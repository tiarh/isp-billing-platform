<?php

namespace App\Services;

use App\Contracts\WaProvider;

final class EvolutionBillingWaProvider implements WaProvider
{
    public function __construct(private EvolutionApiService $evolution) {}

    public function isConfigured(): bool
    {
        return $this->billing()->isConfigured();
    }

    public function connectionState(): string
    {
        return $this->billing()->getConnectionState();
    }

    /** @return array<string, mixed> */
    public function send(string $to, string $message): array
    {
        return $this->billing()->send($to, $message);
    }

    private function billing(): EvolutionApiService
    {
        return $this->evolution->forChannel('billing');
    }
}
