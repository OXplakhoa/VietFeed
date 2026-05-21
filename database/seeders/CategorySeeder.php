<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Thời sự',   'slug' => 'thoi-su'],
            ['name' => 'Thế giới',  'slug' => 'the-gioi'],
            ['name' => 'Kinh doanh','slug' => 'kinh-doanh'],
            ['name' => 'Công nghệ', 'slug' => 'cong-nghe'],
            ['name' => 'Thể thao',  'slug' => 'the-thao'],
            ['name' => 'Giải trí',  'slug' => 'giai-tri'],
            ['name' => 'Sức khỏe',  'slug' => 'suc-khoe'],
            ['name' => 'Giáo dục',  'slug' => 'giao-duc'],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(['slug' => $cat['slug']], ['name' => $cat['name']]);
        }
    }
}
