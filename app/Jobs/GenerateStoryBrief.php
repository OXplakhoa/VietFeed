<?php

namespace App\Jobs;

use App\StoryLens\Brief\BriefEvidence;
use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefModel;
use App\StoryLens\Brief\BriefStore;
use App\StoryLens\Brief\BriefValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

final class GenerateStoryBrief implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public ?string $requestId = null;

    public int $providerAttempt = 1;

    public function __construct(public string $storyId, public int $storyVersion, ?string $requestId = null, int $providerAttempt = 1)
    {
        $this->timeout = (int) config('ai.timeout_seconds') + 10;
        $this->requestId = $requestId;
        $this->providerAttempt = $providerAttempt;
        $this->onConnection('redis');
    }

    public function handle(BriefStore $store, BriefModel $model, BriefValidator $validator): void
    {
        if (app()->environment('testing') && DB::connection('mongodb')->getDatabaseName() !== 'vietfeed_test') {
            throw new \RuntimeException('Brief test generation requires vietfeed_test');
        }
        $story = $store->claim($this->storyId, $this->storyVersion, $this->requestId, $this->providerAttempt);
        if ($story === null) {
            return;
        }
        $evidence = BriefEvidence::read($story);
        $sent = BriefEvidence::forPrompt($evidence, (int) config('ai.max_evidence'));
        $ids = array_column($sent, 'id');
        $points = [];
        $failure = null;
        try {
            $validated = $validator->validate($model->generate($story->title, $sent), $ids);
            foreach (array_column($validated, 'text') as $text) {
                foreach (['ai.gemini.key', 'ai.openai.key'] as $setting) {
                    $key = (string) config($setting);
                    if ($key !== '' && str_contains(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $key)) {
                        throw new BriefFailure('invalid_output', 'sensitive_output');
                    }
                }
            }
            $points = $validated;
        } catch (BriefFailure $e) {
            $failure = $e;
        }
        $retry = $this->providerAttempt === 1 && $failure?->isRetryable();
        $committed = $store->finish($story, [
            'provider' => $model->provider(), 'model' => $model->model(),
            'points' => $points, 'evidence_ids' => $ids,
        ], BriefEvidence::extract($evidence), $failure, $retry);
        if (! $committed) {
            return;
        }
        if ($failure && $failure->category !== 'disabled') {
            Log::warning('Story brief generation failed', [
                'story_id' => $this->storyId, 'story_version' => $this->storyVersion,
                'category' => $failure->category, 'reason' => $failure->reason,
            ]);
        }
        if ($retry) {
            $this->retry($store, $story->brief['request_id'] ?? null);
        }
    }

    public function failed(?Throwable $error): void
    {
        if (app()->environment('testing') && DB::connection('mongodb')->getDatabaseName() !== 'vietfeed_test') {
            return;
        }
        try {
            $marked = app(BriefStore::class)->workerFailed(
                $this->storyId, $this->storyVersion, $this->requestId, $this->attempts() === 1, $this->providerAttempt,
            );
        } catch (Throwable $e) {
            Log::warning('Could not record Story brief worker failure', ['exception_type' => $e::class]);

            return;
        }
        if ($marked) {
            Log::warning('Story brief worker failed', [
                'story_id' => $this->storyId, 'story_version' => $this->storyVersion,
                'exception_type' => $error === null ? null : $error::class,
            ]);
        }
    }

    private function retry(BriefStore $store, ?string $requestId): void
    {
        $job = new self($this->storyId, $this->storyVersion, $requestId, providerAttempt: 2);
        if ($this->job instanceof SyncJob) {
            Sleep::sleep((int) config('ai.sync_retry_delay_seconds'));
            Bus::dispatchSync($job);

            return;
        }
        try {
            Bus::dispatch($job->delay((int) config('ai.retry_delay_seconds')));
        } catch (Throwable $e) {
            try {
                $store->dispatchFailed($this->storyId, $this->storyVersion, $requestId, providerAttempt: 2);
            } catch (Throwable $recordError) {
                // REVIEW-4 R4-MINOR-1: both stores failing must not surface; admin "Tạo lại" still recovers.
                Log::warning('Could not record Story brief retry dispatch failure', ['exception_type' => $recordError::class]);
            }
            Log::warning('Story brief retry dispatch failed', [
                'story_id' => $this->storyId, 'story_version' => $this->storyVersion, 'exception_type' => $e::class,
            ]);
        }
    }
}
