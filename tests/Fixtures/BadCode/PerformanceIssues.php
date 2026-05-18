<?php

declare(strict_types=1);

namespace TestFixtures\BadCode;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Test fixture containing various performance issues
 * 
 * This class intentionally contains performance problems for testing:
 * - N+1 query problems
 * - Inefficient loops and algorithms
 * - Memory leaks
 * - Blocking operations
 * - Unoptimized database queries
 * - Large object creation in loops
 * - Missing caching
 * - Inefficient string operations
 */
class PerformanceIssues
{
    private ResourceConnection $resourceConnection;
    private ProductFactory $productFactory;
    private CollectionFactory $collectionFactory;
    private array $cache = [];

    public function __construct(
        ResourceConnection $resourceConnection,
        ProductFactory $productFactory,
        CollectionFactory $collectionFactory
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->productFactory = $productFactory;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * VIOLATION: N+1 Query Problem
     * Loading products one by one instead of batch loading
     */
    public function getProductDetails(array $productIds): array
    {
        $products = [];
        
        // VIOLATION: N+1 queries - one query per product
        foreach ($productIds as $productId) {
            $product = $this->productFactory->create()->load($productId);
            $products[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'categories' => $this->getProductCategories($productId), // Another query per product
                'reviews' => $this->getProductReviews($productId) // Yet another query per product
            ];
        }
        
        return $products;
    }

    /**
     * VIOLATION: Inefficient algorithm with O(n²) complexity
     * Nested loops for simple operations
     */
    public function findDuplicateProducts(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $products = $connection->fetchAll("SELECT id, sku, name FROM catalog_product_entity");
        
        $duplicates = [];
        
        // VIOLATION: O(n²) algorithm for duplicate detection
        for ($i = 0; $i < count($products); $i++) {
            for ($j = $i + 1; $j < count($products); $j++) {
                if ($products[$i]['sku'] === $products[$j]['sku']) {
                    $duplicates[] = [$products[$i], $products[$j]];
                }
            }
        }
        
        return $duplicates;
    }

    /**
     * VIOLATION: Memory leak with large objects in loops
     * Creating heavy objects without cleanup
     */
    public function processLargeDataSet(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $largeDataSet = $connection->fetchAll("SELECT * FROM sales_order_item"); // Could be millions of rows
        
        foreach ($largeDataSet as $item) {
            // VIOLATION: Creating large objects in loop without cleanup
            $processor = new HeavyDataProcessor();
            $processor->loadCompleteProductData($item['product_id']);
            $processor->loadCustomerHistory($item['order_id']);
            $processor->loadInventoryData($item['product_id']);
            
            // Process data
            $processor->processComplexCalculations();
            
            // VIOLATION: Not unsetting heavy objects - memory accumulates
            // unset($processor); // This should be done but isn't
        }
    }

    /**
     * VIOLATION: Inefficient string operations
     * Concatenating strings in loops
     */
    public function generateLargeReport(array $orderIds): string
    {
        $report = '';
        
        // VIOLATION: String concatenation in loop - very inefficient
        foreach ($orderIds as $orderId) {
            $orderData = $this->getOrderData($orderId);
            $report .= "Order ID: " . $orderId . "\n";
            $report .= "Customer: " . $orderData['customer_name'] . "\n";
            $report .= "Total: $" . $orderData['total'] . "\n";
            $report .= "Items:\n";
            
            foreach ($orderData['items'] as $item) {
                $report .= "  - " . $item['name'] . " (Qty: " . $item['qty'] . ")\n";
            }
            $report .= "\n" . str_repeat('-', 50) . "\n\n";
        }
        
        return $report;
    }

    /**
     * VIOLATION: Blocking synchronous operations
     * Making external API calls in loops without async
     */
    public function updateProductPrices(): void
    {
        $products = $this->getAllProducts();
        
        foreach ($products as $product) {
            // VIOLATION: Blocking operation in loop
            $externalPrice = $this->fetchPriceFromExternalAPI($product['sku']); // Takes 2-3 seconds each
            
            if ($externalPrice) {
                $this->updateProductPrice($product['id'], $externalPrice);
            }
            
            // VIOLATION: No batching, no async processing
        }
    }

    /**
     * VIOLATION: Unoptimized database query
     * Using functions in WHERE clause, no indexes
     */
    public function getOrdersByDateRange(string $startDate, string $endDate): array
    {
        $connection = $this->resourceConnection->getConnection();
        
        // VIOLATION: Using functions in WHERE clause prevents index usage
        $sql = "
            SELECT o.*, c.firstname, c.lastname 
            FROM sales_order o 
            LEFT JOIN customer_entity c ON o.customer_id = c.entity_id 
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            ORDER BY o.created_at DESC
        ";
        
        return $connection->fetchAll($sql, [$startDate, $endDate]);
    }

    /**
     * VIOLATION: Missing caching for expensive operations
     * Recalculating the same data multiple times
     */
    public function getCustomerAnalytics(int $customerId): array
    {
        // VIOLATION: No caching - same calculations repeated
        $totalOrders = $this->calculateTotalOrders($customerId);
        $totalSpent = $this->calculateTotalSpent($customerId); // Expensive calculation
        $averageOrderValue = $this->calculateAverageOrderValue($customerId);
        $preferredCategories = $this->calculatePreferredCategories($customerId); // Very expensive
        $seasonalTrends = $this->calculateSeasonalTrends($customerId); // Extremely expensive
        
        return [
            'total_orders' => $totalOrders,
            'total_spent' => $totalSpent,
            'average_order_value' => $averageOrderValue,
            'preferred_categories' => $preferredCategories,
            'seasonal_trends' => $seasonalTrends
        ];
    }

    /**
     * VIOLATION: Loading unnecessary data
     * Selecting all columns when only few are needed
     */
    public function getProductNames(): array
    {
        $connection = $this->resourceConnection->getConnection();
        
        // VIOLATION: SELECT * when only name is needed
        $products = $connection->fetchAll("
            SELECT * 
            FROM catalog_product_entity cpe
            LEFT JOIN catalog_product_entity_varchar cpev ON cpe.entity_id = cpev.entity_id
            LEFT JOIN eav_attribute ea ON cpev.attribute_id = ea.attribute_id
            WHERE ea.attribute_code = 'name'
        ");
        
        return array_column($products, 'value');
    }

    /**
     * VIOLATION: Inefficient sorting and filtering
     * Loading all data then filtering in PHP
     */
    public function getTopSellingProducts(int $limit = 10): array
    {
        $connection = $this->resourceConnection->getConnection();
        
        // VIOLATION: Loading all products then sorting in PHP
        $allProducts = $connection->fetchAll("
            SELECT p.entity_id, p.sku, SUM(oi.qty_ordered) as total_sold
            FROM catalog_product_entity p
            LEFT JOIN sales_order_item oi ON p.sku = oi.sku
            GROUP BY p.entity_id, p.sku
        ");
        
        // VIOLATION: Sorting in PHP instead of database
        usort($allProducts, function($a, $b) {
            return $b['total_sold'] <=> $a['total_sold'];
        });
        
        // VIOLATION: Limiting in PHP instead of database
        return array_slice($allProducts, 0, $limit);
    }

    /**
     * VIOLATION: Resource-intensive file operations
     * Processing large files without streaming
     */
    public function processLargeCSVFile(string $filePath): array
    {
        // VIOLATION: Loading entire file into memory
        $content = file_get_contents($filePath); // Could be gigabytes
        $lines = explode("\n", $content);
        
        $processedData = [];
        foreach ($lines as $line) {
            $data = str_getcsv($line);
            
            // VIOLATION: Complex processing on each line
            $processedData[] = $this->performComplexTransformation($data);
        }
        
        return $processedData;
    }

    // Helper methods that simulate expensive operations
    private function getProductCategories(int $productId): array
    {
        // Simulates database query
        usleep(10000); // 10ms delay
        return ['Category 1', 'Category 2'];
    }

    private function getProductReviews(int $productId): array
    {
        // Simulates database query
        usleep(15000); // 15ms delay
        return ['Great product!', 'Excellent quality'];
    }

    private function fetchPriceFromExternalAPI(string $sku): ?float
    {
        // Simulates external API call
        sleep(2); // 2 second delay
        return rand(10, 100) + (rand(0, 99) / 100);
    }

    private function calculateTotalSpent(int $customerId): float
    {
        // Simulates expensive calculation
        usleep(50000); // 50ms delay
        return rand(100, 5000) + (rand(0, 99) / 100);
    }

    private function calculatePreferredCategories(int $customerId): array
    {
        // Simulates very expensive calculation
        usleep(200000); // 200ms delay
        return ['Electronics', 'Clothing', 'Books'];
    }

    private function calculateSeasonalTrends(int $customerId): array
    {
        // Simulates extremely expensive calculation
        sleep(1); // 1 second delay
        return ['Spring: High', 'Summer: Medium', 'Fall: High', 'Winter: Low'];
    }

    // Additional helper methods
    private function getAllProducts(): array { return []; }
    private function getOrderData(int $orderId): array { return []; }
    private function updateProductPrice(int $productId, float $price): void {}
    private function calculateTotalOrders(int $customerId): int { return rand(1, 50); }
    private function calculateAverageOrderValue(int $customerId): float { return rand(50, 200); }
    private function performComplexTransformation(array $data): array { return $data; }
}

/**
 * Helper class that simulates heavy resource usage
 */
class HeavyDataProcessor
{
    private array $productData = [];
    private array $customerHistory = [];
    private array $inventoryData = [];

    public function loadCompleteProductData(int $productId): void
    {
        // Simulate loading lots of data
        $this->productData = array_fill(0, 10000, 'product_data_' . $productId);
    }

    public function loadCustomerHistory(int $orderId): void
    {
        // Simulate loading customer history
        $this->customerHistory = array_fill(0, 5000, 'customer_data_' . $orderId);
    }

    public function loadInventoryData(int $productId): void
    {
        // Simulate loading inventory data
        $this->inventoryData = array_fill(0, 3000, 'inventory_data_' . $productId);
    }

    public function processComplexCalculations(): void
    {
        // Simulate complex processing
        usleep(100000); // 100ms delay
    }
} 