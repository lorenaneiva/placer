<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::tomorrow(config('placer.timezone'))->setTime(14, 0)->utc();

        return ['user_id' => User::factory(), 'space_id' => Space::factory(), 'starts_at' => $start, 'ends_at' => $start->addHour(), 'total_price' => '100.00', 'status' => 'confirmed'];
    }
}
