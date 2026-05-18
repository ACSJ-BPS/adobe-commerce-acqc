<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\Exception\LocalizedException;

// Good: no direct curl_exec usage — error handling delegated to HTTP client abstraction
class PaymentGateway
{
    public function __construct(
        private readonly \Magento\Framework\HTTP\Client\Curl $httpClient,
    ) {
    }

    public function charge(float $amount): array
    {
        try {
            $this->httpClient->setTimeout(30);
            $this->httpClient->post('https://payment.example.com/charge', ['amount' => $amount]);
            $statusCode = $this->httpClient->getStatus();
            if ($statusCode !== 200) {
                throw new LocalizedException(__('Payment gateway error: HTTP %1', $statusCode));
            }
            return json_decode($this->httpClient->getBody(), true) ?? [];
        } catch (\Exception $e) {
            throw new LocalizedException(__('Payment failed: %1', $e->getMessage()));
        }
    }
}
