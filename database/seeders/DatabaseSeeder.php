<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FactorySeeder::class,
            SystemSettingsSeeder::class,
            DocsSeeder::class,
        ]);

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if ($email && $password) {
            if (strlen($password) < 12) {
                throw new RuntimeException('ADMIN_PASSWORD must be at least 12 characters.');
            }

            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => env('ADMIN_NAME', 'TriProof Administrator'),
                    'password_hash' => Hash::make($password),
                    'role' => UserRole::Admin,
                    'language_pref' => 'en',
                    'factory_id' => null,
                ]
            );

            $this->command?->info("Administrator {$email} is ready.");
        }

        if (app()->environment('local') && config('triproof.demo_seed')) {
            $this->call(DemoSeeder::class);
        }
    }
}
