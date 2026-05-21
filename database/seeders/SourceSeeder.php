<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Source;
use Illuminate\Database\Seeder;

class SourceSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve category IDs once
        $cats = Category::pluck('id', 'slug');

        $sources = [
            // ── VnExpress ──────────────────────────────────────────────────────
            [
                'name'         => 'VnExpress – Thời sự',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/thoi-su.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'thoi-su',
            ],
            [
                'name'         => 'VnExpress – Thế giới',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/the-gioi.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'the-gioi',
            ],
            [
                'name'         => 'VnExpress – Kinh doanh',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/kinh-doanh.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'kinh-doanh',
            ],
            [
                'name'         => 'VnExpress – Công nghệ',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/cong-nghe.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'cong-nghe',
            ],
            [
                'name'         => 'VnExpress – Thể thao',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/the-thao.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'the-thao',
            ],
            [
                'name'         => 'VnExpress – Giải trí',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/giai-tri.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'giai-tri',
            ],
            [
                'name'         => 'VnExpress – Sức khỏe',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/suc-khoe.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'suc-khoe',
            ],
            [
                'name'         => 'VnExpress – Giáo dục',
                'url'          => 'https://vnexpress.net',
                'feed_url'     => 'https://vnexpress.net/rss/giao-duc.rss',
                'logo_url'     => 'https://s1.vnecdn.net/vnexpress/restapi/i/v526/logo_vne_desktop.svg',
                'category_slug'=> 'giao-duc',
            ],

            // ── Tuổi Trẻ ──────────────────────────────────────────────────────
            [
                'name'         => 'Tuổi Trẻ – Thời sự',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/thoi-su.rss',
                'logo_url'     => null,
                'category_slug'=> 'thoi-su',
            ],
            [
                'name'         => 'Tuổi Trẻ – Thế giới',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/the-gioi.rss',
                'logo_url'     => null,
                'category_slug'=> 'the-gioi',
            ],
            [
                'name'         => 'Tuổi Trẻ – Kinh tế',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/kinh-te.rss',
                'logo_url'     => null,
                'category_slug'=> 'kinh-doanh',
            ],
            [
                'name'         => 'Tuổi Trẻ – Công nghệ',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/khoa-hoc-cong-nghe.rss',
                'logo_url'     => null,
                'category_slug'=> 'cong-nghe',
            ],
            [
                'name'         => 'Tuổi Trẻ – Thể thao',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/the-thao.rss',
                'logo_url'     => null,
                'category_slug'=> 'the-thao',
            ],
            [
                'name'         => 'Tuổi Trẻ – Giải trí',
                'url'          => 'https://tuoitre.vn',
                'feed_url'     => 'https://tuoitre.vn/rss/giai-tri.rss',
                'logo_url'     => null,
                'category_slug'=> 'giai-tri',
            ],

            // ── Thanh Niên ────────────────────────────────────────────────────
            [
                'name'         => 'Thanh Niên – Thời sự',
                'url'          => 'https://thanhnien.vn',
                'feed_url'     => 'https://thanhnien.vn/rss/thoi-su.rss',
                'logo_url'     => null,
                'category_slug'=> 'thoi-su',
            ],
            [
                'name'         => 'Thanh Niên – Thế giới',
                'url'          => 'https://thanhnien.vn',
                'feed_url'     => 'https://thanhnien.vn/rss/the-gioi.rss',
                'logo_url'     => null,
                'category_slug'=> 'the-gioi',
            ],
            [
                'name'         => 'Thanh Niên – Công nghệ',
                'url'          => 'https://thanhnien.vn',
                'feed_url'     => 'https://thanhnien.vn/rss/cong-nghe.rss',
                'logo_url'     => null,
                'category_slug'=> 'cong-nghe',
            ],
            [
                'name'         => 'Thanh Niên – Thể thao',
                'url'          => 'https://thanhnien.vn',
                'feed_url'     => 'https://thanhnien.vn/rss/the-thao.rss',
                'logo_url'     => null,
                'category_slug'=> 'the-thao',
            ],

            // ── Dân Trí ───────────────────────────────────────────────────────
            [
                'name'         => 'Dân Trí – Xã hội',
                'url'          => 'https://dantri.com.vn',
                'feed_url'     => 'https://dantri.com.vn/rss/xa-hoi.rss',
                'logo_url'     => null,
                'category_slug'=> 'thoi-su',
            ],
            [
                'name'         => 'Dân Trí – Kinh doanh',
                'url'          => 'https://dantri.com.vn',
                'feed_url'     => 'https://dantri.com.vn/rss/kinh-doanh.rss',
                'logo_url'     => null,
                'category_slug'=> 'kinh-doanh',
            ],
            [
                'name'         => 'Dân Trí – Sức khỏe',
                'url'          => 'https://dantri.com.vn',
                'feed_url'     => 'https://dantri.com.vn/rss/suc-khoe.rss',
                'logo_url'     => null,
                'category_slug'=> 'suc-khoe',
            ],
            [
                'name'         => 'Dân Trí – Giáo dục',
                'url'          => 'https://dantri.com.vn',
                'feed_url'     => 'https://dantri.com.vn/rss/giao-duc-huong-nghiep.rss',
                'logo_url'     => null,
                'category_slug'=> 'giao-duc',
            ],
            [
                'name'         => 'Dân Trí – Giải trí',
                'url'          => 'https://dantri.com.vn',
                'feed_url'     => 'https://dantri.com.vn/rss/giai-tri.rss',
                'logo_url'     => null,
                'category_slug'=> 'giai-tri',
            ],

            // ── VietNamNet ────────────────────────────────────────────────────
            [
                'name'         => 'VietNamNet – Thời sự',
                'url'          => 'https://vietnamnet.vn',
                'feed_url'     => 'https://vietnamnet.vn/rss/thoi-su.rss',
                'logo_url'     => null,
                'category_slug'=> 'thoi-su',
            ],
            [
                'name'         => 'VietNamNet – Kinh doanh',
                'url'          => 'https://vietnamnet.vn',
                'feed_url'     => 'https://vietnamnet.vn/rss/kinh-doanh.rss',
                'logo_url'     => null,
                'category_slug'=> 'kinh-doanh',
            ],
            [
                'name'         => 'VietNamNet – Công nghệ',
                'url'          => 'https://vietnamnet.vn',
                'feed_url'     => 'https://vietnamnet.vn/cong-nghe.rss',
                'logo_url'     => null,
                'category_slug'=> 'cong-nghe',
            ],
        ];

        foreach ($sources as $data) {
            $categoryId = $cats[$data['category_slug']] ?? null;
            if (!$categoryId) continue;

            Source::updateOrCreate(
                ['feed_url' => $data['feed_url']],
                [
                    'name'        => $data['name'],
                    'url'         => $data['url'],
                    'logo_url'    => $data['logo_url'],
                    'category_id' => $categoryId,
                    'is_active'   => true,
                ]
            );
        }
    }
}
