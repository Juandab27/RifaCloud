<?php

namespace App\Providers;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakePaymentGateway;
use App\Modules\Payments\Gateways\MercadoPagoGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function (): PaymentGateway {
            $driver = (string) config('rifacloud.payment_gateway');

            return match ($driver) {
                'fake' => new FakePaymentGateway,
                'mercadopago' => new MercadoPagoGateway(
                    accessToken: (string) config('mercadopago.access_token'),
                    sandbox: (bool) config('mercadopago.sandbox'),
                    baseUrl: (string) config('mercadopago.base_url'),
                    timeout: (int) config('mercadopago.timeout'),
                    webhookSecret: (string) config('mercadopago.webhook_secret'),
                ),
                default => throw new InvalidArgumentException(
                    "Unsupported payment gateway driver [{$driver}].",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::assertPaymentGatewayIsAllowed(
            $this->app->environment(),
            (string) config('rifacloud.payment_gateway'),
        );
    }

    /**
     * The simulated gateway must never be able to take money in production.
     */
    public static function assertPaymentGatewayIsAllowed(string $environment, string $driver): void
    {
        if ($environment === 'production' && $driver === 'fake') {
            throw new RuntimeException(
                'PAYMENT_GATEWAY=fake is not allowed in production: the simulated gateway does not process real money. Configure a real payment gateway before deploying.',
            );
        }
    }
}
