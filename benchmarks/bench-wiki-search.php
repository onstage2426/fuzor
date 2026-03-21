<?php

declare(strict_types=1);

ini_set('memory_limit', '512M');

require_once __DIR__ . '/../vendor/autoload.php';

use Fuzor\Index;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function memMB(): float
{
    return memory_get_usage(true) / 1024 / 1024;
}

function peakMemMB(): float
{
    return memory_get_peak_usage(true) / 1024 / 1024;
}

// ---------------------------------------------------------------------------
// Open index
// ---------------------------------------------------------------------------

$idxPath = __DIR__ . '/wiki.db';
if (!file_exists($idxPath)) {
    fwrite(STDERR, "Index not found: $idxPath\nRun bench-wiki-index.php first.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Output tee: write to STDOUT + result-wiki-search.txt
// ---------------------------------------------------------------------------

$outPath = __DIR__ . '/result-wiki-search.txt';
$outFh   = fopen($outPath, 'w');
if ($outFh === false) {
    fwrite(STDERR, "Cannot open output file: $outPath\n");
    exit(1);
}

function out(string $line = ''): void
{
    global $outFh;
    assert(is_resource($outFh));
    echo $line . PHP_EOL;
    fwrite($outFh, $line . PHP_EOL);
}

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------

$idxSize = filesize($idxPath) / 1024 / 1024;

out(str_repeat('=', 60));
out(' Fuzor — Wikipedia Search Benchmark');
out(' Date  : ' . date('Y-m-d H:i:s'));
out(sprintf(' Size  : %.1f MB', $idxSize));
// out(' Lang  : en (English stopwords)');
out(str_repeat('=', 60));
out();

$index = Index::open($idxPath);
// $index->language = 'en';

// ---------------------------------------------------------------------------
// 1. Exact BM25
// ---------------------------------------------------------------------------

out(str_pad(' search() — exact BM25 ', 60, '-', STR_PAD_BOTH));

$searchQueries = [
    'anarchism',
    'photosynthesis',
    'quantum mechanics',
    'world war',
    'artificial intelligence',
    'evolution darwin',
    'shakespeare',
    'climate change',
];

$index->asYouType = false;

foreach ($searchQueries as $q) {
    $runs   = 5;
    $start  = hrtime(true);
    $result = null;
    for ($r = 0; $r < $runs; $r++) {
        $result = $index->search($q);
    }
    $avg = (hrtime(true) - $start) / 1e9 / $runs * 1000;
    out(sprintf('  %-38s %8.1f ms   %d hits', "\"$q\"", $avg, $result['hits']));
}

out();

// ---------------------------------------------------------------------------
// 2. As-you-type prefix
// ---------------------------------------------------------------------------

out(str_pad(' search() — asYouType prefix ', 60, '-', STR_PAD_BOTH));

$index->asYouType = true;
$prefixQueries    = ['anarch', 'photo', 'quant', 'evolu', 'climat'];

foreach ($prefixQueries as $q) {
    $runs   = 5;
    $start  = hrtime(true);
    $result = null;
    for ($r = 0; $r < $runs; $r++) {
        $result = $index->search($q);
    }
    $avg = (hrtime(true) - $start) / 1e9 / $runs * 1000;
    out(sprintf('  %-38s %8.1f ms   %d hits', "\"$q\" (prefix)", $avg, $result['hits']));
}

$index->asYouType = false;
out();

// ---------------------------------------------------------------------------
// 3. Fuzzy
// ---------------------------------------------------------------------------

out(str_pad(' searchFuzzy() — Levenshtein ', 60, '-', STR_PAD_BOTH));

$fuzzyQueries = ['anarchizm', 'photosinthesis', 'quatum', 'evolusion', 'shakespear'];

foreach ($fuzzyQueries as $q) {
    $runs   = 3;
    $start  = hrtime(true);
    $result = null;
    for ($r = 0; $r < $runs; $r++) {
        $result = $index->searchFuzzy($q);
    }
    $avg = (hrtime(true) - $start) / 1e9 / $runs * 1000;
    out(sprintf('  %-38s %8.1f ms   %d hits', "\"$q\" (fuzzy)", $avg, $result['hits']));
}

out();

// ---------------------------------------------------------------------------
// 4. Boolean
// ---------------------------------------------------------------------------

out(str_pad(' searchBoolean() ', 60, '-', STR_PAD_BOTH));

$boolQueries = [
    'science or history',
    'war -peace',
    'democracy and freedom',
    'physics or chemistry or biology',
    'europe and medieval',
    'language -english or french',
];

foreach ($boolQueries as $q) {
    $runs   = 5;
    $start  = hrtime(true);
    $result = null;
    for ($r = 0; $r < $runs; $r++) {
        $result = $index->searchBoolean($q);
    }
    $avg = (hrtime(true) - $start) / 1e9 / $runs * 1000;
    out(sprintf('  %-38s %8.1f ms   %d hits', "\"$q\"", $avg, $result['hits']));
}

out();

// ---------------------------------------------------------------------------
// 5. Throughput
// ---------------------------------------------------------------------------

out(str_pad(' Throughput ', 60, '-', STR_PAD_BOTH));

$rounds = 20;
$qMix   = ['anarchism', 'photosynthesis', 'quantum mechanics', 'world war', 'artificial intelligence'];
$qTotal = count($qMix) * $rounds;
$start  = hrtime(true);
foreach (range(1, $rounds) as $_) {
    foreach ($qMix as $q) {
        $index->search($q);
    }
}
$elapsed = (hrtime(true) - $start) / 1e9;
out(sprintf('  %-42s %8.0f qps', "search() mix ($qTotal queries)", $qTotal / $elapsed));

$rounds = 2;
$qTotal = count($boolQueries) * $rounds;
$start  = hrtime(true);
foreach (range(1, $rounds) as $_) {
    foreach ($boolQueries as $q) {
        $index->searchBoolean($q);
    }
}
$elapsed = (hrtime(true) - $start) / 1e9;
out(sprintf('  %-42s %8.0f qps', "searchBoolean() mix ($qTotal queries)", $qTotal / $elapsed));

out();

// ---------------------------------------------------------------------------
// 6. Memory
// ---------------------------------------------------------------------------

out(str_pad(' Memory ', 60, '-', STR_PAD_BOTH));

out(sprintf('  %-42s %8.1f MB', 'Peak PHP memory', peakMemMB()));
out(sprintf('  %-42s %8.1f MB', 'Current PHP memory', memMB()));
out(sprintf('  %-42s %8.1f MB', 'Index file size', $idxSize));
out();

$index->close();

out(str_repeat('-', 60));
out('Done.');

fclose($outFh);
echo PHP_EOL . 'Results written to: ' . substr($outPath, strlen((string) realpath(__DIR__ . '/..')) + 1) . PHP_EOL;
