<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@surehelpsolution.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'phone' => '+1-555-0100',
            'is_active' => true,
            'unique_id' => User::generateUniqueId('admin'),
        ]);

        // Create Agent User
        User::create([
            'name' => 'John Agent',
            'email' => 'agent@surehelpsolution.com',
            'password' => Hash::make('password'),
            'role' => 'agent',
            'phone' => '+1-555-0101',
            'is_active' => true,
            'unique_id' => User::generateUniqueId('agent'),
        ]);

        // Create Client User
        User::create([
            'name' => 'Jane Client',
            'email' => 'client@surehelpsolution.com',
            'password' => Hash::make('password'),
            'role' => 'client',
            'phone' => '+1-555-0102',
            'is_active' => true,
            'unique_id' => User::generateUniqueId('client'),
        ]);

        // Create additional demo users
        User::create([
            'name' => 'Mike Johnson',
            'email' => 'mike.agent@surehelpsolution.com',
            'password' => Hash::make('password'),
            'role' => 'agent',
            'phone' => '+1-555-0103',
            'is_active' => true,
            'unique_id' => User::generateUniqueId('agent'),
        ]);

        User::create([
            'name' => 'Sarah Wilson',
            'email' => 'sarah.client@surehelpsolution.com',
            'password' => Hash::make('password'),
            'role' => 'client',
            'phone' => '+1-555-0104',
            'is_active' => true,
            'unique_id' => User::generateUniqueId('client'),
        ]);
    }
}
