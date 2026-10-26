<?php

namespace App\Modules\Orders\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Numbers\Enums\NumberStatus;
use App\Modules\Numbers\Models\RaffleNumber;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderConflictException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Raffles\Models\Raffle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderReservationService
{
    /**
     * Create an order and reserve its numbers atomically (FR-006, FR-007).
     *
     * The order is created first so raffle_numbers.order_id can reference it,
     * then the requested rows are locked and reserved. Any failure rolls the
     * whole transaction back, including the order itself.
     *
     * @param  list<int>  $numbers
     */
    public function create(User $user, int $raffleId, array $numbers): Order
    {
        $numbers = $this->normalizeNumbers($numbers);
        $this->assertValidCount($numbers);

        $minutes = max(1, (int) config('rifacloud.reservation_minutes'));

        return DB::transaction(function () use ($user, $raffleId, $numbers, $minutes): Order {
            $raffle = Raffle::query()
                ->whereKey($raffleId)
                ->lockForUpdate()
                ->first();

            if ($raffle === null || ! $raffle->isPublished()) {
                throw OrderConflictException::raffleNotAvailable();
            }

            $now = now();

            $this->assertPurchaseWindow($raffle, $now);
            $this->assertNumbersInRange($raffle, $numbers);

            $expiresAt = $now->copy()->addMinutes($minutes);

            $order = Order::query()->create([
                'user_id' => $user->id,
                'raffle_id' => $raffle->id,
                'order_number' => $this->generateOrderNumber(),
                'total_amount' => $raffle->price_per_number * count($numbers),
                'status' => OrderStatus::PENDING_PAYMENT,
                'expires_at' => $expiresAt,
            ]);

            $lockedNumbers = RaffleNumber::query()
                ->where('raffle_id', $raffle->id)
                ->whereIn('number', $numbers)
                ->orderBy('number')
                ->lockForUpdate()
                ->get();

            $this->assertNumbersBelongToRaffle($numbers, $lockedNumbers);
            $this->assertNumbersAvailable($numbers, $lockedNumbers);

            $affected = RaffleNumber::query()
                ->whereKey($lockedNumbers->modelKeys())
                ->where('status', NumberStatus::AVAILABLE->value)
                ->update([
                    'status' => NumberStatus::RESERVED->value,
                    'reserved_at' => $now,
                    'reserved_until' => $expiresAt,
                    'order_id' => $order->id,
                    'updated_at' => $now,
                ]);

            if ($affected !== $lockedNumbers->count()) {
                // Defensive check for a concurrent reservation that slipped
                // past the row locks (NFR-002, NFR-004).
                throw OrderConflictException::numbersUnavailable($numbers);
            }

            OrderItem::query()->insert(
                $lockedNumbers->map(fn (RaffleNumber $number): array => [
                    'order_id' => $order->id,
                    'raffle_number_id' => $number->id,
                    'price_at_purchase' => $raffle->price_per_number,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );

            return $order;
        }, 3);
    }

    /**
     * @param  array<int, mixed>  $numbers
     * @return list<int>
     */
    private function normalizeNumbers(array $numbers): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $number): int => (int) $number,
            $numbers,
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * Enforce the raffle purchase window on the backend. The check runs while
     * the raffle row is locked, so an administrative schedule change cannot
     * slip between validation and reservation.
     */
    private function assertPurchaseWindow(Raffle $raffle, \DateTimeInterface $now): void
    {
        if (! $raffle->hasValidSchedule()) {
            throw OrderConflictException::raffleScheduleInvalid();
        }

        if ($raffle->starts_at !== null && $raffle->starts_at->greaterThan($now)) {
            throw OrderConflictException::raffleNotStarted();
        }

        if ($raffle->ends_at !== null && $raffle->ends_at->lessThan($now)) {
            throw OrderConflictException::raffleEnded();
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function assertValidCount(array $numbers): void
    {
        $max = max(1, (int) config('rifacloud.order_max_numbers'));

        if (count($numbers) < 1) {
            throw ValidationException::withMessages([
                'numbers' => 'Debes seleccionar al menos un número.',
            ]);
        }

        if (count($numbers) > $max) {
            throw ValidationException::withMessages([
                'numbers' => "No puedes seleccionar más de {$max} números por orden.",
            ]);
        }
    }

    /**
     * @param  list<int>  $numbers
     */
    private function assertNumbersInRange(Raffle $raffle, array $numbers): void
    {
        $outside = array_values(array_filter(
            $numbers,
            static fn (int $number): bool => $number < $raffle->min_number || $number > $raffle->max_number,
        ));

        if ($outside !== []) {
            throw ValidationException::withMessages([
                'numbers' => 'Algunos números están fuera del rango de la rifa.',
            ]);
        }
    }

    /**
     * @param  list<int>  $numbers
     * @param  Collection<int, RaffleNumber>  $lockedNumbers
     */
    private function assertNumbersBelongToRaffle(array $numbers, Collection $lockedNumbers): void
    {
        $found = $lockedNumbers->pluck('number')->all();
        $missing = array_values(array_diff($numbers, $found));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'numbers' => 'Algunos números no pertenecen a esta rifa.',
            ]);
        }
    }

    /**
     * @param  list<int>  $numbers
     * @param  Collection<int, RaffleNumber>  $lockedNumbers
     */
    private function assertNumbersAvailable(array $numbers, Collection $lockedNumbers): void
    {
        $unavailable = $lockedNumbers
            ->filter(static fn (RaffleNumber $number): bool => $number->status !== NumberStatus::AVAILABLE)
            ->pluck('number')
            ->all();

        if ($unavailable !== []) {
            throw OrderConflictException::numbersUnavailable($unavailable);
        }
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = sprintf('RC-%s-%s', $date, Str::upper(Str::random(6)));

            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return sprintf('RC-%s-%s', $date, Str::upper((string) Str::ulid()));
    }
}
