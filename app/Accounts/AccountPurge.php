<?php

namespace App\Accounts;

use App\ApiTokens\ApiTokenService;
use App\Authorization\AuthorizationAudit;
use App\Billing\StripeClient;
use App\Interaction\InteractionActivity;
use App\Interaction\Trending;
use App\Models\User;
use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\StoryProcessing\CassandraClient;
use App\StoryProcessing\Neo4jClient;
use ArrayObject;
use Carbon\CarbonImmutable;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Purge worker (`accounts:purge`). A workflow is claimed by a `$$NOW` lease (user document pending -> purging first, so
 * recovery is refused from then on), then idempotent steps run in order and each checkpoints `steps.<name>`. A crash
 * leaves the lease to expire and the next run resumes at the first unfinished step; every step is safe to repeat.
 */
final class AccountPurge
{
    public const LEASE_MS = 600_000;

    public const ANONYMOUS = 'deleted';

    /** Ordered purge steps. Cassandra anonymization MUST precede the partition deletes that locate its keys. */
    public const STEPS = [
        'stripe_cancel_final', 'bookmarks', 'boosts', 'article_unlocks', 'comments', 'redis',
        'cassandra_anonymize', 'cassandra_delete', 'neo4j', 'stripe_customer', 'audit_outbox', 'user_document', 'subscriptions',
    ];

    private const MAX_MONTHS = 1200;

    private ?string $token = null;

    public function __construct(
        private AccountLifecycle $lifecycle,
        private ApiTokenService $tokens,
        private ReadingPassQuota $quota,
        private Trending $trending,
        private CassandraClient $cassandra,
        private Neo4jClient $neo4j,
        private AuthorizationAudit $audit,
    ) {}

    /**
     * @return array<string, int> counters only (no identifiers or PII)
     */
    public function run(int $limit = 100, bool $dryRun = false): array
    {
        $counts = ['stripe_retried' => 0, 'orphans_removed' => 0, 'due' => 0, 'purged' => 0, 'failed' => 0, 'skipped' => 0];
        $steps = array_fill_keys(self::STEPS, 0);
        $this->maintain($limit, $dryRun, $counts);

        $due = $this->lifecycle->workflows()->find([
            'state' => ['$in' => [AccountLifecycle::PENDING, AccountLifecycle::PURGING]],
            '$expr' => ['$and' => [
                ['$lte' => ['$purge_after', '$$NOW']],
                ['$lte' => [['$ifNull' => ['$lease_until', new UTCDateTime(0)]], '$$NOW']],
            ]],
        ], ['sort' => ['purge_after' => 1], 'limit' => $limit, 'projection' => ['_id' => 1]]);
        foreach ($due as $row) {
            $counts['due']++;
            $id = (string) $row['_id'];
            if ($dryRun) {
                foreach ($this->plan($id) as $step => $n) {
                    $steps[$step] += $n;
                }

                continue;
            }
            try {
                $outcome = $this->purge($id);
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::warning('Account purge step failed; the next run resumes it', ['exception_type' => $e::class]);

                continue;
            }
            $counts[$outcome]++;
        }

        return $counts + ['steps' => $steps];
    }

    /** Retry the Stripe cancellation of pending accounts and drop workflows whose user write never landed. */
    private function maintain(int $limit, bool $dryRun, array &$counts): void
    {
        $workflows = $this->lifecycle->workflows();
        $pending = $workflows->find(['state' => AccountLifecycle::PENDING, 'steps.'.AccountLifecycle::STRIPE_STEP => ['$exists' => false]], ['limit' => $limit, 'sort' => ['requested_at' => 1]]);
        foreach ($pending as $workflow) {
            $id = (string) $workflow['_id'];
            $user = $this->users()->findOne(['_id' => AccountLifecycle::userKey($id)], ['projection' => ['deletion.state' => 1]]);
            // Only a missing user or a missing `deletion` makes the workflow an orphan: a `purging` user must still be purged.
            if ($user === null || ($user['deletion'] ?? null) === null) {
                $old = AccountLifecycle::carbon($workflow['requested_at'] ?? null)->lt(CarbonImmutable::now('UTC')->subMinutes(AccountLifecycle::ORPHAN_AFTER_MINUTES));
                if ($old && ! $dryRun) {
                    $workflows->deleteOne(['_id' => $id, 'state' => AccountLifecycle::PENDING]);
                }
                $counts['orphans_removed'] += $old ? 1 : 0;

                continue;
            }
            $counts['stripe_retried']++;
            if ($dryRun) {
                continue;
            }
            try {
                $this->lifecycle->cancelSubscriptions($id);
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::warning('Account deletion Stripe cancel failed; retried next run', ['exception_type' => $e::class]);
            }
        }
    }

    /** @return 'purged'|'skipped' */
    private function purge(string $id): string
    {
        $claim = $this->claim($id);
        if ($claim === null) {
            return 'skipped';
        }
        [$token, $workflow] = $claim;
        $this->token = $token;
        $done = self::arr($workflow['steps'] ?? []);
        $context = self::arr($workflow['context'] ?? []);
        try {
            foreach (self::STEPS as $step) {
                if (array_key_exists($step, $done)) {
                    continue;
                }
                $this->{'step'.str_replace('_', '', ucwords($step, '_'))}($id, $context);
                $this->checkpoint($id, $token, $step);
            }
            $this->finish($id, $token);
        } catch (Throwable $e) {
            $this->lifecycle->workflows()->updateOne(['_id' => $id, 'claim' => $token], [
                '$set' => ['last_error_at' => new UTCDateTime, 'last_error' => $e::class], '$inc' => ['attempts' => 1],
                '$unset' => ['claim' => '', 'lease_until' => ''],
            ]);

            throw $e;
        }

        return 'purged';
    }

    /** @return array{0: string, 1: array}|null lease token and the claimed workflow, or null when not purgeable now */
    private function claim(string $id): ?array
    {
        $workflows = $this->lifecycle->workflows();
        $workflow = $workflows->findOne(['_id' => $id]);
        if ($workflow === null || ! in_array($workflow['state'], [AccountLifecycle::PENDING, AccountLifecycle::PURGING], true)) {
            return null;
        }
        $user = $this->users()->findOne(['_id' => AccountLifecycle::userKey($id)]);
        if ($user !== null || $workflow['state'] === AccountLifecycle::PENDING) {
            $state = $user['deletion']['state'] ?? null;
            if ($user === null || $state === null) {
                // Recovered (crash before the workflow was removed) or the user write never landed.
                $workflows->deleteOne(['_id' => $id, 'state' => AccountLifecycle::PENDING]);

                return null;
            }
            if (($user['role'] ?? 'user') !== 'user') {
                Log::warning('Account purge refused: the pending account is staff', ['workflow_id' => $id]);

                return null;
            }
            if ($state === AccountLifecycle::PENDING) {
                // The point of no return: from here recovery is refused. A crash before the lease only repeats this.
                $this->users()->updateOne(
                    ['_id' => AccountLifecycle::userKey($id), 'deletion.state' => AccountLifecycle::PENDING, '$expr' => ['$lte' => ['$deletion.purge_after', '$$NOW']]],
                    ['$set' => ['deletion.state' => AccountLifecycle::PURGING]],
                );
                $user = $this->users()->findOne(['_id' => AccountLifecycle::userKey($id)]);
                if (($user['deletion']['state'] ?? null) !== AccountLifecycle::PURGING) {
                    return null;
                }
            }
        }

        $token = bin2hex(random_bytes(16));
        $claimed = $workflows->findOneAndUpdate([
            '_id' => $id, 'state' => ['$in' => [AccountLifecycle::PENDING, AccountLifecycle::PURGING]],
            '$expr' => ['$lte' => [['$ifNull' => ['$lease_until', new UTCDateTime(0)]], '$$NOW']],
        ], [['$set' => ['state' => AccountLifecycle::PURGING, 'claim' => $token, 'lease_until' => ['$add' => ['$$NOW', self::LEASE_MS]]]]], [
            'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ]);
        if ($claimed === null) {
            return null;
        }
        if ($user !== null && ! isset($claimed['context'])) {
            // What later steps need after the user document is gone. Cleared when the workflow is marked purged.
            $created = $user['created_at'] ?? null;
            $workflows->updateOne(['_id' => $id, 'claim' => $token], ['$set' => ['context' => [
                'first_month' => ($created === null ? CarbonImmutable::parse('2015-01-01', 'UTC') : AccountLifecycle::carbon($created))->format('Y-m'),
                'stripe_customer_id' => $user['stripe_customer_id'] ?? $user['stripe_id'] ?? null,
            ]]]);
            $claimed = $workflows->findOne(['_id' => $id]);
        }

        return [$token, self::arr($claimed)];
    }

    private function checkpoint(string $id, string $token, string $step): void
    {
        $result = $this->lifecycle->workflows()->updateOne(
            ['_id' => $id, 'claim' => $token, '$expr' => ['$gt' => ['$lease_until', '$$NOW']]],
            [['$set' => ['steps.'.$step => '$$NOW', 'lease_until' => ['$add' => ['$$NOW', self::LEASE_MS]]]]],
        );
        if ($result->getMatchedCount() !== 1) {
            throw new \RuntimeException('Account purge lease lost');
        }
    }

    /** Long loops keep the lease alive; the same conditional update as a checkpoint, so a lost lease aborts the step. */
    private function renewLease(string $id): void
    {
        $result = $this->lifecycle->workflows()->updateOne(
            ['_id' => $id, 'claim' => $this->token, '$expr' => ['$gt' => ['$lease_until', '$$NOW']]],
            [['$set' => ['lease_until' => ['$add' => ['$$NOW', self::LEASE_MS]]]]],
        );
        if ($result->getMatchedCount() !== 1) {
            throw new \RuntimeException('Account purge lease lost');
        }
    }

    private function finish(string $id, string $token): void
    {
        $result = $this->lifecycle->workflows()->updateOne(['_id' => $id, 'claim' => $token], [
            '$set' => ['state' => AccountLifecycle::PURGED, 'purged_at' => new UTCDateTime],
            '$unset' => ['purge_after' => '', 'origin' => '', 'requested_by' => '', 'claim' => '', 'lease_until' => '', 'context' => '',
                'stripe_canceled' => '', 'attempts' => '', 'last_error_at' => '', 'last_error' => ''],
        ]);
        if ($result->getMatchedCount() !== 1) {
            throw new \RuntimeException('Account purge lease lost');
        }
    }

    // ── Steps (each idempotent) ────────────────────────────────────────────────────────────────────────────────

    private function stepStripeCancelFinal(string $id, array $context): void
    {
        // Catches a subscription projected after the request (e.g. a Checkout that completed late).
        $this->lifecycle->cancelSubscriptions($id);
    }

    private function stepBookmarks(string $id, array $context): void
    {
        $this->mongo('bookmarks')->deleteMany(['user_id' => $id]);
    }

    private function stepBoosts(string $id, array $context): void
    {
        $boosts = $this->mongo('boosts');
        foreach ($boosts->find(['user_id' => $id], ['projection' => ['_id' => 1, 'article_id' => 1]]) as $boost) {
            // Redis trending is a projection of the boosts: subtract this boost, then drop it. Retract is a no-op on repeat.
            $this->trending->retract((string) $boost['_id'], (string) $boost['article_id']);
            $boosts->deleteOne(['_id' => $boost['_id']]);
        }
    }

    private function stepArticleUnlocks(string $id, array $context): void
    {
        $this->mongo('article_unlocks')->deleteMany(['$or' => [['user_id' => $id], ['subject' => 'u:'.$id]]]);
    }

    private function stepComments(string $id, array $context): void
    {
        // Never Comment::delete(): its hook cascades to other users' replies. Anonymize in place instead.
        $this->mongo('comments')->updateMany(['user_id' => $id], ['$set' => ['user_id' => null, 'body' => '', 'account_deleted' => true, 'updated_at' => new UTCDateTime]]);
    }

    private function stepRedis(string $id, array $context): void
    {
        $this->tokens->cleanupForUser($id);
        $this->quota->forget('u:'.$id);
    }

    private function stepCassandraAnonymize(string $id, array $context): void
    {
        $keyspace = $this->cassandra->keyspace();
        foreach ($this->months($context) as $month) {
            $this->renewLease($id);
            foreach ($this->activityRows($id, $month) as $row) {
                $time = self::time($row['occurred_at']);
                $key = [(string) $row['article_id'], $time->format('Y-m-d'), new Timestamp($time), new Uuid((string) $row['event_id'])];
                $where = 'WHERE article_id = ? AND activity_day = ? AND occurred_at = ? AND event_id = ?';
                $current = $this->cassandra->rows("SELECT user_id FROM $keyspace.".InteractionActivity::BY_ARTICLE." $where", $key);
                // UPDATE would upsert a partial row, so only rewrite a row that exists and still names this user.
                if ($current !== [] && ($current[0]['user_id'] ?? null) === $id) {
                    $this->cassandra->execute("UPDATE $keyspace.".InteractionActivity::BY_ARTICLE." SET user_id = ? $where", [self::ANONYMOUS, ...$key])->asVoidResult();
                }
            }
        }
    }

    private function stepCassandraDelete(string $id, array $context): void
    {
        $keyspace = $this->cassandra->keyspace();
        foreach ($this->months($context) as $month) {
            $this->renewLease($id);
            foreach ([InteractionActivity::BY_USER => 'user_id', ReadingPassHistory::BY_USER => 'user_id'] as $table => $column) {
                $this->cassandra->execute("DELETE FROM $keyspace.$table WHERE $column = ? AND year_month = ?", [$id, $month])->asVoidResult();
            }
        }
    }

    private function stepNeo4j(string $id, array $context): void
    {
        // No User nodes are written today; kept so a future graph projection is purged without another gate.
        $this->neo4j->run('MATCH (u:User {uuid: $id}) DETACH DELETE u', ['id' => $id]);
    }

    private function stepStripeCustomer(string $id, array $context): void
    {
        // The id captured at claim, plus any customer only a late webhook projected onto the user's subscriptions.
        $customers = array_filter(array_unique([
            $context['stripe_customer_id'] ?? null,
            ...$this->mongo('subscriptions')->distinct('stripe_customer_id', ['user_id' => $id]),
        ]), fn ($customer) => is_string($customer) && $customer !== '');
        foreach ($customers as $customer) {
            try {
                // The Customer stays for invoice history; an empty string unsets the field at Stripe.
                app(StripeClient::class)->updateCustomer($customer, ['email' => '', 'name' => '', 'metadata' => ['user_id' => '']]);
            } catch (InvalidRequestException $e) {
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
            }
        }
    }

    private function stepAuditOutbox(string $id, array $context): void
    {
        // Keep undelivered authorization history after the user document is removed (same rule as the old hard delete).
        $user = User::find($id);
        if ($user !== null) {
            $this->audit->transferPending($user);
        }
    }

    private function stepUserDocument(string $id, array $context): void
    {
        $this->users()->deleteOne(['_id' => AccountLifecycle::userKey($id), 'deletion.state' => AccountLifecycle::PURGING]);
        if ($this->users()->countDocuments(['_id' => AccountLifecycle::userKey($id)], ['limit' => 1]) !== 0) {
            throw new \RuntimeException('User document still present after the delete step');
        }
        $this->tokens->cleanupForUser($id);
    }

    private function stepSubscriptions(string $id, array $context): void
    {
        // After the user document is gone, so a webhook that projected meanwhile is still covered. Rows are kept
        // for billing reconciliation under a pseudonym; BillingGateway::apply() keeps updating them by subscription id.
        $this->mongo('subscriptions')->updateMany(['user_id' => $id], ['$set' => ['user_id' => AccountLifecycle::pseudonym($id), 'account_purged' => true]]);
    }

    // ── Dry run ────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, int> items each step would touch; Cassandra is counted in rows when reachable */
    private function plan(string $id): array
    {
        $workflow = self::arr($this->lifecycle->workflows()->findOne(['_id' => $id]) ?? []);
        $done = self::arr($workflow['steps'] ?? []);
        $user = $this->users()->findOne(['_id' => AccountLifecycle::userKey($id)]);
        $context = self::arr($workflow['context'] ?? []);
        if ($context === [] && $user !== null) {
            $created = $user['created_at'] ?? null;
            $context = ['first_month' => ($created === null ? CarbonImmutable::parse('2015-01-01', 'UTC') : AccountLifecycle::carbon($created))->format('Y-m')];
        }
        $plan = array_fill_keys(self::STEPS, 0);
        if ($user !== null) {
            $plan['stripe_cancel_final'] = $this->mongo('subscriptions')->countDocuments(['user_id' => $id, 'status' => ['$in' => AccountLifecycle::BILLABLE]]);
            $plan['bookmarks'] = $this->mongo('bookmarks')->countDocuments(['user_id' => $id]);
            $plan['boosts'] = $this->mongo('boosts')->countDocuments(['user_id' => $id]);
            $plan['article_unlocks'] = $this->mongo('article_unlocks')->countDocuments(['$or' => [['user_id' => $id], ['subject' => 'u:'.$id]]]);
            $plan['comments'] = $this->mongo('comments')->countDocuments(['user_id' => $id]);
            $plan['user_document'] = 1;
            $plan['neo4j'] = 1;
            $plan['redis'] = 1;
            $plan['audit_outbox'] = 1;
            $plan['stripe_customer'] = (int) (($user['stripe_customer_id'] ?? null) !== null);
            $plan['subscriptions'] = $this->mongo('subscriptions')->countDocuments(['user_id' => $id]);
            try {
                $rows = 0;
                foreach ($this->months($context) as $month) {
                    $rows += count($this->activityRows($id, $month));
                }
                $plan['cassandra_anonymize'] = $rows;
                $plan['cassandra_delete'] = count($this->months($context));
            } catch (Throwable) {
                $plan['cassandra_anonymize'] = $plan['cassandra_delete'] = 0;
            }
        }

        return array_diff_key($plan, $done);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────────────────────

    private function activityRows(string $id, string $month): array
    {
        $keyspace = $this->cassandra->keyspace();

        return $this->cassandra->rows("SELECT article_id, occurred_at, event_id FROM $keyspace.".InteractionActivity::BY_USER.' WHERE user_id = ? AND year_month = ?', [$id, $month]);
    }

    /** @return list<string> UTC `Y-m` buckets from the account's first month to now */
    private function months(array $context): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', (string) ($context['first_month'] ?? '2015-01'), 'UTC') ?: CarbonImmutable::parse('2015-01-01', 'UTC');
        $last = CarbonImmutable::now('UTC')->startOfMonth();
        $months = [];
        for ($month = $first->startOfMonth(); $month <= $last && count($months) < self::MAX_MONTHS; $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    private static function time(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->utc() : CarbonImmutable::parse((string) $value, 'UTC');
    }

    private static function arr(mixed $value): array
    {
        if ($value instanceof ArrayObject) {
            return array_map(fn ($v) => $v instanceof ArrayObject ? self::arr($v) : $v, $value->getArrayCopy());
        }

        return (array) $value;
    }

    private function mongo(string $name): Collection
    {
        return DB::connection('mongodb')->getCollection($name);
    }

    private function users(): Collection
    {
        return $this->mongo('users');
    }
}
