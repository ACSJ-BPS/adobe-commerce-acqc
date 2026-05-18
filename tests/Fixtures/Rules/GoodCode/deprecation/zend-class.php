<?php

declare(strict_types=1);

namespace Vendor\Module\Model;

use Magento\Framework\Mail\Template\TransportBuilderInterface;

class ModernTransport
{
    public function __construct(private readonly TransportBuilderInterface $transportBuilder)
    {
    }

    public function send(string $templateId, array $vars): void
    {
        // Good: use Magento mail transport abstraction
        $transport = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateVars($vars)
            ->setTemplateOptions(['area' => 'frontend', 'store' => 1])
            ->setFromByScope('general')
            ->addTo('customer@example.com')
            ->getTransport();
        $transport->sendMessage();
    }
}
