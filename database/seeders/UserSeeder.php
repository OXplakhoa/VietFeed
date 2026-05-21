<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@vietfeed.com'],
            [
                'name'              => 'VietFeed Admin',
                'password'          => Hash::make('password'),
                'role'              => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $users = [
            ['name' => 'Nguyễn Văn An',  'email' => 'user1@vietfeed.com'],
            ['name' => 'Trần Thị Bình',  'email' => 'user2@vietfeed.com'],
            ['name' => 'Lê Minh Châu',   'email' => 'user3@vietfeed.com'],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'              => $data['name'],
                    'password'          => Hash::make('password'),
                    'role'              => 'user',
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
