<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FR-010: enqueue purchase confirmations and payment/refund incidents.
 *
 * The notifications table is owned by the scheduler/notification worker and
 * has UNIQUE(type, order_id), so running this command repeatedly is
 * idempotent. It never writes orders, payments or numbers.
 */
class QueueNotifications extends Command
{
    protected $signature = 'notifications:queue
                            {--limit=100 : Maximum notifications to enqueue per type}
                            {--purchase-days=2 : Only queue purchase confirmations paid within this many days}';

    protected $description = 'Queue purchase confirmations and payment/refund incident emails (idempotent)';

    public function handle(): int
    {
        if (! Schema::hasTable('notifications')) {
            $this->warn('The notifications migration is pending; queueing skipped.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $now = now();
        $purchaseSince = $now->copy()->subDays(max(1, (int) $this->option('purchase-days')));
        $queued = 0;

        $purchaseOrders = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->join('raffles as r', 'r.id', '=', 'o.raffle_id')
            ->where('o.status', 'PAID')
            ->whereNotNull('o.paid_at')
            ->where('o.paid_at', '>=', $purchaseSince)
            ->whereNotExists(static function ($query): void {
                $query->select(DB::raw(1))
                    ->from('notifications')
                    ->whereColumn('notifications.order_id', 'o.id')
                    ->where('notifications.type', 'PURCHASE_CONFIRMATION');
            })
            ->orderBy('o.id')
            ->limit($limit)
            ->get([
                'o.id',
                'o.order_number',
                'o.total_amount',
                'o.paid_at',
                'u.name',
                'u.email',
                'r.title as raffle_title',
            ]);

        foreach ($purchaseOrders as $order) {
            $numbers = DB::table('order_items as oi')
                ->join('raffle_numbers as n', 'n.id', '=', 'oi.raffle_number_id')
                ->where('oi.order_id', $order->id)
                ->orderBy('n.number')
                ->pluck('n.number')
                ->implode(', ');

            $body = implode("\n", array_filter([
                'Hola '.$order->name.',',
                '',
                'Tu compra fue confirmada.',
                'Orden: '.$order->order_number,
                'Rifa: '.$order->raffle_title,
                'Numeros: '.($numbers === '' ? 'sin numeros' : $numbers),
                'Total: $'.number_format((int) $order->total_amount, 0, ',', '.'),
                'Pagada: '.$order->paid_at,
                '',
                'Conserva este correo como comprobante. Puedes consultar el detalle en Mis compras.',
            ]));

            $queued += DB::table('notifications')->insertOrIgnore([
                'type' => 'PURCHASE_CONFIRMATION',
                'recipient_email' => $order->email,
                'subject' => 'Confirmacion de compra '.$order->order_number,
                'body' => $body,
                'order_id' => $order->id,
                'status' => 'PENDING',
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $incidentTypes = [
            'REQUIRES_REFUND' => 'REFUND_INCIDENT',
            'PAYMENT_REVIEW' => 'PAYMENT_INCIDENT',
        ];

        foreach ($incidentTypes as $orderStatus => $notificationType) {
            $incidents = DB::table('orders as o')
                ->join('users as u', 'u.id', '=', 'o.user_id')
                ->join('raffles as r', 'r.id', '=', 'o.raffle_id')
                ->where('o.status', $orderStatus)
                ->whereNotExists(static function ($query) use ($notificationType): void {
                    $query->select(DB::raw(1))
                        ->from('notifications')
                        ->whereColumn('notifications.order_id', 'o.id')
                        ->where('notifications.type', $notificationType);
                })
                ->orderBy('o.id')
                ->limit($limit)
                ->get([
                    'o.id',
                    'o.order_number',
                    'o.status',
                    'u.name',
                    'u.email',
                    'r.title as raffle_title',
                ]);

            foreach ($incidents as $order) {
                $isRefund = $orderStatus === 'REQUIRES_REFUND';
                $type = $notificationType;

                $body = implode("\n", [
                    'Hola '.$order->name.',',
                    '',
                    $isRefund
                        ? 'Tu pago fue confirmado, pero la orden requiere un reembolso manual.'
                        : 'El resultado de tu pago es incierto y la orden quedo en revision.',
                    'Orden: '.$order->order_number,
                    'Rifa: '.$order->raffle_title,
                    '',
                    $isRefund
                        ? 'Un administrador gestionara el reembolso. No se realizara ningun cobro adicional.'
                        : 'Los numeros siguen reservados. Un administrador revisara el caso; no se realizara ningun cobro adicional.',
                    '',
                    'Puedes consultar el estado en Mis compras.',
                ]);

                $queued += DB::table('notifications')->insertOrIgnore([
                    'type' => $type,
                    'recipient_email' => $order->email,
                    'subject' => ($isRefund ? 'Reembolso pendiente ' : 'Revision de pago ').$order->order_number,
                    'body' => $body,
                    'order_id' => $order->id,
                    'status' => 'PENDING',
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->info('Notifications queued: '.$queued);

        return self::SUCCESS;
    }
}
