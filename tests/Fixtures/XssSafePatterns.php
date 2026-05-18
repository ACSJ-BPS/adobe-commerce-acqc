<?php

declare(strict_types=1);

namespace ACQC\Tests\Rules;

/**
 * Test cases for XSS detection improvements
 * 
 * This file contains code patterns that should NOT be flagged as XSS vulnerabilities
 * after the security analyzer improvements.
 */

use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Magento\Store\Model\Store;

class SafeCodePatterns extends Template
{
    /**
     * SAFE: Returns collection object, not output
     * Should NOT be flagged as XSS
     */
    public function getInvoices(): Collection
    {
        $collection = $this->collectionFactory->create();
        return $collection;  // Line similar to your line 78
    }

    /**
     * SAFE: Uses getUrl() with typed integer parameter
     * Should NOT be flagged as XSS
     */
    public function getDisputeUrl(int $invoiceId): string
    {
        // Line similar to your line 89
        return $this->getUrl('invoicedispute/index/form', ['invoice_id' => $invoiceId]);
    }

    /**
     * SAFE: Uses currency format with typed float parameter
     * Should NOT be flagged as XSS
     */
    public function formatPrice(float $price): string
    {
        /** @var Store $store */
        $store = $this->_storeManager->getStore();
        // Line similar to your line 102
        return $store->getCurrentCurrency()->format($price, [], false);
    }

    /**
     * SAFE: Returns integer ID
     * Should NOT be flagged as XSS
     */
    public function getCustomerId(): int
    {
        return $this->customerSession->getCustomerId();
    }

    /**
     * SAFE: Returns integer with type cast
     * Should NOT be flagged as XSS
     */
    public function getProductId(): int
    {
        return (int) $this->getRequest()->getParam('id');
    }

    /**
     * SAFE: Returns float with type cast
     * Should NOT be flagged as XSS
     */
    public function getAmount(): float
    {
        return (float) $this->getData('amount');
    }

    /**
     * SAFE: Returns boolean
     * Should NOT be flagged as XSS
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * SAFE: Echo with integer literal
     * Should NOT be flagged as XSS
     */
    public function displayCount(): void
    {
        echo 42;
    }

    /**
     * SAFE: Echo with float literal
     * Should NOT be flagged as XSS
     */
    public function displayPrice(): void
    {
        echo 19.99;
    }

    /**
     * SAFE: Echo with string literal
     * Should NOT be flagged as XSS
     */
    public function displayMessage(): void
    {
        echo "Hello World";
    }

    /**
     * SAFE: Echo with type cast to int
     * Should NOT be flagged as XSS
     */
    public function displayId(string $value): void
    {
        echo (int) $value;
    }

    /**
     * SAFE: Echo with escaped content
     * Should NOT be flagged as XSS
     */
    public function displaySafeName(): void
    {
        echo htmlspecialchars($this->getName());
    }

    /**
     * SAFE: Echo with JSON encoding
     * Should NOT be flagged as XSS
     */
    public function displayJson(): void
    {
        echo json_encode($this->getData());
    }

    /**
     * SAFE: Echo with safe method call
     * Should NOT be flagged as XSS
     */
    public function displayEntityId(): void
    {
        echo $this->getProduct()->getId();
    }

    /**
     * SAFE: Echo with collection count
     * Should NOT be flagged as XSS
     */
    public function displayCollectionSize(): void
    {
        echo $this->getCollection()->getCount();
    }
}

/**
 * These are REAL XSS vulnerabilities that SHOULD be flagged
 */
class UnsafeCodePatterns
{
    /**
     * UNSAFE: Direct user input without escaping
     * SHOULD be flagged as XSS
     */
    public function unsafeDirectInput(): void
    {
        echo $_GET['search'];  // VULNERABLE
    }

    /**
     * UNSAFE: Unescaped request parameter
     * SHOULD be flagged as XSS
     */
    public function unsafeRequestParam(): void
    {
        echo $this->getRequest()->getParam('name');  // VULNERABLE
    }

    /**
     * UNSAFE: Unescaped variable
     * SHOULD be flagged as XSS
     */
    public function unsafeVariable(): void
    {
        $userInput = $_POST['comment'];
        echo $userInput;  // VULNERABLE
    }

    /**
     * UNSAFE: Print with user input
     * SHOULD be flagged as XSS
     */
    public function unsafePrint(): void
    {
        print $_GET['message'];  // VULNERABLE
    }
}
