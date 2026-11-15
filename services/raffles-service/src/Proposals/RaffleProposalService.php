<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RifaCloud\RafflesService\Admin\AdminSession;
use RifaCloud\RafflesService\Admin\NotFoundException;
use RifaCloud\RafflesService\Admin\ValidationException;

/**
 * Proposal submission and moderation rules.
 *
 * Approval is a moderation decision only: this service never creates a
 * raffle, order, payment or notification.
 */
final class RaffleProposalService
{
    public const DAILY_LIMIT = 3;

    public const COOLDOWN_SECONDS = 30;

    public const TITLE_MIN = 5;

    public const TITLE_MAX = 120;

    public const DESCRIPTION_MIN = 10;

    public const DESCRIPTION_MAX = 2000;

    public const NOTE_MAX = 1000;

    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED'];

    public function __construct(
        private readonly RaffleProposalRepository $repository,
        private readonly int $dailyLimit = self::DAILY_LIMIT,
        private readonly int $cooldownSeconds = self::COOLDOWN_SECONDS,
        private readonly string $privacyVersion = '2026-11-14',
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws ProposalRateLimitException
     */
    public function create(array $input, ProposalUser $user): array
    {
        $data = $this->validateCreate($input);

        $dailyLimit = max(1, $this->dailyLimit);
        $since = gmdate('Y-m-d H:i:s', time() - 86400);

        if ($this->repository->countSince($user->id, $since) >= $dailyLimit) {
            throw ProposalRateLimitException::daily(3600);
        }

        $lastCreatedAt = $this->repository->lastCreatedAt($user->id);

        if ($lastCreatedAt !== null && $this->cooldownSeconds > 0) {
            $last = strtotime($lastCreatedAt.' UTC');
            $elapsed = $last === false ? $this->cooldownSeconds : time() - $last;

            if ($elapsed >= 0 && $elapsed < $this->cooldownSeconds) {
                throw ProposalRateLimitException::cooldown(
                    max(1, $this->cooldownSeconds - $elapsed),
                );
            }
        }

        $id = $this->repository->create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            // Contact is copied from the authenticated users row, never from
            // the request body.
            'contact_name' => $user->name,
            'contact_email' => $user->email,
            'contact_phone' => $user->phone,
            'privacy_version' => $this->privacyVersion,
        ]);

        $row = $this->repository->find($id);

        if ($row === null) {
            throw new NotFoundException();
        }

        return ProposalPresenter::public($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function paginate(int $page, ?string $status): array
    {
        $this->assertStatus($status, allowNull: true);

        return $this->repository->paginate($page, $status);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function show(int $id): array
    {
        $row = $this->repository->find($id);

        if ($row === null) {
            throw new NotFoundException();
        }

        return ProposalPresenter::admin($row);
    }

    /**
     * @return array{pending: int, approved: int, rejected: int}
     */
    public function metrics(): array
    {
        return $this->repository->metrics();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ProposalConflictException
     */
    public function decide(
        int $id,
        string $status,
        array $input,
        AdminSession $admin,
    ): array {
        $this->assertStatus($status, allowNull: false);
        $note = $this->validateNote($input);

        $row = $this->repository->find($id);

        if ($row === null) {
            throw new NotFoundException();
        }

        if ((string) $row['status'] !== 'PENDING') {
            throw new ProposalConflictException();
        }

        if (! $this->repository->decide($id, $status, $admin->userId, $note)) {
            // A concurrent moderation request won the PENDING condition.
            throw new ProposalConflictException();
        }

        $updated = $this->repository->find($id);

        if ($updated === null) {
            throw new NotFoundException();
        }

        return ProposalPresenter::admin($updated);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, description: string}
     *
     * @throws ValidationException
     */
    private function validateCreate(array $input): array
    {
        $errors = [];

        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $titleLength = mb_strlen($title);

        if ($title === '') {
            $errors['title'][] = 'El título es obligatorio.';
        } elseif ($titleLength < self::TITLE_MIN) {
            $errors['title'][] = 'El título debe tener al menos '.self::TITLE_MIN.' caracteres.';
        } elseif ($titleLength > self::TITLE_MAX) {
            $errors['title'][] = 'El título no puede superar '.self::TITLE_MAX.' caracteres.';
        }

        $description = is_string($input['description'] ?? null)
            ? trim($input['description'])
            : '';
        $descriptionLength = mb_strlen($description);

        if ($description === '') {
            $errors['description'][] = 'La descripción es obligatoria.';
        } elseif ($descriptionLength < self::DESCRIPTION_MIN) {
            $errors['description'][] = 'La descripción debe tener al menos '
                .self::DESCRIPTION_MIN.' caracteres.';
        } elseif ($descriptionLength > self::DESCRIPTION_MAX) {
            $errors['description'][] = 'La descripción no puede superar '
                .self::DESCRIPTION_MAX.' caracteres.';
        }

        $accepted = $input['privacy_accepted'] ?? false;

        if (! in_array($accepted, [true, 1, '1', 'true'], true)) {
            $errors['privacy_accepted'][] = 'Debes aceptar el aviso de privacidad.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'description' => $description,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function validateNote(array $input): ?string
    {
        $note = $input['note'] ?? null;

        if ($note === null) {
            return null;
        }

        if (! is_string($note)) {
            throw ValidationException::single('note', 'La nota interna no es válida.');
        }

        $note = trim($note);

        if ($note === '') {
            return null;
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::single(
                'note',
                'La nota interna no puede superar '.self::NOTE_MAX.' caracteres.',
            );
        }

        return $note;
    }

    /**
     * @throws ValidationException
     */
    private function assertStatus(?string $status, bool $allowNull): void
    {
        if ($status === null && $allowNull) {
            return;
        }

        if ($status === null || ! in_array($status, self::STATUSES, true)) {
            throw ValidationException::single(
                'status',
                'El estado debe ser PENDING, APPROVED o REJECTED.',
            );
        }
    }
}
