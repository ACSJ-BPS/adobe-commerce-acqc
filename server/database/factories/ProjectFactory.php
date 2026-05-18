<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'       => fake()->company(),
            'dr_no'      => 'DR' . fake()->unique()->numerify('########'),
            'description' => fake()->sentence(),
            'repo_url'   => null,
            'local_path' => null,
            'tech_stack' => null,
            'status'     => 'active',
            'created_by' => User::factory(),
        ];
    }
}
