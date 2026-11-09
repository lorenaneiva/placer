<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Space> */
class SpaceFactory extends Factory
{
    public function definition(): array
    {
        return ['category_id' => Category::factory(), 'identifier' => fake()->unique()->numerify('###'), 'hourly_price' => '100.00', 'capacity' => 20, 'is_active' => true];
    }
}
