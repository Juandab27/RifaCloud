<?php

namespace App\Modules\Raffles\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Services\NumberGenerator;
use App\Modules\Raffles\Enums\RaffleStatus;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RaffleService
{
    public function __construct(private readonly NumberGenerator $numberGenerator) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $admin): Raffle
    {
        return DB::transaction(function () use ($data, $admin): Raffle {
            $raffle = Raffle::query()->create([
                ...$data,
                'status' => RaffleStatus::DRAFT,
                'created_by' => $admin->id,
            ]);

            $this->numberGenerator->generate($raffle);

            return $raffle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Raffle $raffle, array $data): Raffle
    {
        if ($raffle->status !== RaffleStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only DRAFT raffles can be reconfigured.',
            ]);
        }

        return DB::transaction(function () use ($raffle, $data): Raffle {
            $rangeChanged = array_key_exists('min_number', $data) && (int) $data['min_number'] !== $raffle->min_number
                || array_key_exists('max_number', $data) && (int) $data['max_number'] !== $raffle->max_number;

            $raffle->fill($data)->save();

            if ($rangeChanged) {
                $this->regenerateNumbers($raffle);
            }

            return $raffle->refresh();
        });
    }

    public function publish(Raffle $raffle): Raffle
    {
        return $this->transition($raffle, RaffleStatus::PUBLISHED);
    }

    public function close(Raffle $raffle): Raffle
    {
        return $this->transition($raffle, RaffleStatus::CLOSED);
    }

    private function transition(Raffle $raffle, RaffleStatus $target): Raffle
    {
        if (! $raffle->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot transition raffle from %s to %s.',
                    $raffle->status->value,
                    $target->value,
                ),
            ]);
        }

        $raffle->status = $target;
        $raffle->save();

        return $raffle->refresh();
    }

    private function regenerateNumbers(Raffle $raffle): void
    {
        $hasAssignedNumbers = $raffle->numbers()
            ->where('status', '!=', NumberStatus::AVAILABLE->value)
            ->exists();

        if ($hasAssignedNumbers) {
            throw ValidationException::withMessages([
                'min_number' => 'The number range cannot change after numbers are reserved or sold.',
            ]);
        }

        $raffle->numbers()->delete();
        $this->numberGenerator->generate($raffle);
    }
}
