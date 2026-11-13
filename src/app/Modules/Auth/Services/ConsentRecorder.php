<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * S11: records the exact consent decision made at registration.
 *
 * Evidence is kept per type and document version. The recorder does not store
 * IP address or user agent: version, user id and timestamp are enough for the
 * prototype and keep personal data to the minimum required (NFR-015).
 *
 * During the S11 rollout the `user_consents` migration may not be applied to
 * the main schema yet. In that case registration still succeeds (the checkbox
 * is still mandatory), but no row is written and a warning is logged. This is
 * an explicit, documented gap until the additive migration is authorized.
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
