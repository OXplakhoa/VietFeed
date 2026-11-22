<?php

namespace App\StoryLens\Brief;

use App\Models\Story;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

class BriefStore
{
    private const AS_ARRAY = ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']];

    public function queue(string $id, int $version): ?string
    {
        $story = $this->settle($id);
        if (! $story || (int) ($story->version ?? 0) !== $version) {
            return null;
        }
        $brief = $story->brief ?? [];
        $request = (string) Str::uuid7();
        $changes = $this->supersede($brief['candidate_id'] ?? null, 'candidate');

        return $this->write($story, [
            'state' => 'queued', 'story_version' => $version, 'request_id' => $request,
            'provider_attempt' => 1,
            'candidate_id' => null, 'published_id' => $brief['published_id'] ?? null,
            'error_category' => null, 'validation_reason' => null,
        ], $changes, ['$or' => [['brief.story_version' => ['$ne' => $version]], ['$expr' => $this->leaseExpired()]]])
            ? $request : null;
    }

    public function dispatchFailed(string $id, int $version, ?string $request, int $providerAttempt = 1): void
    {
        $story = $this->settle($id);
        if (! $story || (int) ($story->version ?? 0) !== $version) {
            return;
        }
        $this->write($story, array_replace($story->brief ?? [], ['state' => 'failed', 'error_category' => 'queue_unavailable']),
            guard: [
                'brief.state' => 'queued', 'brief.story_version' => $version, 'brief.request_id' => $request,
                'brief.provider_attempt' => $this->attemptFilter($providerAttempt),
            ]);
    }

    public function claim(string $id, int $version, ?string $request = null, int $providerAttempt = 1): ?Story
    {
        $story = $this->settle($id);
        if (! $story || (int) ($story->version ?? 0) !== $version || ($story->brief['story_version'] ?? null) !== $version) {
            return null;
        }

        $guard = [
            'brief.state' => ['$in' => ['queued', 'generating']],
            'brief.provider_attempt' => $this->attemptFilter($providerAttempt),
            '$expr' => $this->leaseExpired(),
        ];
        if ($request !== null || $providerAttempt > 1) {
            $guard['brief.request_id'] = $request;
        }

        return $this->write($story, array_replace($story->brief, [
            'state' => 'generating', 'lease_request_id' => $request, 'provider_attempt' => $providerAttempt,
        ]), guard: $guard, lease: true);
    }

    public function workerFailed(string $id, int $version, ?string $request, bool $firstAttempt, int $providerAttempt = 1): bool
    {
        $story = $this->settle($id);
        $brief = $story?->brief ?? [];
        if (! $story || (int) ($story->version ?? 0) !== $version || ($brief['story_version'] ?? null) !== $version) {
            return false;
        }
        $guard = [
            'brief.state' => ['$in' => ['queued', 'generating']],
            'brief.provider_attempt' => $this->attemptFilter($providerAttempt),
        ];
        if ($request !== null || $providerAttempt > 1) {
            $guard['brief.request_id'] = $request;
        }
        $guard['$or'] = [
            ['brief.state' => 'queued', 'brief.request_id' => $request],
            ['brief.state' => 'generating', '$expr' => $this->leaseExpired()],
        ];
        if ($request !== null && $firstAttempt) {
            $guard['$or'][] = ['brief.lease_request_id' => $request];
        }

        return $this->write($story, array_replace($brief, [
            'state' => 'failed', 'error_category' => 'worker_failed', 'validation_reason' => null,
        ]), guard: $guard) !== null;
    }

    public function finish(Story $claimed, array $output, array $extractive, ?BriefFailure $failure, bool $retry = false): bool
    {
        $brief = $claimed->brief;
        $version = $brief['story_version'];
        $providerAttempt = $brief['provider_attempt'];
        $id = (string) Str::uuid7();
        $auto = ! $failure && ! config('ai.require_review');
        $brief = array_replace($brief, [
            'state' => $retry ? 'queued' : ($failure ? 'failed' : 'ready'),
            'provider_attempt' => $retry ? 2 : $providerAttempt,
            'candidate_id' => $failure || $auto ? null : $id,
            'error_category' => $failure?->category,
            'validation_reason' => $failure?->reason,
        ]);
        $changes = [];
        if ($extractive !== []) {
            $changes[] = ['extractive' => true, 'insert' => $this->record($claimed->id, $version, (string) Str::uuid7(), [
                'kind' => 'extractive', 'provider' => null, 'model' => null, 'points' => $extractive,
                'evidence_ids' => array_values(array_unique(array_merge(...array_column($extractive, 'evidence_ids')))),
                'prompt_version' => null, 'status' => 'accepted',
            ])];
        }
        if ($failure?->category !== 'disabled') {
            $changes[] = ['insert' => $this->record($claimed->id, $version, $id, $output + [
                'kind' => 'ai', 'prompt_version' => BriefPrompt::VERSION,
                'provider_attempt' => $providerAttempt, 'request_id' => $brief['request_id'] ?? null,
                'status' => $failure ? 'rejected' : ($auto ? 'accepted' : 'candidate'),
                'error_category' => $failure?->category, 'validation_reason' => $failure?->reason,
            ])];
        }
        if ($auto) {
            $changes = [...$changes, ...$this->supersede($brief['published_id'] ?? null, 'accepted')];
            $brief['published_id'] = $id;
        }

        return $this->write($claimed, $brief, $changes, [
            'brief.state' => 'generating', '$expr' => ['$not' => [$this->leaseExpired()]],
        ]) !== null;
    }

    public function review(string $id, int $version, string $candidate, string $reviewer, bool $accept, ?string $note): bool
    {
        $story = $this->settle($id);
        $brief = $story?->brief ?? [];
        if (! $story || (int) ($story->version ?? 0) !== $version
            || ($brief['story_version'] ?? null) !== $version || ($brief['candidate_id'] ?? null) !== $candidate
            || $this->output($story, $candidate, 'candidate') === null) {
            return false;
        }
        $status = $accept ? 'accepted' : 'rejected';
        $changes = [[
            'id' => $candidate, 'from' => 'candidate',
            'set' => ['status' => $status, 'review_decision' => $status, 'reviewed_by' => $reviewer,
                'reviewed_at' => new UTCDateTime, 'review_note' => $note],
        ]];
        $brief['candidate_id'] = null;
        if ($accept) {
            $changes = [...$changes, ...$this->supersede($brief['published_id'] ?? null, 'accepted')];
            $brief['published_id'] = $candidate;
        }

        return $this->write($story, $brief, $changes) !== null;
    }

    public function output(Story $story, ?string $id, string $status): ?array
    {
        if ($id === null) {
            return null;
        }

        return $this->outputs()->findOne([
            '_id' => $id, 'story_id' => $story->id, 'story_version' => (int) ($story->version ?? 0),
            'kind' => 'ai', 'status' => $status, 'error_category' => null,
        ], self::AS_ARRAY);
    }

    public function acceptedOutputs(array $ids): iterable
    {
        if ($ids === []) {
            return [];
        }

        return $this->outputs()->find(
            ['_id' => ['$in' => $ids], 'kind' => 'ai', 'status' => 'accepted', 'error_category' => null],
            self::AS_ARRAY + ['projection' => ['story_id' => 1, 'story_version' => 1, 'points' => 1, 'reviewed_by' => 1]],
        );
    }

    public function history(Story $story, ?int $limit = null, ?string $kind = null): iterable
    {
        $filter = ['story_id' => $story->id];
        if ($kind !== null) {
            $filter['kind'] = $kind;
        }
        $options = self::AS_ARRAY + ['sort' => ['_id' => -1]];
        if ($limit !== null) {
            $options['limit'] = $limit;
        }

        return $this->outputs()->find($filter, $options);
    }

    public function settle(string $id): ?Story
    {
        $document = $this->stories()->findOne(['_id' => $id], self::AS_ARRAY);
        $changes = $document['brief']['history_updates'] ?? null;
        if ($changes === null) {
            return $document === null ? null : (new Story)->newFromBuilder($document);
        }

        // A Story CAS commits the transition before cross-document writes. Replaying the
        // pending changes is safe after interruption and cannot roll back later reviews.
        foreach ($changes as $change) {
            if (isset($change['insert'])) {
                $output = $change['insert'];
                $filter = isset($change['extractive'])
                    ? array_intersect_key($output, array_flip(['story_id', 'story_version', 'kind']))
                    : ['_id' => $output['_id']];
                try {
                    $this->outputs()->updateOne($filter, ['$setOnInsert' => $output], ['upsert' => true]);
                } catch (BulkWriteException $e) {
                    if ($e->getCode() !== 11000 || $this->outputs()->findOne($filter, ['projection' => ['_id' => 1]]) === null) {
                        throw $e;
                    }
                }

                continue;
            }
            $this->outputs()->updateOne(['_id' => $change['id'], 'status' => $change['from']],
                ['$set' => $change['set'] + ['updated_at' => new UTCDateTime]]);
        }
        $this->stories()->updateOne(['_id' => $id, 'brief.revision' => $document['brief']['revision']],
            ['$unset' => ['brief.history_updates' => true]]);

        return Story::find($id);
    }

    private function write(Story $story, array $brief, array $changes = [], array $guard = [], bool $lease = false): ?Story
    {
        $filter = ['_id' => $story->id, 'version' => $story->version, 'brief.revision' => $story->brief['revision'] ?? null,
            'brief.history_updates' => ['$exists' => false]] + $guard;
        unset($brief['lease_until'], $brief['updated_at'], $brief['history_updates']);
        $brief['revision'] = (string) Str::uuid7();
        if ($changes !== []) {
            $brief['history_updates'] = $changes;
        }
        // Treat the evidence text as literal data, including values beginning with '$'.
        $fields = array_map(fn ($value) => ['$literal' => $value], $brief);
        $fields['updated_at'] = '$$NOW';
        if ($lease) {
            $fields['lease_until'] = ['$dateAdd' => [
                'startDate' => '$$NOW', 'unit' => 'second', 'amount' => (int) config('ai.timeout_seconds') + 20,
            ]];
        } else {
            unset($fields['lease_request_id']);
        }
        $result = $this->stories()->findOneAndUpdate($filter, [
            ['$replaceWith' => ['$mergeObjects' => ['$$ROOT', ['brief' => $fields]]]],
        ],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER] + self::AS_ARRAY);
        if ($result === null) {
            return null;
        }
        if ($changes !== []) {
            $this->settle($story->id);
        }

        return $story->newFromBuilder($result);
    }

    private function leaseExpired(): array
    {
        return ['$lte' => [['$ifNull' => ['$brief.lease_until', new UTCDateTime(0)]], '$$NOW']];
    }

    private function attemptFilter(int $attempt): int|array
    {
        // Story briefs queued before provider retry was added have no attempt marker.
        return $attempt === 1 ? ['$in' => [1, null]] : $attempt;
    }

    private function record(string $storyId, int $version, string $id, array $fields): array
    {
        $now = new UTCDateTime;

        return $fields + [
            '_id' => $id, 'story_id' => $storyId, 'story_version' => $version,
            'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function supersede(?string $id, string $from): array
    {
        return $id === null ? [] : [['id' => $id, 'from' => $from, 'set' => ['status' => 'superseded']]];
    }

    private function stories(): Collection
    {
        return DB::connection('mongodb')->getCollection('stories');
    }

    private function outputs(): Collection
    {
        return DB::connection('mongodb')->getCollection('story_briefs');
    }
}
