<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Admin;

use PDO;

/**
 * Business rules ported one-to-one from App\Modules\Raffles\Services\RaffleService.
 *
 * The only structural difference is that this service writes `raffles` and
 * AVAILABLE `raffle_numbers` directly instead of through Eloquent. Status
 * transitions, schedule validation and the reserved/sold guard are preserved.
 */
final class RaffleAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RaffleAdminRepository $repository,
        private readonly RaffleValidator $validator,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $page): array
    {
        return $this->repository->paginate($page);
    }

    /**
     * Read-only metrics for the admin dashboard.
     *
     * @return array{published_raffles: int}
     */
    public function metrics(): array
    {
        return [
            'published_raffles' => $this->repository->countPublished(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function show(int $raffleId): array
    {
        $raffle = $this->repository->find($raffleId);

        if ($raffle === null) {
            throw new NotFoundException();
        }

        return $raffle;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function create(array $input, AdminSession $admin): array
    {
        $data = $this->validator->validate($input);
        $this->validator->assertValidSchedule(
            $data['starts_at'] ?? null,
            $data['ends_at'] ?? null,
        );

        $raffleId = $this->transaction(function () use ($data, $admin): int {
            $raffleId = $this->repository->insert($data, $admin->userId);
            $this->repository->generateNumbers(
                $raffleId,
                (int) $data['min_number'],
                (int) $data['max_number'],
            );

            return $raffleId;
        });

        return $this->show($raffleId);
    }

    /**
     * FR-014: publish the manually verified winner and close the raffle.
     *
     * Only a CLOSED raffle can receive its single result. The number must be
     * in range and must have been marked SOLD by orders-service; this service
     * writes only raffle_results and the raffle status, never number state.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function publishResult(int $raffleId, array $input, AdminSession $admin): array
    {
        $number = filter_var($input['winning_number'] ?? null, FILTER_VALIDATE_INT);

        if ($number === false || $number < 1) {
            throw ValidationException::single(
                'winning_number',
                'The winning number must be a positive integer.',
            );
        }

        $evidence = trim((string) ($input['evidence_description'] ?? ''));

        if ($evidence === '' || mb_strlen($evidence) > 2000) {
            throw ValidationException::single(
                'evidence_description',
                'Evidence description is required and must not exceed 2000 characters.',
            );
        }

        $url = trim((string) ($input['evidence_url'] ?? ''));

        if (
            $url !== ''
            && (mb_strlen($url) > 500 || filter_var($url, FILTER_VALIDATE_URL) === false)
        ) {
            throw ValidationException::single(
                'evidence_url',
                'Evidence URL must be a valid URL of at most 500 characters.',
            );
        }

        $this->transaction(function () use ($raffleId, $number, $evidence, $url, $admin): void {
            if (! $this->repository->raffleResultsAvailable()) {
                throw ValidationException::single(
                    'result',
                    'Result publishing is temporarily unavailable: the pending migration has not been applied.',
                );
            }

            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                throw new NotFoundException();
            }

            $this->transition((string) $raffle['status'], 'FINISHED');

            if ($number < (int) $raffle['min_number'] || $number > (int) $raffle['max_number']) {
                throw ValidationException::single(
                    'winning_number',
                    'The winning number is outside the raffle range.',
                );
            }

            if ($this->repository->findResult($raffleId) !== null) {
                throw ValidationException::single(
                    'result',
                    'A result has already been published for this raffle.',
                );
            }

            $raffleNumberId = $this->repository->findSoldNumber($raffleId, $number);

            if ($raffleNumberId === null) {
                throw ValidationException::single(
                    'winning_number',
                    'The winning number must be sold and belong to this raffle.',
                );
            }

            $this->repository->insertResult(
                $raffleId,
                $number,
                $raffleNumberId,
                $admin->userId,
                $evidence,
                $url === '' ? null : $url,
            );
            $this->repository->updateStatus($raffleId, 'FINISHED');
        });

        return $this->show($raffleId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(int $raffleId, array $input): array
    {
        $data = $this->validator->validate($input);

        $this->transaction(function () use ($raffleId, $data): void {
            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                throw new NotFoundException();
            }

            if ((string) $raffle['status'] !== 'DRAFT') {
                throw ValidationException::single(
                    'status',
                    'Only DRAFT raffles can be reconfigured.',
                );
            }

            $effectiveStartsAt = array_key_exists('starts_at', $data)
                ? $data['starts_at']
                : $raffle['starts_at'];
            $effectiveEndsAt = array_key_exists('ends_at', $data)
                ? $data['ends_at']
                : $raffle['ends_at'];

            $this->validator->assertValidSchedule(
                $effectiveStartsAt === null ? null : (string) $effectiveStartsAt,
                $effectiveEndsAt === null ? null : (string) $effectiveEndsAt,
            );

            $rangeChanged = (array_key_exists('min_number', $data)
                    && (int) $data['min_number'] !== (int) $raffle['min_number'])
                || (array_key_exists('max_number', $data)
                    && (int) $data['max_number'] !== (int) $raffle['max_number']);

            if ($rangeChanged && $this->repository->hasAssignedNumbers($raffleId)) {
                throw ValidationException::single(
                    'min_number',
                    'The number range cannot change after numbers are reserved or sold.',
                );
            }

            $this->repository->update($raffleId, $data);

            if ($rangeChanged) {
                $this->repository->deleteNumbers($raffleId);
                $this->repository->generateNumbers(
                    $raffleId,
                    (int) ($data['min_number'] ?? $raffle['min_number']),
                    (int) ($data['max_number'] ?? $raffle['max_number']),
                );
            }
        });

        return $this->show($raffleId);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function delete(int $raffleId): void
    {
        $this->transaction(function () use ($raffleId): void {
            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                throw new NotFoundException();
            }

            if ((string) $raffle['status'] !== 'DRAFT') {
                throw ValidationException::single(
                    'status',
                    'Only DRAFT raffles can be deleted.',
                );
            }

            if ($this->repository->hasAssignedNumbers($raffleId)) {
                throw ValidationException::single(
                    'status',
                    'Raffles with reserved or sold numbers cannot be deleted.',
                );
            }

            // The raffle_numbers FK is ON DELETE CASCADE; only AVAILABLE rows
            // can exist at this point because of the guard above.
            $this->repository->delete($raffleId);
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function publish(int $raffleId): array
    {
        $this->transaction(function () use ($raffleId): void {
            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                throw new NotFoundException();
            }

            $this->validator->assertValidSchedule(
                $raffle['starts_at'] === null ? null : (string) $raffle['starts_at'],
                $raffle['ends_at'] === null ? null : (string) $raffle['ends_at'],
            );

            $this->transition((string) $raffle['status'], 'PUBLISHED');
            $this->repository->updateStatus($raffleId, 'PUBLISHED');
        });

        return $this->show($raffleId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function close(int $raffleId): array
    {
        $this->transaction(function () use ($raffleId): void {
            $raffle = $this->repository->findForUpdate($raffleId);

            if ($raffle === null) {
                throw new NotFoundException();
            }

            $this->transition((string) $raffle['status'], 'CLOSED');
            $this->repository->updateStatus($raffleId, 'CLOSED');
        });

        return $this->show($raffleId);
    }

    /**
     * @throws ValidationException
     */
    private function transition(string $from, string $target): void
    {
        $allowed = match ($from) {
            'DRAFT' => ['PUBLISHED'],
            'PUBLISHED' => ['CLOSED'],
            'CLOSED' => ['FINISHED'],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::single(
                'status',
                "Cannot transition raffle from {$from} to {$target}.",
            );
        }
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
