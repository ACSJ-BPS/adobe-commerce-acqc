<?php

declare(strict_types=1);

namespace TestFixtures\GoodCode;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Escaper;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;

/**
 * Example of well-written Magento 2 code following best practices
 * 
 * This class demonstrates:
 * - Proper dependency injection
 * - Input validation and sanitization
 * - CSRF protection
 * - Proper error handling
 * - Logging and monitoring
 * - Type hints and documentation
 * - Single responsibility principle
 * - Secure coding practices
 */
class BestPracticesExample extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_NAME_LENGTH = 255;
    private const MIN_NAME_LENGTH = 2;

    private JsonFactory $resultJsonFactory;
    private Validator $formKeyValidator;
    private LoggerInterface $logger;
    private CustomerRepositoryInterface $customerRepository;
    private Escaper $escaper;

    /**
     * Constructor with proper dependency injection
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Validator $formKeyValidator,
        LoggerInterface $logger,
        CustomerRepositoryInterface $customerRepository,
        Escaper $escaper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
        $this->customerRepository = $customerRepository;
        $this->escaper = $escaper;
    }

    /**
     * Execute action with proper error handling and validation
     */
    public function execute(): ResponseInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            // Validate request method
            if (!$this->getRequest()->isPost()) {
                throw new LocalizedException(__('Invalid request method'));
            }

            // Validate CSRF token
            if (!$this->formKeyValidator->validate($this->getRequest())) {
                throw new LocalizedException(__('Invalid form key'));
            }

            // Get and validate input data
            $customerData = $this->getValidatedCustomerData();

            // Process the customer update
            $updatedCustomer = $this->updateCustomer($customerData);

            // Log successful operation
            $this->logger->info('Customer updated successfully', [
                'customer_id' => $updatedCustomer->getId(),
                'user_agent' => $this->getRequest()->getHeader('User-Agent'),
                'remote_ip' => $this->getRequest()->getClientIp()
            ]);

            return $result->setData([
                'success' => true,
                'message' => __('Customer updated successfully'),
                'customer_id' => $updatedCustomer->getId()
            ]);

        } catch (ValidationException $e) {
            // Handle validation errors
            $this->logger->warning('Customer update validation failed', [
                'errors' => $e->getErrors(),
                'remote_ip' => $this->getRequest()->getClientIp()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('Validation failed'),
                'errors' => $e->getErrors()
            ]);

        } catch (LocalizedException $e) {
            // Handle business logic errors
            $this->logger->error('Customer update failed', [
                'error' => $e->getMessage(),
                'remote_ip' => $this->getRequest()->getClientIp()
            ]);

            return $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);

        } catch (\Exception $e) {
            // Handle unexpected errors
            $this->logger->critical('Unexpected error during customer update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'remote_ip' => $this->getRequest()->getClientIp()
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An unexpected error occurred')
            ]);
        }
    }

    /**
     * Validate and sanitize customer data from request
     * 
     * @throws ValidationException
     */
    private function getValidatedCustomerData(): array
    {
        $request = $this->getRequest();
        $errors = [];

        // Get raw input data
        $customerId = $request->getParam('customer_id');
        $firstName = $request->getParam('firstname');
        $lastName = $request->getParam('lastname');
        $email = $request->getParam('email');

        // Validate customer ID
        if (!$customerId || !is_numeric($customerId)) {
            $errors[] = __('Invalid customer ID');
        }

        // Validate first name
        if (empty($firstName)) {
            $errors[] = __('First name is required');
        } elseif (strlen($firstName) < self::MIN_NAME_LENGTH) {
            $errors[] = __('First name must be at least %1 characters', self::MIN_NAME_LENGTH);
        } elseif (strlen($firstName) > self::MAX_NAME_LENGTH) {
            $errors[] = __('First name cannot exceed %1 characters', self::MAX_NAME_LENGTH);
        } elseif (!$this->isValidName($firstName)) {
            $errors[] = __('First name contains invalid characters');
        }

        // Validate last name
        if (empty($lastName)) {
            $errors[] = __('Last name is required');
        } elseif (strlen($lastName) < self::MIN_NAME_LENGTH) {
            $errors[] = __('Last name must be at least %1 characters', self::MIN_NAME_LENGTH);
        } elseif (strlen($lastName) > self::MAX_NAME_LENGTH) {
            $errors[] = __('Last name cannot exceed %1 characters', self::MAX_NAME_LENGTH);
        } elseif (!$this->isValidName($lastName)) {
            $errors[] = __('Last name contains invalid characters');
        }

        // Validate email
        if (empty($email)) {
            $errors[] = __('Email is required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('Invalid email format');
        }

        // Throw exception if validation fails
        if (!empty($errors)) {
            throw new ValidationException(__('Validation failed'), null, 0, null, $errors);
        }

        // Return sanitized data
        return [
            'customer_id' => (int)$customerId,
            'firstname' => $this->escaper->escapeHtml(trim($firstName)),
            'lastname' => $this->escaper->escapeHtml(trim($lastName)),
            'email' => trim(strtolower($email))
        ];
    }

    /**
     * Update customer with validated data
     * 
     * @throws LocalizedException
     */
    private function updateCustomer(array $customerData): CustomerInterface
    {
        try {
            // Load existing customer
            $customer = $this->customerRepository->getById($customerData['customer_id']);

            // Update customer data
            $customer->setFirstname($customerData['firstname']);
            $customer->setLastname($customerData['lastname']);
            $customer->setEmail($customerData['email']);

            // Save customer
            return $this->customerRepository->save($customer);

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new LocalizedException(__('Customer not found'));
        } catch (\Exception $e) {
            $this->logger->error('Failed to update customer', [
                'customer_id' => $customerData['customer_id'],
                'error' => $e->getMessage()
            ]);
            throw new LocalizedException(__('Failed to update customer'));
        }
    }

    /**
     * Validate name contains only allowed characters
     */
    private function isValidName(string $name): bool
    {
        // Allow letters, spaces, hyphens, and apostrophes
        return preg_match('/^[a-zA-Z\s\-\']+$/', $name) === 1;
    }

    /**
     * CSRF validation for requests
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // Handle in execute method instead
    }

    /**
     * Validate against CSRF attacks
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }
}

/**
 * Example of a well-structured service class
 */
class CustomerAnalyticsService
{
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Get customer analytics with proper error handling and caching
     */
    public function getCustomerAnalytics(int $customerId): array
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            
            return [
                'id' => $customer->getId(),
                'name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                'email' => $customer->getEmail(),
                'created_at' => $customer->getCreatedAt(),
                'group_id' => $customer->getGroupId()
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get customer analytics', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            
            throw new LocalizedException(__('Unable to retrieve customer analytics'));
        }
    }
}

/**
 * Example of proper data transfer object
 */
class CustomerUpdateRequest
{
    private int $customerId;
    private string $firstName;
    private string $lastName;
    private string $email;

    public function __construct(
        int $customerId,
        string $firstName,
        string $lastName,
        string $email
    ) {
        $this->customerId = $customerId;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}

/**
 * Example of proper exception handling
 */
class CustomerValidationException extends LocalizedException
{
    private array $validationErrors;

    public function __construct(
        array $validationErrors,
        \Magento\Framework\Phrase $phrase = null,
        \Exception $cause = null
    ) {
        $this->validationErrors = $validationErrors;
        parent::__construct($phrase ?: __('Customer validation failed'), $cause);
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
} 