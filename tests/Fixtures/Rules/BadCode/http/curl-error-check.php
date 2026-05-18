<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Bad: curl_exec result assigned to variable but curl_errno() never checked
class PaymentGateway
{
    public function charge(float $amount): array
    {
        $ch = curl_init('https://payment.example.com/charge');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $response, true) ?? [];
    }
}
