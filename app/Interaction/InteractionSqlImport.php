<?php

namespace App\Interaction;

use App\Models\Bookmark;
use App\Models\Boost;
use App\Models\InteractionMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class InteractionSqlImport
{
    public function __construct(private InteractionStream $stream) {}

    public function hasImportedMemberships(): bool
    {
        return Bookmark::where('legacy_sql_id', 'exists', true)->exists()
            || Boost::where('legacy_sql_id', 'exists', true)->exists();
    }

    public function run(bool $emit): array
    {
        return [
            'bookmarks' => $this->copy(DB::table('bookmarks'), Bookmark::class, $emit),
            'boosts' => $this->copy(DB::table('boosts'), Boost::class, $emit),
        ];
    }

    /** @param class-string<InteractionMembership> $model */
    private function copy(Builder $source, string $model, bool $emit): array
    {
        $counts = ['inserted' => 0, 'existing' => 0, 'published' => 0, 'publish_failed' => 0];
        $source->chunkById(500, function ($rows) use ($model, $emit, &$counts) {
            foreach ($rows as $row) {
                $key = ['user_id' => (string) $row->user_id, 'article_id' => (string) $row->article_id];
                $legacy = ['legacy_sql_id' => (int) $row->id];
                $legacy += array_filter([
                    'created_at' => $row->created_at ?? $row->updated_at,
                    'updated_at' => $row->updated_at ?? $row->created_at,
                ], fn ($time) => $time !== null);
                $membership = $model::unguarded(fn () => $model::firstOrCreate($key, $legacy));
                if (! $membership->wasRecentlyCreated) {
                    $counts['existing']++;

                    continue;
                }
                $counts['inserted']++;
                if ($emit) {
                    $published = $this->stream->publishMembership(InteractionEvent::membership($membership, added: true));
                    $counts[$published ? 'published' : 'publish_failed']++;
                }
            }
        });

        return $counts;
    }
}
