<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UpdateExistingUsersWithUniqueIdSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all users without unique_id
        $users = User::whereNull('unique_id')->get();

        foreach ($users as $user) {
            $user->unique_id = User::generateUniqueId($user->role ?? 'client');
            $user->save();
        }

        $this->command->info("Updated {$users->count()} users with unique IDs.");
    }
}
