<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->words(2, true),
            'base_url' => 'https://'.fake()->domainName(),
            'platform' => 'wordpress',
            'crawl_settings' => null,
        ];
    }
}
