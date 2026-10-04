<?php

namespace Database\Seeders;

use App\Models\AgentDutySchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class AgentDutyScheduleSeeder extends Seeder
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

        // Create sample schedules for each agent
        foreach ($agents as $index => $agent) {
            // Create different types of schedules for different agents
            if ($index % 4 == 0) {
                // Agent 1: Morning shift for next week
                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Morning Shift - Week 1',
                    'start_datetime' => Carbon::now()->addDays(1)->setTime(8, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(1)->setTime(16, 0, 0),
                    'shift_type' => 'morning',
                    'description' => 'Regular morning shift for customer service and support',
                    'is_active' => true,
                ]);

                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Morning Shift - Week 2',
                    'start_datetime' => Carbon::now()->addDays(2)->setTime(8, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(2)->setTime(16, 0, 0),
                    'shift_type' => 'morning',
                    'description' => 'Regular morning shift for customer service and support',
                    'is_active' => true,
                ]);
            } elseif ($index % 4 == 1) {
                // Agent 2: Afternoon shift
                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Afternoon Shift - Week 1',
                    'start_datetime' => Carbon::now()->addDays(1)->setTime(12, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(1)->setTime(20, 0, 0),
                    'shift_type' => 'afternoon',
                    'description' => 'Afternoon shift for peak hours and extended support',
                    'is_active' => true,
                ]);

                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Afternoon Shift - Week 2',
                    'start_datetime' => Carbon::now()->addDays(2)->setTime(12, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(2)->setTime(20, 0, 0),
                    'shift_type' => 'afternoon',
                    'description' => 'Afternoon shift for peak hours and extended support',
                    'is_active' => true,
                ]);
            } elseif ($index % 4 == 2) {
                // Agent 3: Night shift (overnight)
                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Night Shift - Week 1',
                    'start_datetime' => Carbon::now()->addDays(1)->setTime(20, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(2)->setTime(4, 0, 0), // Next day
                    'shift_type' => 'night',
                    'description' => 'Overnight shift for 24/7 coverage and emergency support',
                    'is_active' => true,
                ]);

                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Night Shift - Week 2',
                    'start_datetime' => Carbon::now()->addDays(2)->setTime(20, 0, 0),
                    'end_datetime' => Carbon::now()->addDays(3)->setTime(4, 0, 0), // Next day
                    'shift_type' => 'night',
                    'description' => 'Overnight shift for 24/7 coverage and emergency support',
                    'is_active' => true,
                ]);
            } else {
                // Agent 4: Weekend specialist
                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Weekend Morning Shift',
                    'start_datetime' => Carbon::now()->next(Carbon::SATURDAY)->setTime(9, 0, 0),
                    'end_datetime' => Carbon::now()->next(Carbon::SATURDAY)->setTime(17, 0, 0),
                    'shift_type' => 'morning',
                    'description' => 'Weekend coverage shift for customer support',
                    'is_active' => true,
                ]);

                AgentDutySchedule::create([
                    'agent_id' => $agent->id,
                    'title' => 'Weekend Evening Shift',
                    'start_datetime' => Carbon::now()->next(Carbon::SUNDAY)->setTime(17, 0, 0),
                    'end_datetime' => Carbon::now()->next(Carbon::MONDAY)->setTime(1, 0, 0), // Next day
                    'shift_type' => 'night',
                    'description' => 'Weekend evening shift for extended coverage',
                    'is_active' => true,
                ]);
            }
        }

        $this->command->info('Agent duty schedules created successfully with '.AgentDutySchedule::count().' total schedules.');
    }
}
