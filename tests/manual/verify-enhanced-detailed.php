#!/usr/bin/env php
<?php
/**
 * Detailed Verification of Enhanced Recommendations
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$reportFile = $argv[1] ?? 'reports/acqc-data_2026-01-31_12-04-56.xlsx';

if (!file_exists($reportFile)) {
    echo "❌ Report file not found: {$reportFile}\n";
    exit(1);
}

echo "Detailed Verification of Enhanced Recommendations\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$spreadsheet = IOFactory::load($reportFile);
$perfSheet = $spreadsheet->getSheetByName('Performance Analysis');

if (!$perfSheet) {
    echo "❌ Performance Analysis sheet not found\n";
    exit(1);
}

// Find Performance Issue Details section
$lastRow = $perfSheet->getHighestRow();
$issueDetailsRow = null;

for ($row = 1; $row <= $lastRow; $row++) {
    $cellA = $perfSheet->getCell('A' . $row)->getValue();
    if (stripos($cellA, 'Performance Issue Details') !== false) {
        $issueDetailsRow = $row;
        echo "✅ Found 'Performance Issue Details' at row {$row}\n";
        break;
    }
}

if (!$issueDetailsRow) {
    echo "⚠️  Could not find 'Performance Issue Details' section\n";
    exit(1);
}

// Next row should be headers
$headerRow = $issueDetailsRow + 1;
$headers = [];
for ($col = 'A'; $col <= 'Z'; $col++) {
    $value = $perfSheet->getCell($col . $headerRow)->getValue();
    if ($value) {
        $headers[$col] = $value;
    }
}

echo "\n📊 Issue Details Headers:\n";
foreach ($headers as $col => $header) {
    echo "   Column {$col}: {$header}\n";
}

// Find column indices
$colMapping = [];
foreach ($headers as $col => $header) {
    $lowerHeader = strtolower($header);
    if (stripos($lowerHeader, 'module') !== false) $colMapping['module'] = $col;
    if (stripos($lowerHeader, 'file') !== false || stripos($lowerHeader, 'path') !== false) $colMapping['file'] = $col;
    if (stripos($lowerHeader, 'issue') !== false || stripos($lowerHeader, 'type') !== false) $colMapping['issue'] = $col;
    if (stripos($lowerHeader, 'severity') !== false) $colMapping['severity'] = $col;
    if (stripos($lowerHeader, 'recommendation') !== false) $colMapping['recommendation'] = $col;
}

echo "\n✅ Column Mapping:\n";
foreach ($colMapping as $type => $col) {
    echo "   {$type}: Column {$col}\n";
}

// Sample performance issues
echo "\n";
echo "=" . str_repeat("=", 70) . "\n";
echo "📋 Performance Issues with Recommendations (First 10):\n";
echo "=" . str_repeat("=", 70) . "\n\n";

$dataStartRow = $headerRow + 1;
$count = 0;
$stats = [
    'total' => 0,
    'enhanced' => 0,
    'generic' => 0,
    'by_rule' => []
];

for ($row = $dataStartRow; $row <= $lastRow && $count < 10; $row++) {
    $issue = $perfSheet->getCell($colMapping['issue'] . $row)->getValue();
    $recommendation = $perfSheet->getCell($colMapping['recommendation'] . $row)->getValue();
    
    if (!$issue || !$recommendation) {
        continue;
    }
    
    $count++;
    $stats['total']++;
    
    $isGeneric = (
        stripos($recommendation, 'Optimize code for better performance and efficiency') !== false ||
        stripos($recommendation, 'Optimize code for better performance') === 0
    );
    
    if ($isGeneric) {
        $stats['generic']++;
        $status = "❌ GENERIC";
    } else {
        $stats['enhanced']++;
        $status = "✅ ENHANCED";
    }
    
    // Track by rule
    if (!isset($stats['by_rule'][$issue])) {
        $stats['by_rule'][$issue] = ['enhanced' => 0, 'generic' => 0];
    }
    $stats['by_rule'][$issue][$isGeneric ? 'generic' : 'enhanced']++;
    
    echo "Issue #{$count}: {$status}\n";
    echo "  Rule: {$issue}\n";
    echo "  Recommendation: ";
    $shortRec = strlen($recommendation) > 100 ? substr($recommendation, 0, 100) . '...' : $recommendation;
    echo $shortRec . "\n\n";
}

// Continue counting rest without printing
for ($row = $dataStartRow + $count; $row <= $lastRow; $row++) {
    $issue = $perfSheet->getCell($colMapping['issue'] . $row)->getValue();
    $recommendation = $perfSheet->getCell($colMapping['recommendation'] . $row)->getValue();
    
    if (!$issue || !$recommendation) {
        continue;
    }
    
    $stats['total']++;
    
    $isGeneric = (
        stripos($recommendation, 'Optimize code for better performance and efficiency') !== false ||
        stripos($recommendation, 'Optimize code for better performance') === 0
    );
    
    if ($isGeneric) {
        $stats['generic']++;
    } else {
        $stats['enhanced']++;
    }
    
    if (!isset($stats['by_rule'][$issue])) {
        $stats['by_rule'][$issue] = ['enhanced' => 0, 'generic' => 0];
    }
    $stats['by_rule'][$issue][$isGeneric ? 'generic' : 'enhanced']++;
}

echo "\n";
echo "=" . str_repeat("=", 70) . "\n";
echo "📊 Complete Statistics:\n";
echo "=" . str_repeat("=", 70) . "\n\n";

echo "Total issues analyzed: {$stats['total']}\n";
echo "Enhanced recommendations: {$stats['enhanced']} (" . round(($stats['enhanced'] / $stats['total']) * 100, 1) . "%)\n";
echo "Generic recommendations: {$stats['generic']} (" . round(($stats['generic'] / $stats['total']) * 100, 1) . "%)\n\n";

echo "Breakdown by Rule:\n";
echo str_repeat("-", 70) . "\n";
foreach ($stats['by_rule'] as $rule => $counts) {
    $total = $counts['enhanced'] + $counts['generic'];
    $enhPercent = round(($counts['enhanced'] / $total) * 100, 1);
    $status = $counts['enhanced'] > 0 ? "✅" : "❌";
    echo "{$status} {$rule}: {$counts['enhanced']}/{$total} enhanced ({$enhPercent}%)\n";
}

if ($stats['generic'] > 0) {
    echo "\n⚠️  Rules still using generic recommendations:\n";
    foreach ($stats['by_rule'] as $rule => $counts) {
        if ($counts['generic'] > 0) {
            echo "   • {$rule}: {$counts['generic']} instances\n";
        }
    }
}

echo "\n";
if ($stats['enhanced'] / $stats['total'] >= 0.8) {
    echo "✅ SUCCESS! Majority of recommendations are enhanced!\n";
} else {
    echo "⚠️  Need more rule mappings to improve recommendation quality.\n";
}

echo "\n";
