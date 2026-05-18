#!/usr/bin/env php
<?php
/**
 * Verify Enhanced Recommendations in Excel Report
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$reportFile = $argv[1] ?? 'reports/acqc-data_2026-01-31_12-04-56.xlsx';

if (!file_exists($reportFile)) {
    echo "❌ Report file not found: {$reportFile}\n";
    exit(1);
}

echo "Verifying Enhanced Recommendations in Excel Report\n";
echo "=" . str_repeat("=", 70) . "\n\n";

echo "📄 Report: {$reportFile}\n";
echo "   Size: " . number_format(filesize($reportFile)) . " bytes\n\n";

echo "Loading Excel file...\n";
$spreadsheet = IOFactory::load($reportFile);

// Check Performance Analysis sheet
$perfSheet = $spreadsheet->getSheetByName('Performance Analysis');
if (!$perfSheet) {
    echo "❌ Performance Analysis sheet not found\n";
    exit(1);
}

echo "✅ Found Performance Analysis sheet\n\n";

// Find the recommendation column
$headers = [];
for ($col = 'A'; $col <= 'Z'; $col++) {
    $headerValue = $perfSheet->getCell($col . '1')->getValue();
    if ($headerValue) {
        $headers[$col] = $headerValue;
    }
}

echo "📊 Sheet Headers:\n";
foreach ($headers as $col => $header) {
    echo "   Column {$col}: {$header}\n";
}
echo "\n";

// Find recommendation column (typically column F or G)
$recommendationCol = null;
foreach ($headers as $col => $header) {
    if (stripos($header, 'recommendation') !== false) {
        $recommendationCol = $col;
        break;
    }
}

if (!$recommendationCol) {
    echo "⚠️  Could not find Recommendation column in headers\n";
    // Try to find it in row 3 or later (might be in Issue Details section)
    $lastRow = $perfSheet->getHighestRow();
    for ($row = 1; $row <= min(20, $lastRow); $row++) {
        for ($col = 'A'; $col <= 'Z'; $col++) {
            $value = $perfSheet->getCell($col . $row)->getValue();
            if ($value && stripos($value, 'recommendation') !== false) {
                $recommendationCol = $col;
                $startRow = $row + 1;
                echo "✅ Found Recommendation column: {$col} at row {$row}\n";
                break 2;
            }
        }
    }
}

if (!$recommendationCol) {
    echo "❌ Could not find Recommendation column\n";
    exit(1);
}

echo "✅ Recommendation column: {$recommendationCol}\n\n";

// Sample some recommendations
echo "=" . str_repeat("=", 70) . "\n";
echo "📋 Sample Recommendations (First 5):\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$lastRow = $perfSheet->getHighestRow();
$sampleCount = 0;
$enhancedCount = 0;
$genericCount = 0;

for ($row = 2; $row <= $lastRow && $sampleCount < 10; $row++) {
    $recommendation = $perfSheet->getCell($recommendationCol . $row)->getValue();
    
    if (!$recommendation || empty(trim($recommendation))) {
        continue;
    }
    
    $sampleCount++;
    
    // Check if it's generic or enhanced
    $isGeneric = (stripos($recommendation, 'Optimize code for better') !== false);
    
    if ($isGeneric) {
        $genericCount++;
        $status = "❌ GENERIC";
    } else {
        $enhancedCount++;
        $status = "✅ ENHANCED";
    }
    
    if ($sampleCount <= 5) {
        echo "Row {$row}: {$status}\n";
        echo str_repeat("-", 70) . "\n";
        echo substr($recommendation, 0, 200);
        if (strlen($recommendation) > 200) {
            echo "...";
        }
        echo "\n\n";
    }
}

echo "\n";
echo "=" . str_repeat("=", 70) . "\n";
echo "📊 Summary Statistics:\n";
echo "=" . str_repeat("=", 70) . "\n\n";

echo "Total recommendations sampled: {$sampleCount}\n";
echo "Enhanced recommendations: {$enhancedCount}\n";
echo "Generic recommendations: {$genericCount}\n";

if ($enhancedCount > 0) {
    $percentage = round(($enhancedCount / $sampleCount) * 100, 1);
    echo "\n✅ SUCCESS! {$percentage}% of recommendations are enhanced!\n";
} else {
    echo "\n⚠️  WARNING: No enhanced recommendations found. Check integration.\n";
}

echo "\n";
