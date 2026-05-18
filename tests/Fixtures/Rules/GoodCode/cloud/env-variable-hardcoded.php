<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Good: credentials read from environment variables, never hardcoded
class PaymentConfig
{
    public function getClient(): array
    {
        return [
            'api_key'    => (string) getenv('VENDOR_PAYMENT_API_KEY'),
            'api_secret' => (string) getenv('VENDOR_PAYMENT_API_SECRET'),
        ];
    }
}
