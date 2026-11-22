<?php

namespace App\StoryLens\Brief;

use App\Jobs\GenerateStoryBrief;
use App\Models\Story;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use MongoDB\BSON\UTCDateTime;
use Throwable;
use UnexpectedValueException;

final class Briefs
{
    public const RECENT_LIMIT = 50;

    public function __construct(private BriefStore $store) {}

    public function queue(string $id, int $version, bool $sync = false, int $position = 0): bool
    {
        $request = null;
        try {
            if (app()->environment('testing') && ! $sync
                && Redis::connection(config('queue.connections.redis.connection'))->client()->getDbNum() !== 15) {
                throw new \RuntimeException('Brief test queue requires Redis DB15');
            }
            $request = $this->store->queue($id, $version);
            if ($request === null) {
                return false;
            }
            $job = new GenerateStoryBrief($id, $version, $request);
            if ($sync) {
                Bus::dispatchSync($job);
            } else {
                Bus::dispatch($job->delay($position * (int) config('ai.dispatch_spacing_seconds')));
            }

            return true;
        } catch (Throwable $e) {
            if ($request !== null) {
                try {
                    $this->store->dispatchFailed($id, $version, $request);
                } catch (Throwable) {
                    // A subsequent explicit retry can recover the queued state.
                }
            }
            Log::warning('Story brief dispatch failed', ['story_id' => $id, 'story_version' => $version, 'exception_type' => $e::class]);

            return false;
        }
    }

    private function published(Story $story, Closure $lookup): ?array
    {
        $id = $story->brief['published_id'] ?? null;
        if ($id !== null && ! is_string($id)) {
            throw new UnexpectedValueException('Invalid brief publication ID');
        }
        $output = $id === null ? null : $lookup($id);
        if ($output === null || ($output['story_id'] ?? null) !== $story->id
            || ($output['story_version'] ?? null) !== (int) ($story->version ?? 0) || ($output['_id'] ?? null) !== $id) {
            return null;
        }
        $reviewer = $output['reviewed_by'] ?? null;
        if (! array_key_exists('reviewed_by', $output) || ($reviewer !== null && (! is_string($reviewer) || $reviewer === ''))) {
            throw new UnexpectedValueException('Invalid brief reviewer');
        }

        return ! config('ai.require_review') || $reviewer !== null ? $output : null;
    }

    public function presentStories(array $stories): array
    {
        $evidence = BriefEvidence::readForStories($stories);
        $ids = [];
        foreach ($stories as $story) {
            $brief = $story->brief;
            $id = is_array($brief) ? ($brief['published_id'] ?? null) : null;
            if (is_string($id)) {
                $ids[$id] = true;
            }
        }
        try {
            $outputs = [];
            foreach ($this->store->acceptedOutputs(array_keys($ids)) as $output) {
                $outputs[$output['_id']] = $output;
            }
            $lookup = fn (string $id) => $outputs[$id] ?? null;
        } catch (Throwable $e) {
            $lookup = fn () => throw $e;
        }
        $briefs = [];
        foreach ($stories as $story) {
            $briefs[$story->id] = $this->presentWithLookup($story, $evidence[$story->id], $lookup);
        }

        return $briefs;
    }

    public function present(Story $story, array $evidence): array
    {
        return $this->presentWithLookup($story, $evidence, fn (string $id) => $this->store->output($story, $id, 'accepted'));
    }

    private function presentWithLookup(Story $story, array $evidence, Closure $lookup): array
    {
        try {
            $state = self::state($story);
            $published = $state === 'unavailable' ? null : $this->published($story, $lookup);
            if ($published !== null) {
                $points = self::cite($published['points'] ?? [], $evidence);
                if (! $points) {
                    throw new UnexpectedValueException('Invalid published brief points');
                }

                return ['kind' => 'ai', 'points' => $points, 'state' => $state,
                    'label' => $published['reviewed_by'] === null ? 'Tóm tắt AI đã qua kiểm tra tự động' : 'Tóm tắt AI, đã được biên tập viên duyệt'];
            }
        } catch (Throwable $e) {
            $state = 'unavailable';
            try {
                Log::debug('Story brief unavailable', ['exception_type' => $e::class]);
            } catch (Throwable) {
                // Logging is optional on the public fallback path.
            }
        }

        try {
            $extractive = self::cite(BriefEvidence::extract($evidence), $evidence) ?? [];
        } catch (Throwable) {
            $extractive = [];
            $state = 'unavailable';
        }

        return $extractive
            ? ['kind' => 'extractive', 'points' => $extractive, 'state' => $state, 'label' => 'Tóm tắt trích từ nguồn']
            : ['kind' => 'none', 'points' => [], 'state' => $state, 'label' => 'Chưa có nội dung tóm tắt.'];
    }

    public static function state(Story $story): string
    {
        $brief = $story->brief;
        if ($brief === null) {
            return 'none';
        }
        if (! is_array($brief) || ! is_int($brief['story_version'] ?? null)
            || ! in_array($brief['state'] ?? null, ['none', 'queued', 'generating', 'ready', 'failed'], true)) {
            return 'unavailable';
        }
        if ($brief['story_version'] !== (int) ($story->version ?? 0)) {
            return 'none';
        }
        if ($brief['state'] === 'generating') {
            $lease = $brief['lease_until'] ?? null;
            if ($lease instanceof UTCDateTime) {
                $lease = $lease->toDateTimeImmutable();
            }

            return $lease instanceof DateTimeInterface && $lease > now() ? 'generating' : 'failed';
        }

        return $brief['state'];
    }

    public static function cite(array $points, array $evidence): ?array
    {
        $titles = array_column($evidence, 'title', 'id');
        $presented = [];
        foreach ($points as $point) {
            if (! is_string($point['text'] ?? null) || ! is_array($point['evidence_ids'] ?? null) || $point['evidence_ids'] === []) {
                return null;
            }
            $references = [];
            foreach ($point['evidence_ids'] as $id) {
                if (! is_string($id) || ! isset($titles[$id])) {
                    return null;
                }
                $references[] = ['id' => $id, 'title' => $titles[$id]];
            }
            $presented[] = ['text' => $point['text'], 'evidence' => $references];
        }

        return $presented;
    }

    public function evaluate(Story $story): array
    {
        $attempts = $invalid = $accepted = $rejected = 0;
        $latest = null;
        foreach ($this->store->history($story, kind: 'ai') as $output) {
            $attempts++;
            $invalid += ($output['error_category'] ?? null) === 'invalid_output' ? 1 : 0;
            $accepted += ($output['review_decision'] ?? null) === 'accepted' ? 1 : 0;
            $rejected += ($output['review_decision'] ?? null) === 'rejected' ? 1 : 0;
            if ($output['story_version'] === (int) ($story->version ?? 0)) {
                $latest ??= $output;
            }
        }
        $points = $latest['points'] ?? [];
        $sent = count($latest['evidence_ids'] ?? []);
        $cited = count(array_unique(array_merge(...array_column($points, 'evidence_ids'))));

        return [
            'story_id' => $story->id, 'story_version' => (int) ($story->version ?? 0),
            'points_count' => count($points), 'evidence_cited' => $cited, 'evidence_sent' => $sent,
            'evidence_coverage' => $sent ? $cited / $sent : 0,
            'attempts' => $attempts, 'invalid_outputs' => $invalid,
            'invalid_output_rate' => $attempts ? $invalid / $attempts : 0,
            'accepted_count' => $accepted, 'rejected_count' => $rejected,
        ];
    }
}
