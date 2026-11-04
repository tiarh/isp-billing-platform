<?php

namespace App\Services\PaymentGateway;

use App\Contracts\PaymentGateway;
use App\Models\Setting;
use App\Services\PaymentGateway\Drivers\DuitkuGateway;
use App\Services\PaymentGateway\Drivers\MidtransGateway;
use Closure;
use InvalidArgumentException;

/**
 * Resolves payment gateway drivers and the one active provider.
 *
 * Setting payment_gateway_active: "duitku" | "midtrans" | "none".
 * When the setting has never been saved, Midtrans stays active (previous
 * behaviour) so deploying this class changes nothing on its own.
 */
class PaymentGatewayManager
{
    public const SETTING_ACTIVE = 'payment_gateway_active';

    public const NONE = 'none';

    /** @var array<string, Closure(): PaymentGateway> */
    private array $resolvers = [];

    /** @var array<string, PaymentGateway> */
    private array $drivers = [];

    public function __construct(private readonly PaymentWriteGate $gate)
    {
        $this->extend('duitku', fn (): PaymentGateway => app(DuitkuGateway::class));
        $this->extend('midtrans', fn (): PaymentGateway => app(MidtransGateway::class));
    }

    /**
     * @param  Closure(): PaymentGateway  $resolver
     */
    public function extend(string $provider, Closure $resolver): void
    {
        $provider = strtolower($provider);
        $this->resolvers[$provider] = $resolver;
        unset($this->drivers[$provider]);
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->resolvers);
    }

    public function has(string $provider): bool
    {
        return isset($this->resolvers[strtolower($provider)]);
    }

    public function driver(string $provider): PaymentGateway
    {
        $provider = strtolower($provider);
        if (! isset($this->resolvers[$provider])) {
            throw new InvalidArgumentException("Unknown payment gateway [{$provider}].");
        }

        return $this->drivers[$provider] ??= ($this->resolvers[$provider])();
    }

    /** Provider chosen in settings, or null when none/unknown. */
    public function activeProvider(): ?string
    {
        $value = strtolower(trim((string) Setting::get(self::SETTING_ACTIVE, 'midtrans')));

        return $value !== '' && $value !== self::NONE && $this->has($value) ? $value : null;
    }

    /** Active provider driver when it has credentials. */
    public function active(): ?PaymentGateway
    {
        $provider = $this->activeProvider();
        if ($provider === null) {
            return null;
        }

        $driver = $this->driver($provider);

        return $driver->isConfigured() ? $driver : null;
    }

    /**
     * Customers may start an online payment right now.
     *
     * With $customerId the pilot allowlist (payment_gateway_pilot_customer_ids)
     * also applies: a customer who is not listed gets false (manual transfer
     * fallback). Without it the answer is global. Midtrans is never filtered,
     * and callbacks never ask this method.
     */
    public function onlineAvailable(?int $customerId = null): bool
    {
        if ($this->active() === null || ! $this->gate->enabled()) {
            return false;
        }

        return $customerId === null || $this->pilotAllowsStart($customerId);
    }

    /** Pilot allowlist for starting a payment (direct providers only; Midtrans unaffected). */
    public function pilotAllowsStart(?int $customerId): bool
    {
        if ($this->isActive('midtrans')) {
            return true;
        }

        return app(PaymentGatewaySettings::class)->pilotAllows($customerId);
    }

    public function isActive(string $provider): bool
    {
        return $this->activeProvider() === strtolower($provider);
    }
}
