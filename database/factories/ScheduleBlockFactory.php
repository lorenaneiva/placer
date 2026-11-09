<?php

namespace Database\Factories;

use App\Models\ScheduleBlock;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleBlock> */
class ScheduleBlockFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::tomorrow(config('placer.timezone'))->setTime(14, 0)->utc();

        return ['space_id' => Space::factory(), 'starts_at' => $start, 'ends_at' => $start->addHour(), 'created_by' => User::factory(), 'reason' => 'Manutenção'];
    }
}
