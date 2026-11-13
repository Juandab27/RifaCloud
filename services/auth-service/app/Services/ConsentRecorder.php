<?php

namespace RifaCloud\AuthService\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * S11: records the exact consent decision made at registration.
 *
 * auth-service owns `users`; `user_consents` is an auth-owned audit table. The
 * recorder stores only type, document version, decision, timestamp, source and
 * user id (no IP address or user agent), keeping the evidence to the minimum
 * needed by the prototype (NFR-015).
 */
final class ConsentRecorder
{
    public const TYPE_PRIVACY = 'PRIVACY';

    public const TYPE_TERMS = 'TERMS';

    public const TYPE_MARKETING = 'MARKETING';

    public function record(
        int $userId,
        bool $privacyAccepted,
        bool $termsAccepted,
        bool $marketingAccepted,
        string $version,
        string $source = 'REGISTRATION',
    ): bool {
        if (! Schema::hasTable('user_consents')) {
            Log::warning('Consent evidence table is pending; registration consent was not persisted.', [
                'user_id' => $userId,
                'version' => $version,
            ]);

            return false;
        }

        $now = now();

        $rows = [
            $this->row($userId, self::TYPE_PRIVACY, $privacyAccepted, $version, $source, $now),
            $this->row($userId, self::TYPE_TERMS, $termsAccepted, $version, $source, $now),
            $this->row($userId, self::TYPE_MARKETING, $marketingAccepted, $version, $source, $now),
        ];

        DB::table('user_consents')->insertOrIgnore($rows);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        int $userId,
        string $type,
        bool $accepted,
        string $version,
        string $source,
        mixed $now,
    ): array {
        return [
            'user_id' => $userId,
            'type' => $type,
            'document_version' => $version,
            'accepted' => $accepted,
            'accepted_at' => $now,
            'source' => $source,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
