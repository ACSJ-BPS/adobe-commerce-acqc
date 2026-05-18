<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

// Good: use Magento's HTTP client which sets timeouts by default
class ApiClient
{
    public function __construct(
        private readonly \Magento\Framework\HTTP\Client\Curl $httpClient,
    ) {
    }

    public function fetch(string $url): string
    {
        $this->httpClient->setTimeout(30);
        $this->httpClient->get($url);
        return $this->httpClient->getBody();
    }
}
