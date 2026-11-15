<?php

declare(strict_types=1);

namespace RifaCloud\RafflesService\Proposals;

use RifaCloud\RafflesService\RafflePresenter;

/**
 * Two deliberate projections:
 *  - public: only what the proposer already knows; no contact data.
 *  - admin: full contact, consent and moderation evidence for ADMIN users.
 */
final class ProposalPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function public(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
            'created_at' => RafflePresenter::iso8601($row['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function admin(array $row): array
    {
        $decided = $row['status'] !== 'PENDING';

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
            'contact' => [
                'name' => (string) $row['contact_name'],
                'email' => (string) $row['contact_email'],
                'phone' => (string) $row['contact_phone'],
            ],
            'privacy_version' => (string) $row['privacy_version'],
            'privacy_accepted_at' => RafflePresenter::iso8601($row['privacy_accepted_at'] ?? null),
            'decision' => $decided ? [
                'note' => $row['decision_note'] === null
                    ? null
                    : (string) $row['decision_note'],
                'decided_at' => RafflePresenter::iso8601($row['decided_at'] ?? null),
                'by' => $row['decided_by'] === null ? null : [
                    'id' => (int) $row['decided_by'],
                    'name' => $row['decided_by_name'] === null
                        ? null
                        : (string) $row['decided_by_name'],
                    'email' => $row['decided_by_email'] === null
                        ? null
                        : (string) $row['decided_by_email'],
                ],
            ] : null,
            'created_at' => RafflePresenter::iso8601($row['created_at'] ?? null),
            'updated_at' => RafflePresenter::iso8601($row['updated_at'] ?? null),
        ];
    }
}
