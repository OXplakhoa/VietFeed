<?php

namespace App\Demo;

use App\Accounts\AccountLifecycle;
use App\Interaction\InteractionToggle;
use App\Interaction\Trending;
use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\User;
use App\Services\ReadingPassService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Ramsey\Uuid\Uuid;
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;
use RuntimeException;

final class SyntheticWorkload
{
    public const COLLECTION = 'synthetic_workloads';

    public function __construct(
        private InteractionToggle $interactions,
        private ReadingPassService $readingPass,
        private Trending $trending,
        private AccountLifecycle $accounts,
    ) {}

    public function manifest(): array
    {
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/synthetic-workload-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($manifest['version'] !== 1 || $manifest['synthetic'] !== true) {
            throw new RuntimeException('Unsupported synthetic workload manifest.');
        }

        return $manifest;
    }

    public function inspect(int $seed, array $manifest): array
    {
        $run = $this->runs()->findOne(['_id' => "v1:$seed"]);
        $ids = $this->articleIds($manifest, $run['article_ids'] ?? null);
        $scores = $run === null ? [] : $this->trending->workloadTop($seed);
        $top = [];
        foreach ($ids as $id) {
            if (isset($scores[$id])) {
                $top[$id] = $scores[$id];
            }
        }
        // The fixture URL order breaks ties even when a fresh ingest assigns different MongoDB IDs.
        arsort($top, SORT_NUMERIC);

        return ['seed' => $seed] + $manifest + [
            'pinned' => $run !== null,
            'article_ids' => $ids,
            'published_events' => count($run['published'] ?? []),
            'top' => $top,
        ];
    }

    public function seed(int $seed, array $manifest): void
    {
        $key = "v1:$seed";
        $result = Cache::lock("demo:synthetic:$key", 300)->get(function () use ($key, $seed, $manifest): void {
            $runs = $this->runs();
            $run = $runs->findOne(['_id' => $key]);
            $ids = $this->articleIds($manifest, $run['article_ids'] ?? null);
            if ($run === null) {
                $run = $runs->findOneAndUpdate(['_id' => $key], ['$setOnInsert' => [
                    'synthetic' => true, 'seed' => $seed, 'article_ids' => $ids, 'published' => [],
                ]], ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
            }
            $articles = Article::whereIn('_id', $ids)->get()->keyBy('id');
            if ($articles->count() !== count($ids)) {
                throw new RuntimeException('A frozen fixture article disappeared during seeding. Restore it with php artisan storylens ingest.');
            }

            $random = new Randomizer(new PcgOneseq128XslRr64($seed));
            $start = CarbonImmutable::parse($manifest['window_utc']['start']);
            $end = CarbonImmutable::parse($manifest['window_utc']['end_exclusive']);
            $stride = intdiv($end->getTimestamp() - $start->getTimestamp(), $manifest['user_count']);
            $published = array_fill_keys($run['published'], true);
            for ($index = 0; $index < $manifest['user_count']; $index++) {
                $user = $this->reader($seed, $index + 1, $start);
                $time = $start->addSeconds($index * $stride + $random->getInt(0, $stride - count($manifest['events_per_user'])));
                foreach ($manifest['events_per_user'] as $offset => $type) {
                    $position = $random->getInt(0, count($ids) - 1);
                    if ($type === 'boost') {
                        $position = min($position, $random->getInt(0, count($ids) - 1));
                    }
                    $article = $articles[$ids[$position]];
                    $at = $time->addSeconds($offset);
                    $eventId = $this->eventId($user->id, $type, $article->id, $at);
                    if (isset($published[$eventId])) {
                        continue;
                    }
                    match ($type) {
                        'unlock' => $this->readingPass->seedSyntheticUnlock($article, $user, $eventId, $at),
                        'bookmark' => $this->interactions->seedSynthetic(Bookmark::class, $user->id, $article->id, $seed, $eventId, $at),
                        'boost' => $this->interactions->seedSynthetic(Boost::class, $user->id, $article->id, $seed, $eventId, $at),
                    };
                    $runs->updateOne(['_id' => $key], ['$addToSet' => ['published' => $eventId]]);
                }
            }
        });
        if ($result === false) {
            throw new RuntimeException('This synthetic seed is already running.');
        }
    }

    private function reader(int $seed, int $number, CarbonImmutable $start): User
    {
        $suffix = sprintf('%d-%02d', $seed, $number);
        $id = 'synthetic-v1-'.$suffix;
        if ($this->accounts->isPurging($id)) {
            throw new RuntimeException('This synthetic reader was purged. Use a new seed.');
        }
        $user = User::unguarded(fn () => User::firstOrCreate(['email' => "synthetic+v1-$suffix@demo.invalid"], fn () => [
            '_id' => $id, 'name' => "Synthetic v1 $suffix", 'password' => Str::random(64),
            'password_reset_requires_verified_email' => true,
            'synthetic' => true, 'synthetic_seed' => $seed, 'created_at' => $start, 'updated_at' => $start,
        ]));
        if ($user->id !== $id || $user->synthetic !== true || $user->synthetic_seed !== $seed || ! $user->isUser() || $user->isPendingDeletion()) {
            throw new RuntimeException('The synthetic reader identity is unavailable.');
        }

        return $user;
    }

    private function eventId(string $userId, string $type, string $articleId, CarbonImmutable $at): string
    {
        // UUIDv7 uses 48 bits of Unix milliseconds, followed by version, variant and deterministic entropy.
        $hash = hash('sha256', "$userId:$type:$articleId");
        $hex = sprintf('%012x', (int) $at->format('Uv')).'7'.substr($hash, 0, 3).'8'.substr($hash, 3, 15);

        return Uuid::fromBytes(hex2bin($hex))->toString();
    }

    private function articleIds(array $manifest, ?array $pinned = null): array
    {
        $fixture = json_decode(file_get_contents(base_path($manifest['article_fixture'])), true, flags: JSON_THROW_ON_ERROR);
        $expected = array_column($fixture['items'], 'story_key', 'link');
        ksort($expected, SORT_STRING);
        $expected = array_slice($expected, 0, $manifest['article_limit'], preserve_keys: true);
        $articles = Article::whereIn('original_url', array_keys($expected))->whereNotNull('story_id')
            ->get(['_id', 'original_url', 'story_key'])->keyBy('original_url');
        $ids = [];
        foreach ($expected as $url => $storyKey) {
            $article = $articles->get($url);
            if ($article?->story_key === $storyKey) {
                $ids[] = $article->id;
            }
        }
        if (count($ids) !== $manifest['article_limit']) {
            throw new RuntimeException('The frozen StoryLens fixture is incomplete. Run php artisan storylens ingest first.');
        }
        if ($pinned !== null && $pinned !== $ids) {
            throw new RuntimeException('The pinned articles no longer match the frozen StoryLens fixture. Use a new seed.');
        }

        return $ids;
    }

    private function runs(): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection(self::COLLECTION, [
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ]);
    }
}
