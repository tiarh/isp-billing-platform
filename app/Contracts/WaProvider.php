<?php

namespace App\Contracts;

interface WaProvider
{
    public function isConfigured(): bool;

    public function connectionState(): string;

    /** @return array<string, mixed> */
    public function send(string $to, string $message): array;
}
