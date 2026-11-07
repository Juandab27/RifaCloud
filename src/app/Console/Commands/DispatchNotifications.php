<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * FR-010/NFR-005/NFR-006: deliver queued notifications with bounded retries.
 *
 * A mail failure only changes the notification row. It never alters an order,
 * a payment or a number. The local/test environment uses the log/array mailer;
 * no real email is required for validation.
 */
class DispatchNotifications extends Command
{
    private const MAX_ATTEMPTS = 5;

    private const RETRY_AFTER_MINUTES = 5;

    protected $signature = 'notifications:dispatch {--limit=50 : Maximum notifications to attempt}';

    protected $description = 'Deliver queued email notifications with retries (mail failure never affects purchases)';

    public function handle(): int
    {
        if (! Schema::hasTable('notifications')) {
            $this->warn('The notifications migration is pending; dispatch skipped.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $now = now();

        $due = DB::table('notifications')
            ->whereIn('status', ['PENDING', 'RETRY'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('last_attempt_at')
                    ->orWhere('last_attempt_at', '<=', $now->copy()->subMinutes(self::RETRY_AFTER_MINUTES));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($due as $notification) {
            $attempt = (int) $notification->attempts + 1;

            DB::table('notifications')
                ->where('id', $notification->id)
                ->update([
                    'last_attempt_at' => $now,
                    'updated_at' => $now,
                ]);

            try {
                Mail::raw($notification->body, static function ($message) use ($notification): void {
                    $message->to($notification->recipient_email)
                        ->subject($notification->subject);
                });

                DB::table('notifications')
                    ->where('id', $notification->id)
                    ->update([
                        'status' => 'SENT',
                        'attempts' => $attempt,
                        'sent_at' => $now,
                        'error_message' => null,
                        'updated_at' => $now,
                    ]);

                $sent++;
            } catch (Throwable $exception) {
                $status = $attempt >= self::MAX_ATTEMPTS ? 'FAILED' : 'RETRY';

                DB::table('notifications')
                    ->where('id', $notification->id)
                    ->update([
                        'status' => $status,
                        'attempts' => $attempt,
                        // Class names only: the SMTP/server message may contain
                        // addresses or host details and must not be persisted.
                        'error_message' => $exception::class,
                        'updated_at' => $now,
                    ]);

                Log::warning('Notification delivery failed.', [
                    'notification_id' => (int) $notification->id,
                    'type' => (string) $notification->type,
                    'attempt' => $attempt,
                    'status' => $status,
                    'exception' => $exception::class,
                ]);

                $failed++;
            }
        }

        $this->info('Notifications sent: '.$sent.' failed: '.$failed);

        return self::SUCCESS;
    }
}
