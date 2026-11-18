<?php

namespace App\ReadingPass;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\BulkWriteException;

/** One-off copy of legacy SQL `article_unlocks` into the MongoDB ledger (the only SQL reader of that table). */
final class ReadingPassSqlImport
{
    public function __construct(private UnlockLedger $ledger, private ReadingPassHistory $history, private ReadingPassQuota $quota) {}

    public function hasImported(): bool
    {
        return $this->ledger->collection()->countDocuments(['legacy_sql_id' => ['$exists' => true]], ['limit' => 1]) > 0;
    }

    /** @return array{inserted: int, existing: int, skipped: int, published: int, publish_failed: int} */
    public function run(bool $emit): array
    {
        $counts = ['inserted' => 0, 'existing' => 0, 'skipped' => 0, 'published' => 0, 'publish_failed' => 0];
        $now = CarbonImmutable::now();
        DB::table('article_unlocks')->orderBy('id')->chunkById(500, function ($rows) use ($emit, $now, &$counts) {
            foreach ($rows as $row) {
                $userId = $row->user_id === null || $row->user_id === '' ? null : (string) $row->user_id;
                $sessionHash = $userId === null && $row->session_id !== null && $row->session_id !== '' ? UnlockLedger::sessionHash((string) $row->session_id) : null;
                if ($userId === null && $sessionHash === null) {
                    $counts['skipped']++; // No subject: cannot be attributed to anyone.

                    continue;
                }
                $at = CarbonImmutable::parse($row->unlocked_at ?? $row->created_at ?? $now, 'UTC');
                $doc = [
                    '_id' => (string) Str::uuid7(),
                    'subject' => $userId !== null ? UnlockLedger::userSubject($userId) : UnlockLedger::GUEST_PREFIX.$sessionHash,
                    'user_id' => $userId,
                    'session_hash' => $sessionHash,
                    'article_id' => (string) $row->article_id,
                    'state' => UnlockLedger::GRANTED,
                    'unlocked_at' => UnlockLedger::date($at),
                    'tier' => null,
                    'origin' => 'import',
                    'legacy_sql_id' => (int) $row->id,
                    'created_at' => UnlockLedger::date($now),
                    'updated_at' => UnlockLedger::date($now),
                ];
                try {
                    // Natural key (subject, article): an existing ledger document is never overwritten.
                    $this->ledger->collection()->insertOne($doc);
                } catch (BulkWriteException $e) {
                    if (! UnlockLedger::isDuplicate($e)) {
                        throw $e;
                    }
                    $counts['existing']++;

                    continue;
                }
                $counts['inserted']++;
                if ($emit) {
                    $counts[$this->history->publish($doc) ? 'published' : 'publish_failed']++;
                }
            }
        });
        // Every quota window rebuilds lazily from the ledger, imported rows included.
        $this->quota->invalidateAll();

        return $counts;
    }
}
