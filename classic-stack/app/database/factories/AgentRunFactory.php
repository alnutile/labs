<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'prompt' => 'Inspect a public web page and save a JSON report.', 'status' => 'queued'];
    }
}
