<?php

namespace App\Content;

use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Offline cutover only. Natural keys make interrupted copies/remaps safe to repeat. */
class ConfigSqlImport
{
    public function run(): array
    {
        if (DB::connection()->getDriverName() === 'mongodb') {
            throw new LogicException('The default connection must point to the legacy SQL database.');
        }
        if (Schema::getForeignKeys('source_fetch_logs')) {
            throw new LogicException('Run the source_fetch_logs widening migration before importing.');
        }
        // Validate the entire SQL input before any Mongo/cache/reference mutation.
        $sqlCategories = DB::table('categories')->orderBy('id')->get();
        $sqlSources = DB::table('sources')->orderBy('id')->get();
        $duplicates = [];
        foreach ([['categories', 'slug', $sqlCategories], ['sources', 'feed_url', $sqlSources]] as [$table, $key, $rows]) {
            foreach ($rows->groupBy($key) as $group) {
                if ($group->count() > 1) {
                    $duplicates[] = $table.'.'.$key.' duplicate ids: '.$group->pluck('id')->implode(', ');
                }
            }
        }
        if ($duplicates) {
            throw new LogicException(implode('; ', $duplicates));
        }
        $counts = array_fill_keys(['categories_copied', 'categories_matched', 'sources_copied', 'sources_matched',
            'article_references', 'users_remapped', 'fetch_logs_remapped'], 0);
        $categories = [];
        foreach ($sqlCategories as $row) {
            $category = $this->copy(Category::class, ['slug' => $row->slug], (array) $row);
            $counts[$category->wasRecentlyCreated ? 'categories_copied' : 'categories_matched']++;
            $categories[(string) $row->id] = $category->id;
            $counts['article_references'] += $this->remapArticles('category_id', $row->id, $category->id);

        }
        foreach ($sqlSources as $row) {
            if (! isset($categories[(string) $row->category_id])) {
                throw new LogicException('Legacy source has no matching category.');
            }
            $data = (array) $row;
            $data['category_id'] = $categories[(string) $row->category_id];
            $source = $this->copy(Source::class, ['feed_url' => $row->feed_url], $data);
            $counts[$source->wasRecentlyCreated ? 'sources_copied' : 'sources_matched']++;
            $counts['article_references'] += $this->remapArticles('source_id', $row->id, $source->id);

            $counts['fetch_logs_remapped'] += DB::table('source_fetch_logs')->where('source_id', (string) $row->id)
                ->update(['source_id' => $source->id]);
        }
        foreach (User::whereNotNull('favorite_category_ids')->cursor() as $user) {
            $before = $user->favorite_category_ids;
            $after = array_values(array_unique(array_map(fn ($id) => $categories[(string) $id] ?? $id, $before)));
            if ($before !== $after) {
                $user->update(['favorite_category_ids' => $after]);
                $counts['users_remapped']++;
            }
        }
        app(CategoryList::class)->forget();

        return $counts;
    }

    private function copy(string $class, array $key, array $data): Category|Source
    {
        $model = $class::firstOrNew($key);
        if (! $model->exists) {
            $model->fill($data);
            foreach (['created_at', 'updated_at'] as $field) {
                if (! empty($data[$field])) {
                    $model->setAttribute($field, $data[$field]);
                }
            }
            $model->save();
        }

        return $model;
    }

    private function remapArticles(string $field, int $old, string $id): int
    {
        return Article::whereIn($field, [$old, (string) $old])->update([$field => $id]);
    }
}
