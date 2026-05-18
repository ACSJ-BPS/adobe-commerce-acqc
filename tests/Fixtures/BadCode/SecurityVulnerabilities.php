<?php

declare(strict_types=1);

namespace TestFixtures\BadCode;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Test fixture containing various security vulnerabilities
 * 
 * This class intentionally contains security issues for testing purposes:
 * - SQL Injection vulnerabilities
 * - XSS vulnerabilities 
 * - Path traversal issues
 * - Insecure direct object references
 * - Missing input validation
 * - Hardcoded credentials
 * - Information disclosure
 */
class SecurityVulnerabilities extends Action
{
    private AdapterInterface $connection;
    private JsonFactory $resultJsonFactory;
    
    // VIOLATION: Hardcoded credentials
    private const DB_PASSWORD = 'admin123';
    private const API_KEY = 'sk-1234567890abcdef';

    public function __construct(
        Context $context,
        AdapterInterface $connection,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->connection = $connection;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * VIOLATION: SQL Injection vulnerability
     * Direct concatenation of user input into SQL query
     */
    public function getUserData()
    {
        $userId = $this->getRequest()->getParam('user_id'); // No validation
        
        // CRITICAL: SQL Injection vulnerability
        $sql = "SELECT * FROM users WHERE user_id = " . $userId;
        $result = $this->connection->fetchAll($sql);
        
        return $result;
    }

    /**
     * VIOLATION: XSS vulnerability 
     * Outputting user input without escaping
     */
    public function displayUserComment()
    {
        $comment = $this->getRequest()->getParam('comment');
        
        // CRITICAL: XSS vulnerability - no escaping
        echo "<div class='comment'>" . $comment . "</div>";
    }

    /**
     * VIOLATION: Path traversal vulnerability
     * Using user input to construct file paths
     */
    public function downloadFile()
    {
        $filename = $this->getRequest()->getParam('file');
        
        // CRITICAL: Path traversal vulnerability
        $filePath = '/var/www/uploads/' . $filename;
        
        if (file_exists($filePath)) {
            readfile($filePath);
        }
    }

    /**
     * VIOLATION: Insecure direct object reference
     * No authorization check before accessing user data
     */
    public function getUserProfile()
    {
        $profileId = $this->getRequest()->getParam('profile_id');
        
        // VIOLATION: No ownership/permission check
        $sql = "SELECT * FROM user_profiles WHERE id = ?";
        $profile = $this->connection->fetchRow($sql, [$profileId]);
        
        return $profile;
    }

    /**
     * VIOLATION: Missing input validation
     * No validation of email format or content
     */
    public function sendEmail()
    {
        $email = $this->getRequest()->getParam('email');
        $subject = $this->getRequest()->getParam('subject');
        $message = $this->getRequest()->getParam('message');
        
        // VIOLATION: No input validation
        // Could lead to email header injection
        mail($email, $subject, $message);
    }

    /**
     * VIOLATION: Information disclosure
     * Exposing sensitive system information
     */
    public function getSystemInfo()
    {
        $info = [
            'php_version' => phpversion(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'],
            'document_root' => $_SERVER['DOCUMENT_ROOT'],
            'database_host' => 'localhost',
            'database_user' => 'root',
            'api_keys' => [
                'payment_gateway' => self::API_KEY,
                'third_party_service' => 'secret123'
            ]
        ];
        
        return $this->resultJsonFactory->create()->setData($info);
    }

    /**
     * VIOLATION: Weak cryptography
     * Using MD5 for password hashing
     */
    public function hashPassword($password)
    {
        // VIOLATION: MD5 is cryptographically weak
        return md5($password);
    }

    /**
     * VIOLATION: Session management issues
     * Not regenerating session ID after login
     */
    public function loginUser($username, $password)
    {
        // Validate credentials (simplified)
        if ($this->validateCredentials($username, $password)) {
            $_SESSION['user_id'] = $this->getUserId($username);
            $_SESSION['logged_in'] = true;
            // VIOLATION: Not regenerating session ID
            return true;
        }
        return false;
    }

    /**
     * VIOLATION: Command injection
     * Using user input in system commands
     */
    public function resizeImage()
    {
        $filename = $this->getRequest()->getParam('filename');
        $width = $this->getRequest()->getParam('width');
        
        // CRITICAL: Command injection vulnerability
        $command = "convert $filename -resize {$width}x{$width} output.jpg";
        system($command);
    }

    /**
     * VIOLATION: Inadequate logging
     * Not logging security-sensitive operations
     */
    public function deleteUser()
    {
        $userId = $this->getRequest()->getParam('user_id');
        
        // VIOLATION: No logging of deletion operation
        $sql = "DELETE FROM users WHERE id = ?";
        $this->connection->query($sql, [$userId]);
        
        return true;
    }

    /**
     * VIOLATION: Weak random number generation
     * Using predictable random functions
     */
    public function generateToken()
    {
        // VIOLATION: rand() is not cryptographically secure
        return md5(rand() . time());
    }

    /**
     * VIOLATION: Missing CSRF protection
     * State-changing operation without CSRF token
     */
    public function updateUserSettings()
    {
        $settings = $this->getRequest()->getParams();
        
        // VIOLATION: No CSRF token validation
        foreach ($settings as $key => $value) {
            $this->updateUserSetting($key, $value);
        }
    }

    /**
     * Helper methods (simplified implementations)
     */
    private function validateCredentials($username, $password): bool
    {
        // Simplified validation
        return !empty($username) && !empty($password);
    }

    private function getUserId($username): int
    {
        $sql = "SELECT id FROM users WHERE username = ?";
        $result = $this->connection->fetchOne($sql, [$username]);
        return (int)$result;
    }

    private function updateUserSetting($key, $value): void
    {
        // Simplified implementation
        $sql = "UPDATE user_settings SET value = ? WHERE key = ?";
        $this->connection->query($sql, [$value, $key]);
    }
} 