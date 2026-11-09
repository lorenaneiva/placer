<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Reservation;
use App\Models\ScheduleBlock;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchedulingService
{
    public function local(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, config('placer.timezone'));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'Informe uma data e horário válidos.']);
        }
    }

    public function validateInterval(Space $space, CarbonImmutable $start, CarbonImmutable $end, bool $block = false): void
    {
        $start = $start->setTimezone(config('placer.timezone'));
        $end = $end->setTimezone(config('placer.timezone'));
        $now = CarbonImmutable::now(config('placer.timezone'));
        if ($start <= $now || ! $start->isSameDay($end) || $start->minute !== 0 || $start->second !== 0 || $end->minute !== 0 || $end->second !== 0 || $start->hour < config('placer.opening_hour') || $end->hour > config('placer.closing_hour') || $end <= $start || (! $block && $end != $start->addMinutes(config('placer.slot_minutes')))) {
            throw ValidationException::withMessages(['starts_at' => 'Escolha horários futuros na grade de hora cheia, entre 08h e 22h. Uma reserva dura uma hora.']);
        }
        if (! $block) {
            if (! $space->is_active || ! $space->category->is_active) {
                throw ValidationException::withMessages(['space_id' => 'Este espaço ou sua categoria está inativo.']);
            }
            $limit = $space->category->max_advance_days === 0 ? $now->endOfDay() : $now->addDays($space->category->max_advance_days);
            if ($start > $limit) {
                throw ValidationException::withMessages(['starts_at' => 'O horário ultrapassa a antecedência máxima da categoria.']);
            }
        }
    }

    public function conflict(Space $space, CarbonImmutable $start, CarbonImmutable $end, ?int $ignore = null): ?string
    {
        if ($space->reservations()->where('status', 'confirmed')->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists()) {
            return 'Ocupado';
        }
        if ($space->scheduleBlocks()->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists()) {
            return 'Bloqueado';
        }

        return null;
    }

    public function reserve(User $user, int $spaceId, string $startsAt, ?Reservation $reservation = null): Reservation
    {
        return DB::transaction(function () use ($user, $spaceId, $startsAt, $reservation) {
            $ids = array_unique(array_filter([$spaceId, $reservation?->space_id]));
            sort($ids);
            $spaces = Space::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $space = $spaces->get($spaceId);
            abort_unless($space, 404);
            $space->setRelation('category', Category::whereKey($space->category_id)->sharedLock()->firstOrFail());
            $start = $this->local($startsAt);
            $end = $start->addMinutes(config('placer.slot_minutes'));
            $this->validateInterval($space, $start, $end);
            if ($reservation) {
                $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                if (! $spaces->has($reservation->space_id)) {
                    throw ValidationException::withMessages(['starts_at' => 'Esta reserva foi alterada por outra operação. Atualize a página e tente novamente.']);
                } if ($reservation->status !== 'confirmed') {
                    throw ValidationException::withMessages(['starts_at' => 'Uma reserva cancelada não pode ser reagendada.']);
                }
            }
            if ($this->conflict($space, $start, $end, $reservation?->id)) {
                throw ValidationException::withMessages(['starts_at' => 'Horário indisponível. Consulte a disponibilidade e escolha outro horário.']);
            }
            if ($reservation && $reservation->space_id === $spaceId && $reservation->starts_at->equalTo($start)) {
                return $reservation;
            }
            $values = ['space_id' => $spaceId, 'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'total_price' => $space->hourly_price];
            if ($reservation) {
                $reservation->update($values);

                return $reservation;
            }

            return Reservation::create($values + ['user_id' => $user->id, 'status' => 'confirmed']);
        }, 3);
    }

    public function cancel(Reservation $reservation, User $actor): void
    {
        abort_unless($actor->is_admin || $reservation->user_id === $actor->id, 403);
        DB::transaction(function () use ($reservation, $actor) {
            $originalSpaceId = $reservation->space_id;
            Space::whereKey($originalSpaceId)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->space_id !== $originalSpaceId) {
                throw ValidationException::withMessages(['reservation' => 'A reserva foi reagendada por outra operação. Atualize a página antes de cancelar.']);
            }
            if ($reservation->status !== 'confirmed' || (! $actor->is_admin && ! $reservation->starts_at->isFuture())) {
                throw ValidationException::withMessages(['reservation' => 'Esta reserva não pode ser cancelada.']);
            }
            $reservation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }, 3);
    }

    public function block(User $admin, int $spaceId, string $startsAt, string $endsAt, ?string $reason): ScheduleBlock
    {
        abort_unless($admin->is_admin, 403);

        return DB::transaction(function () use ($admin, $spaceId, $startsAt, $endsAt, $reason) {
            $space = Space::whereKey($spaceId)->lockForUpdate()->firstOrFail();
            $start = $this->local($startsAt);
            $end = $this->local($endsAt);
            $this->validateInterval($space, $start, $end, true);
            if ($this->conflict($space, $start, $end)) {
                throw ValidationException::withMessages(['starts_at' => 'O bloqueio conflita com uma reserva ou outro bloqueio.']);
            }

            return ScheduleBlock::create(['space_id' => $spaceId, 'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'reason' => $reason, 'created_by' => $admin->id]);
        }, 3);
    }

    public function unblock(ScheduleBlock $block): void
    {
        DB::transaction(function () use ($block) {
            Space::whereKey($block->space_id)->lockForUpdate()->firstOrFail();
            $block->delete();
        }, 3);
    }

    public function slots(Space $space, string $date, ?int $ignore = null): array
    {
        $slots = [];
        for ($hour = config('placer.opening_hour'); $hour < config('placer.closing_hour'); $hour++) {
            $start = $this->local($date)->setTime($hour, 0);
            $end = $start->addMinutes(config('placer.slot_minutes'));
            $label = 'Livre';
            try {
                $this->validateInterval($space, $start, $end);
                $label = $this->conflict($space, $start, $end, $ignore) ?? 'Livre';
            } catch (ValidationException) {
                $label = 'Fora do prazo';
            }
            $slots[] = ['start' => $start, 'end' => $end, 'label' => $label];
        }

        return $slots;
    }
}
