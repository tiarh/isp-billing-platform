<?php

namespace App\Services\PaymentGateway\Data;

final class PaymentMethodOption
{
    public const GROUP_VA = 'va';

    public const GROUP_QRIS = 'qris';

    public const GROUP_EWALLET = 'ewallet';

    public const GROUP_RETAIL = 'retail';

    public const GROUP_CARD = 'card';

    /** Provider hosted page that lets the customer choose there. */
    public const GROUP_HOSTED = 'hosted';

    public const GROUP_OTHER = 'other';

    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $group,
        public readonly int $fee = 0,
        public readonly ?string $imageUrl = null,
    ) {}

    /** transactions.method value for money received through this option. */
    public function transactionMethod(): string
    {
        return match ($this->group) {
            self::GROUP_VA, self::GROUP_QRIS, self::GROUP_EWALLET, self::GROUP_RETAIL, self::GROUP_CARD => $this->group,
            default => 'other',
        };
    }

    /**
     * @return array{code: string, name: string, group: string, fee: int, image_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'group' => $this->group,
            'fee' => $this->fee,
            'image_url' => $this->imageUrl,
        ];
    }
}
