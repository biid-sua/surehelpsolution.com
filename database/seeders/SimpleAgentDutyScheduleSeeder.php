<?php

namespace Database\Seeders;

use App\Models\AgentDutySchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SimpleAgentDutyScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all agents
        $agents = User::where('role', 'agent')->get();

        if ($agents->isEmpty()) {
            $this->command->warn('No agents found. Please run UserSeeder first.');

            return;
        }

        // Clear existing schedules
        AgentDutySchedule::truncate();

        // Create simple, working schedules for each agent
        foreach ($agents as $index => $agent) {
            // Create a basic weekday schedule for each agent
            AgentDutySchedule::create([
                'agent_id' => $agent->id,
                'title' => 'Regular Shift - Weekdays',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'days_of_week' => [1, 2, 3, 4, 5], // Monday to Friday
                'shift_type' => 'morning',
                'description' => 'Regular weekday shift for '.$agent->name,
                'effective_from' => Carbon::now()->startOfMonth(),
                'effective_until' => Carbon::now()->endOfMonth()->addMonths(3),
                'is_active' => true,
            ]);

            // Create a weekend schedule for every other agent
            if ($index % 2 == 0) {
                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Weekend Shift',
                    'start_time' => '10:00:00',
                    'end_time' => '18:00:00',
                    'days_of_week' => [0, 6], // Sunday and Saturday
                    'shift_type' => 'afternoon',
                    'description' => 'Weekend shift for '.$agent->name,
                    'effective_from' => Carbon::now()->startOfMonth(),
                    'effective_until' => Carbon::now()->endOfMonth()->addMonths(3),
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info('Simple agent duty schedules created successfully with '.AgentDutySchedule::count().' total schedules.');
    }
}
