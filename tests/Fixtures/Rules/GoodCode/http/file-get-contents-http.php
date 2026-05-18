<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Exception\LocalizedException;

// Good: use Magento HTTP client with proper timeout and error handling
class RemoteConfig
{
    public function __construct(private readonly Curl $httpClient)
    {
    }

    public function loadConfig(): array
    {
        $this->httpClient->setTimeout(10);
        $this->httpClient->get('https://config.example.com/settings.json');
        if ($this->httpClient->getStatus() !== 200) {
            throw new LocalizedException(__('Failed to load remote config'));
        }
        return json_decode($this->httpClient->getBody(), true) ?? [];
    }
}
