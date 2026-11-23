<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@vietfeed.com'],
            [
                'name' => 'VietFeed Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $options = ['--super-admin' => 'admin@vietfeed.com'];
        if (($this->command?->call('rbac:bootstrap', $options) ?? Artisan::call('rbac:bootstrap', $options)) !== 0) {
            throw new RuntimeException('Unable to bootstrap the demo Super Admin.');
        }

        $users = [
            ['name' => 'Nguyễn Văn An',  'email' => 'user1@vietfeed.com'],
            ['name' => 'Trần Thị Bình',  'email' => 'user2@vietfeed.com'],
            ['name' => 'Lê Minh Châu',   'email' => 'user3@vietfeed.com'],
        ];

        foreach ($users as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
