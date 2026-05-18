<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: hardcoded credentials in application code — exposed in version control
class PaymentConfig
{
    public function getClient(): array
    {
        $password = 'hardcoded_db_password_123';
        $api_key  = 'sk_live_abc123secretapikey';
        $secret   = 'whsec_hardcoded_webhook_secret';
        $apikey   = 'another_hardcoded_key_xyz789';

        return [
            'api_key' => $api_key,
            'secret'  => $secret,
        ];
    }
}
