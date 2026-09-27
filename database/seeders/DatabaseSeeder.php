<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Creates a demo administrator. Use `php artisan watchrex:install` in production. */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'WatchRex Admin',
            'email' => 'admin@example.com',
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
        ]);
    }
}
