<?php

namespace Tests\Feature;

use App\Content\ContentCatalog;
use App\Demo\SyntheticWorkload;
use App\Interaction\InteractionActivity;
use App\Interaction\InteractionStream;
use App\Interaction\MongoInteractionReplaySource;
use App\Interaction\Trending;
use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\Story;
use App\Models\User;
use App\ReadingPass\ReadingPassHistory;
use App\StoryProcessing\CassandraClient;
use App\Support\MongoCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SyntheticWorkloadTest extends TestCase
{
    public function test_workload_repeats_across_clean_stores_and_reseeding_keeps_one_copy(): void
    {
        $manifest = app(SyntheticWorkload::class)->manifest();
        $fixture = json_decode(file_get_contents(base_path($manifest['article_fixture'])), true, flags: JSON_THROW_ON_ERROR);
        $environment = config('app.env');
        try {
            config(['app.env' => 'production']);
            $this->artisan('demo:synthetic', ['action' => 'seed'])
                ->expectsOutputToContain('disabled in production')->assertFailed();
        } finally {
            config(['app.env' => $environment]);
        }
        app(InteractionActivity::class)->bootstrap();
        app(ReadingPassHistory::class)->bootstrap();

        $first = $this->seedFromCleanStores($manifest, $fixture, 'https://a.example/first-run');
        $second = $this->seedFromCleanStores($manifest, $fixture, 'https://b.example/second-run');
        $this->assertSame($first, $second);
    }

    private function seedFromCleanStores(array $manifest, array $fixture, string $liveUrl): array
    {
        $this->flushTestDatabase(Redis::connection('nosql'));
        $runs = DB::connection('mongodb')->table(SyntheticWorkload::COLLECTION);
        $partitions = [];
        $seed = $manifest['seed'];
        try {
            Article::create(['title' => 'Live RSS', 'original_url' => $liveUrl]);
            $alreadyFetched = $fixture['items'][0];
            Article::create(['title' => $alreadyFetched['title'], 'original_url' => $alreadyFetched['link']]);
            $catalog = app(ContentCatalog::class);
            $catalog->ingest(array_replace($fixture, ['items' => array_slice($fixture['items'], 1)]));
            $this->artisan('demo:synthetic', ['action' => 'seed'])
                ->expectsOutputToContain('Run php artisan storylens ingest first.')->assertFailed();
            $this->assertSame(0, User::count());
            $this->assertSame(0, $runs->count());

            $catalog->ingest($fixture);
            $fixtureUrls = array_column($fixture['items'], 'link');
            sort($fixtureUrls, SORT_STRING);
            $urlsById = Article::whereIn('original_url', $fixtureUrls)->pluck('original_url', 'id')->all();
            $this->artisan('demo:synthetic', ['action' => 'inspect', '--seed' => '42'])
                ->expectsOutputToContain('"seed": 42')->assertSuccessful();
            $this->artisan('demo:synthetic', ['action' => 'seed', '--dry-run' => true])
                ->expectsOutputToContain('"dry_run": true')->assertSuccessful();
            $this->assertSame(0, User::count());
            $this->assertSame(0, $runs->count());
            $this->assertSame([], $this->streamEvents(InteractionStream::STREAM));
            $this->assertSame([], $this->streamEvents(ReadingPassHistory::STREAM));

            // Break dispatch after the first durable unlock, then resume the same run.
            $redis = Redis::connection('nosql')->client();
            $redis->set(ReadingPassHistory::STREAM, 'blocked stream');
            $this->artisan('demo:synthetic', ['action' => 'seed'])
                ->expectsOutputToContain('unlock could not be published')->assertFailed();
            $this->assertSame(1, ArticleUnlock::where('synthetic_seed', $seed)->count());
            $redis->del(ReadingPassHistory::STREAM);
            $this->artisan('demo:synthetic', ['action' => 'seed'])->assertSuccessful();
            $pinned = app(SyntheticWorkload::class)->inspect($seed, $manifest)['article_ids'];
            $this->assertSame($fixtureUrls, array_map(fn ($id) => $urlsById[$id], $pinned));
            $interactionEvents = $this->streamEvents(InteractionStream::STREAM);
            $unlockEvents = $this->streamEvents(ReadingPassHistory::STREAM);
            $this->assertCount($manifest['user_count'] * 2, $interactionEvents);
            $this->assertCount($manifest['user_count'], $unlockEvents);
            $this->assertSame($manifest['user_count'], User::where('synthetic', true)->count());
            foreach ([Bookmark::class, Boost::class, ArticleUnlock::class] as $model) {
                $this->assertSame($manifest['user_count'], $model::where('synthetic', true)->where('synthetic_seed', $seed)->count());
            }
            $this->assertTrue(MongoCounts::group(Boost::class, 'article_id')->isEmpty());
            $users = User::orderBy('_id')->get()->map->only(['id', 'email', 'name', 'synthetic', 'role'])->all();
            foreach ($users as $user) {
                $this->assertStringStartsWith('Synthetic ', $user['name']);
                $this->assertStringEndsWith('@demo.invalid', $user['email']);
                $this->assertSame('user', $user['role']);
            }
            foreach ([...$interactionEvents, ...$unlockEvents] as $event) {
                $this->assertTrue($event['synthetic']);
                $this->assertSame($seed, $event['synthetic_seed']);
                $second = substr($event['occurred_at'], 0, 19).'Z';
                $this->assertGreaterThanOrEqual($manifest['window_utc']['start'], $second);
                $this->assertLessThan($manifest['window_utc']['end_exclusive'], $second);
                $month = substr($event['occurred_at'], 0, 7);
                if ($event['type'] === 'UNLOCKED') {
                    $this->assertSame('synthetic', $event['origin']);
                    $partitions[ReadingPassHistory::BY_USER]['user_id']['year_month'][$event['user_id']][$month][] = $event['event_id'];
                } else {
                    $day = substr($event['occurred_at'], 0, 10);
                    $partitions[InteractionActivity::BY_USER]['user_id']['year_month'][$event['user_id']][$month][] = $event['event_id'];
                    $partitions[InteractionActivity::BY_ARTICLE]['article_id']['activity_day'][$event['article_id']][$day][] = $event['event_id'];
                }
            }

            // A new article sorts ahead of the pinned corpus, even on a rerun before the consumers start.
            Article::create(['title' => 'Added afterwards', 'original_url' => 'https://a.example/added']);
            $this->artisan('demo:synthetic', ['action' => 'seed'])->assertSuccessful();
            $this->assertSame($interactionEvents, $this->streamEvents(InteractionStream::STREAM));
            $this->assertSame($unlockEvents, $this->streamEvents(ReadingPassHistory::STREAM));
            $this->assertSame($pinned, app(SyntheticWorkload::class)->inspect($seed, $manifest)['article_ids']);

            $this->artisan('interactions', ['action' => 'consume', '--limit' => 100])->assertSuccessful();
            $this->artisan('reading-pass', ['action' => 'consume', '--limit' => 100])->assertSuccessful();
            $trending = app(Trending::class);
            $top = $trending->workloadTop($seed, 5);
            $this->assertNotEmpty($top);
            $this->assertSame(0, $trending->rebuild(app(MongoInteractionReplaySource::class)));
            $this->assertSame([], $trending->top());
            $counts = $this->cassandraPartitions($partitions);
            $this->assertSame($manifest['user_count'] * 5, array_sum($counts));

            $this->artisan('demo:synthetic', ['action' => 'seed'])
                ->expectsOutputToContain('"published_events": '.$manifest['event_count'])->assertSuccessful();
            $this->assertSame([], $this->streamEvents(InteractionStream::STREAM));
            $this->assertSame([], $this->streamEvents(ReadingPassHistory::STREAM));
            $this->assertSame($top, $trending->workloadTop($seed, 5));
            $this->assertSame($counts, $this->cassandraPartitions($partitions));

            // Fresh imports recreate the physical IDs. Compare their logical event and partition identities by URL.
            $events = array_map(function (array $event) use ($urlsById): array {
                $event['article_id'] = $urlsById[$event['article_id']];
                unset($event['event_id'], $event['aggregate_id']);

                return $event;
            }, [...$interactionEvents, ...$unlockEvents]);
            $ranking = array_slice(app(SyntheticWorkload::class)->inspect($seed, $manifest)['top'], 0, 5, preserve_keys: true);
            $topByUrl = [];
            foreach ($ranking as $id => $score) {
                $topByUrl[$urlsById[$id]] = $score;
            }
            $countsByUrl = [];
            foreach ($counts as $partition => $count) {
                $countsByUrl[strtr($partition, $urlsById)] = $count;
            }

            return [$users, $events, $topByUrl, $countsByUrl];
        } finally {
            try {
                $this->cassandraPartitions($partitions, clear: true);
            } finally {
                $runs->where('_id', "v1:$seed")->delete();
                ArticleUnlock::where('synthetic_seed', $seed)->delete();
                Boost::where('synthetic_seed', $seed)->delete();
                Bookmark::where('synthetic_seed', $seed)->delete();
                User::where('synthetic_seed', $seed)->delete();
                Article::query()->delete();
                Story::query()->delete();
                $this->flushTestDatabase(Redis::connection('nosql'));
            }
        }
    }

    private function streamEvents(string $stream): array
    {
        $rows = Redis::connection('nosql')->client()->xRange($stream, '-', '+');

        return array_map(fn (array $row) => json_decode($row['event'], true, flags: JSON_THROW_ON_ERROR), array_values($rows));
    }

    private function cassandraPartitions(array $partitions, bool $clear = false): array
    {
        $client = app(CassandraClient::class);
        $keyspace = $client->keyspace();
        $counts = [];
        foreach ($partitions as $table => $columns) {
            $idColumn = array_key_first($columns);
            $bucketColumn = array_key_first($columns[$idColumn]);
            $idField = $table === ReadingPassHistory::BY_USER ? 'unlock_id' : 'event_id';
            $where = "WHERE $idColumn = ? AND $bucketColumn = ?";
            foreach ($columns[$idColumn][$bucketColumn] as $id => $buckets) {
                foreach ($buckets as $bucket => $ids) {
                    if ($clear) {
                        $client->execute("DELETE FROM $keyspace.$table $where", [$id, $bucket])->asVoidResult();
                    } else {
                        $rows = $client->rows("SELECT $idField FROM $keyspace.$table $where", [$id, $bucket]);
                        $this->assertEqualsCanonicalizing($ids, array_map(strval(...), array_column($rows, $idField)));
                        $counts["$table:$id:$bucket"] = count($rows);
                    }
                }
            }
        }

        return $counts;
    }
}
