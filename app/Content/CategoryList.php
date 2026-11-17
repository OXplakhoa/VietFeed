<?php

namespace App\Content;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CategoryList
{
    public const KEY = 'catalog:categories:active:v1';

    public function active(): Collection
    {
        try {
            $rows = Cache::remember(self::KEY, 300, fn () => Category::active()->get()
                ->map(fn ($category) => ['_id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'is_active' => true])->all());
        } catch (Throwable) {
            return Category::active()->get();
        }

        return Category::hydrate($rows);
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // A cache outage must not turn a successful canonical write into an error.
        }
    }
}
