<?php

namespace Database\Factories;

use App\Models\IdeaBoardGroup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaBoardGroup>
 */
class IdeaBoardGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Company Operations',
            'Product & Technology',
            'People & Culture',
            'Finance & Accounting',
            'Sales & Marketing',
        ]);

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }
}
