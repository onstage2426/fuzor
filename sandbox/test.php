<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Fuzor\Index;

$indexDir = __DIR__ . '/indexes';
$dataFile = __DIR__ . '/data/cars.json';

if (!is_dir($indexDir)) {
    mkdir($indexDir, 0755, true);
}

$records = json_decode(file_get_contents($dataFile), true);

$european = ['BMW', 'Audi', 'Mercedes-Benz', 'Volkswagen', 'Porsche', 'Jaguar'];
[$euRecords, $otherRecords] = [[], []];
foreach ($records as $r) {
    if (in_array($r['brand'], $european, true)) {
        $euRecords[] = $r;
    } else {
        $otherRecords[] = $r;
    }
}

// --- Build indexes ---
$t = microtime(true);
$eu = Index::create($indexDir . '/european.db', true);
$eu->insertMany($euRecords);
printf("Built european.db  (%d docs)  %.2f ms\n", count($euRecords), (microtime(true) - $t) * 1000);

$t = microtime(true);
$other = Index::create($indexDir . '/other.db', true);
$other->insertMany($otherRecords);
printf("Built other.db     (%d docs)  %.2f ms\n", count($otherRecords), (microtime(true) - $t) * 1000);

echo "\n";

// --- Open indexes ---
$eu    = Index::open($indexDir . '/european.db');
$other = Index::open($indexDir . '/other.db');

// --- Standard search ---
echo "=== Standard search ===\n";
foreach (['sedan', 'SUV', 'TDI'] as $q) {
    $t = microtime(true);
    $a = $eu->search($q, 5);
    $b = $other->search($q, 5);
    $ms = (microtime(true) - $t) * 1000;
    printf("  %-10s  eu:%d [%s]  other:%d [%s]  %.2f ms\n",
        "'$q'",
        $a['hits'], implode(', ', $a['ids']),
        $b['hits'], implode(', ', $b['ids']),
        $ms
    );
}

// --- Boolean search ---
echo "\n=== Boolean search ===\n";
foreach (['sedan or coupe', 'SUV -bmw', 'audi or porsche'] as $q) {
    $t = microtime(true);
    $a = $eu->searchBoolean($q, 5);
    $b = $other->searchBoolean($q, 5);
    $ms = (microtime(true) - $t) * 1000;
    printf("  %-20s  eu:%d [%s]  other:%d [%s]  %.2f ms\n",
        "'$q'",
        $a['hits'], implode(', ', $a['ids']),
        $b['hits'], implode(', ', $b['ids']),
        $ms
    );
}

// --- Fuzzy search ---
echo "\n=== Fuzzy search ===\n";
foreach (['mercdes' => 'mercedes', 'volksagen' => 'volkswagen', 'porche' => 'porsche'] as $typo => $intended) {
    $t = microtime(true);
    $a = $eu->searchFuzzy($typo, 5);
    $b = $other->searchFuzzy($typo, 5);
    $ms = (microtime(true) - $t) * 1000;
    printf("  %-14s (→%-12s)  eu:%d [%s]  other:%d [%s]  %.2f ms\n",
        "'$typo'", $intended,
        $a['hits'], implode(', ', $a['ids']),
        $b['hits'], implode(', ', $b['ids']),
        $ms
    );
}

echo "\n";
