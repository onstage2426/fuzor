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
// Load dataset
// ---------------------------------------------------------------------------

$datasetPath = __DIR__ . '/../resources/datasets/movies.json';
$json = file_get_contents($datasetPath);
if ($json === false) {
    fwrite(STDERR, "Cannot read dataset: $datasetPath\n");
    exit(1);
}
/** @var list<array<string, mixed>> $movies */
$movies = json_decode($json, true);
$total  = count($movies);

/** @var list<array{id: mixed, text: string}> $docs */
$docs = array_map(fn(array $m): array => [
    'id'   => $m['id'],
    'text' => trim(is_string($m['title'] ?? null) ? $m['title'] : '') . ' '
            . trim(is_string($m['overview'] ?? null) ? $m['overview'] : ''),
], $movies);

// ---------------------------------------------------------------------------
// Output tee: write to STDOUT + basemark-movies.txt
// ---------------------------------------------------------------------------

$outPath = __DIR__ . '/result-movies.txt';
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

out(str_repeat('=', 60));
out(' Fuzor — Movies Benchmark');
out(' Date    : ' . date('Y-m-d H:i:s'));
out(' Dataset : ' . basename($datasetPath) . " ($total docs)");
out(str_repeat('=', 60));
out();

// ---------------------------------------------------------------------------
// 1. Index build
// ---------------------------------------------------------------------------

out(str_pad(' Index build ', 60, '-', STR_PAD_BOTH));

$tmpDir  = sys_get_temp_dir();
$idxPath = __DIR__ . '/movies.db';
@unlink($idxPath);

$t0    = hrtime(true);
$index = Index::create($idxPath, true);
out(sprintf('  %-38s %8.1f ms', 'Create index (schema init)', (hrtime(true) - $t0) / 1e6));

$index = Index::create($idxPath, true);
$start = hrtime(true);
$index->insertMany($docs);
out(sprintf('  %-38s %8.1f ms', "insertMany ($total docs)", (hrtime(true) - $start) / 1e6));

$index2 = Index::create($tmpDir . '/fuzor_bench2.db', true);
$start  = hrtime(true);
foreach ($docs as $doc) {
    $index2->insert($doc);
}
out(sprintf('  %-38s %8.1f ms', "insert() ($total docs, one by one)", (hrtime(true) - $start) / 1e6));
$index2->close();
@unlink($tmpDir . '/fuzor_bench2.db');

out();

// ---------------------------------------------------------------------------
// 2. Exact BM25
// ---------------------------------------------------------------------------

out(str_pad(' search() — exact BM25 ', 60, '-', STR_PAD_BOTH));

$searchQueries = ['space', 'love', 'war', 'dragon', 'secret agent'];

$index->asYouType = false;

foreach ($searchQueries as $q) {
    $runs   = 20;
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
// 3. As-you-type prefix
// ---------------------------------------------------------------------------

out(str_pad(' search() — asYouType prefix ', 60, '-', STR_PAD_BOTH));

$index->asYouType = true;
$prefixQueries    = ['sci', 'dra', 'adv'];

foreach ($prefixQueries as $q) {
    $runs   = 20;
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
// 4. Fuzzy
// ---------------------------------------------------------------------------

out(str_pad(' searchFuzzy() — Levenshtein ', 60, '-', STR_PAD_BOTH));

$fuzzyQueries = ['spase', 'draggon', 'lovve', 'warroir'];

foreach ($fuzzyQueries as $q) {
    $runs   = 10;
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
// 5. Boolean
// ---------------------------------------------------------------------------

out(str_pad(' searchBoolean() ', 60, '-', STR_PAD_BOTH));

$boolQueries = [
    'space or war',
    'love -romance',
    'hero and villain',
    'action or adventure or thriller',
];

foreach ($boolQueries as $q) {
    $runs   = 20;
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
// 6. Throughput
// ---------------------------------------------------------------------------

out(str_pad(' Throughput ', 60, '-', STR_PAD_BOTH));

$rounds = 100;
$qMix   = ['space', 'love', 'war', 'dragon', 'secret agent'];
$qTotal = count($qMix) * $rounds;
$start  = hrtime(true);
foreach (range(1, $rounds) as $_) {
    foreach ($qMix as $q) {
        $index->search($q);
    }
}
$elapsed = (hrtime(true) - $start) / 1e9;
out(sprintf('  %-42s %8.0f qps', "search() mix ($qTotal queries)", $qTotal / $elapsed));

$rounds = 50;
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
// 7. Memory
// ---------------------------------------------------------------------------

out(str_pad(' Memory ', 60, '-', STR_PAD_BOTH));

$idxSize = file_exists($idxPath) ? filesize($idxPath) / 1024 / 1024 : 0.0;
out(sprintf('  %-42s %8.1f MB', 'Peak PHP memory', peakMemMB()));
out(sprintf('  %-42s %8.1f MB', 'Current PHP memory', memMB()));
out(sprintf('  %-42s %8.1f MB', 'Index file size', $idxSize));
out();

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

$index->close();

out(str_repeat('-', 60));
out('Done.');

fclose($outFh);
echo PHP_EOL . 'Results written to: ' . substr($outPath, strlen((string) realpath(__DIR__ . '/..')) + 1) . PHP_EOL;
