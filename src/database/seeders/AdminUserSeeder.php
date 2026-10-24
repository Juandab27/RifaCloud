<?php

namespace Database\Seeders;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create or update the bootstrap admin from environment values.
     * Skipped when ADMIN_EMAIL or ADMIN_PASSWORD are empty.
     */
    public function run(): void
    {
        $email = config('rifacloud.admin.email');
        $password = config('rifacloud.admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD not set: admin seeder skipped.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('rifacloud.admin.name'),
                'phone' => config('rifacloud.admin.phone'),
                'password' => $password,
                'role' => UserRole::ADMIN,
            ],
        );
    }
}
