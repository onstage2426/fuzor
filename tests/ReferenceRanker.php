<?php

namespace Fuzor\Tests;

use Fuzor\FacetExclude;
use Fuzor\Filter;
use Fuzor\Index;
use Fuzor\MatchingStrategy;
use Fuzor\SearchOptions;
use Fuzor\Tokenizer;

/**
 * A deliberately simple search() reference for tests: scores every document by brute force,
 * sharing no code with Index's ranking. Only the expansion of each query word into indexed terms
 * (own term, as-you-type prefix, typo derivations with their distance) comes from
 * Index::inspectQuery(); document frequencies, lengths, BM25, words buckets, typos, sort,
 * filters, negations and quoted phrases are computed here from the documents themselves.
 *
 * Covers what SearchInvariantTest uses: no language, no stop words, no synonyms, no field
 * boosts, proximity off (Config::$proximityBoost = 0.0), string filters and FacetExclude of
 * strings, numeric sort fields that every document has, distinct on a single-valued field.
 */
final class ReferenceRanker
{
    /** @var array<int, list<list<string>>> doc → searchable fields → tokens */
    private array $fields = [];

    /** @var array<int, array<string, int>> doc → term → hits */
    private array $hits = [];

    /** @var array<int, int> */
    private array $length = [];

    /** @var array<string, int> term → documents */
    private array $df = [];

    private float $avgdl;

    /** @var array<int, array<string, mixed>> */
    private array $docs = [];

    /**
     * @param list<array<string, mixed>> $docs
     * @param list<string>               $searchable Searchable fields, in the order the documents hold them.
     */
    public function __construct(
        array $docs,
        array $searchable,
        private readonly Index $index,
        private readonly float $k1 = 1.2,
        private readonly float $b = 0.75,
    ) {
        foreach ($docs as $doc) {
            $id = self::int($doc['id']);
            $this->docs[$id]   = $doc;
            $this->fields[$id] = [];
            $this->hits[$id]   = [];
            foreach ($searchable as $field) {
                $tokens              = Tokenizer::tokenize(self::str($doc[$field] ?? ''), null);
                $this->fields[$id][] = $tokens;
                foreach ($tokens as $t) {
                    $this->hits[$id][$t] = ($this->hits[$id][$t] ?? 0) + 1;
                }
            }
            $this->length[$id] = array_sum(array_map('count', $this->fields[$id]));
            foreach (array_keys($this->hits[$id]) as $t) {
                $this->df[(string) $t] = ($this->df[(string) $t] ?? 0) + 1;
            }
        }
        $this->avgdl = max(1.0, array_sum($this->length) / max(1, count($docs)));
    }

    /**
     * Every match in rank order (with distinct: the ones it keeps) with its rank key, and the
     * facet counts of the result set.
     *
     * @return array{ids: list<int>, keys: array<int, list<float|int>>,
     *               facets: array<string, array<string, int>>}
     */
    public function search(string $query, SearchOptions $o): array
    {
        $filter = $o->filter;
        if ($filter instanceof Filter) {
            throw new \LogicException('The reference ranker takes filter maps only.');
        }
        $negations = [];
        $positive  = preg_replace_callback(
            '/(?<=^|\s)-("[^"]*"|\S+)/',
            function (array $m) use (&$negations): string {
                $negations[] = Tokenizer::tokenize(trim($m[1], '"'), null);
                return ' ';
            },
            $query,
        ) ?? $query;
        preg_match_all('/"([^"]*)"/', $positive, $m);
        $phrases = array_values(array_filter(
            array_map(fn(string $p): array => Tokenizer::tokenize($p, null), $m[1]),
            fn(array $p): bool => $p !== [],
        ));

        // Each query word: its terms → typos (0 for its own term and prefix expansions).
        /** @var list<array<string, int>> $groups */
        $groups = [];
        /** @var list<string> $words */
        $words = [];
        foreach ($this->index->inspectQuery($query, $o->asYouType)->tokens as $token) {
            $words[] = $token->processed;
            $terms = [];
            foreach ($token->wordlistRows as $row) {
                $terms[$row['term']] = $row['distance'] ?? 0;
            }
            $groups[] = $terms;
        }
        if ($groups === []) {
            return ['ids' => [], 'keys' => [], 'facets' => []];
        }
        $order = array_keys($groups);
        // A word's document frequency: its terms' summed (as Index does for expansions).
        $gdf = array_map(
            fn(array $g): int => array_sum(array_map(fn($t) => $this->df[$t] ?? 0, array_keys($g))),
            $groups,
        );
        if ($o->matchingStrategy === MatchingStrategy::Frequency) {
            usort($order, fn(int $a, int $b): int => $gdf[$a] <=> $gdf[$b] ?: $a <=> $b);
        }
        $minBucket = $o->matchingStrategy === MatchingStrategy::All ? count($order) : 1;
        // A phrase whose words are all indexed is required; documents below its last word's
        // bucket are not returned (Index's documented rule).
        $phrases = array_values(array_filter($phrases, function (array $p): bool {
            foreach ($p as $t) {
                if (!isset($this->df[$t])) {
                    return false;
                }
            }
            return true;
        }));
        foreach ($phrases as $p) {
            foreach ($order as $pos => $g) {
                if (in_array($words[$g], $p, true)) {
                    $minBucket = max($minBucket, $pos + 1);
                }
            }
        }

        $n     = count($this->docs);
        $match = [];   // doc → [bucket, typos, score]
        foreach ($this->hits as $id => $hits) {
            $bucket = 0;
            $typos  = 0;
            $score  = 0.0;
            foreach ($order as $g) {
                $found = null;
                $part  = 0.0;
                $idf   = log(1 + ($n - min($gdf[$g], $n) + 0.5) / (min($gdf[$g], $n) + 0.5));
                foreach ($groups[$g] as $term => $distance) {
                    $tf = $hits[$term] ?? 0;
                    if ($tf === 0) {
                        continue;
                    }
                    $found = min($found ?? PHP_INT_MAX, $distance);
                    $norm  = $this->k1 * (1 - $this->b + $this->b * $this->length[$id] / $this->avgdl);
                    $part += $idf * ($this->k1 + 1) * $tf / ($norm + $tf);
                }
                if ($found === null) {
                    break;
                }
                $bucket++;
                $typos += $found;
                $score += $part;
            }
            if ($bucket < $minBucket) {
                continue;
            }
            if (!$this->passesQueryFilters($id, $negations, $phrases)) {
                continue;
            }
            $match[$id] = [$bucket, $typos, $score];
        }

        $passing = array_filter(array_keys($match), fn(int $id): bool => $this->passesFilter($id, $filter));
        $keys    = [];
        foreach ($passing as $id) {
            [$bucket, $typos, $score] = $match[$id];
            $sortValues = [];
            foreach ($o->sort as $spec) {
                [$field, $dir] = explode(':', $spec);
                $v             = (float) self::num($this->doc($id)[$field]);
                $sortValues[]  = $dir === 'desc' ? -$v : $v;
            }
            $keys[$id] = [-$bucket, $typos, ...$sortValues, -$score];
        }
        $ids = array_keys($keys);
        usort($ids, function (int $a, int $b) use ($keys): int {
            foreach ($keys[$a] as $i => $x) {
                if (!self::near($x, $keys[$b][$i])) {
                    return $x <=> $keys[$b][$i];
                }
            }
            return $a <=> $b;
        });
        if ($o->distinct !== null) {
            // At most distinctCount documents per value, in rank order; those without it all kept.
            $seen = [];
            $ids  = array_values(array_filter($ids, function (int $id) use ($o, &$seen): bool {
                $value = $this->doc($id)[$o->distinct] ?? null;
                if ($value === null) {
                    return true;
                }
                $key        = self::str($value);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                return $seen[$key] <= $o->distinctCount;
            }));
        }

        $facets = [];
        foreach ($o->facets as $field) {
            $own     = $filter[$field] ?? null;
            $counted = $own === null || $own instanceof FacetExclude
                ? $filter
                : array_diff_key($filter, [$field => true]);
            $counts = [];
            foreach (array_keys($match) as $id) {
                if ($this->passesFilter($id, $counted) && isset($this->doc($id)[$field])) {
                    $v          = self::str($this->doc($id)[$field]);
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }
            if ($counts !== []) {
                ksort($counts);
                $facets[$field] = $counts;
            }
        }
        return ['ids' => $ids, 'keys' => $keys, 'facets' => $facets];
    }

    /** Equal rank keys: integers exactly, BM25 scores up to float summation order. */
    public static function near(float|int $a, float|int $b): bool
    {
        return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
    }

    /**
     * @param list<list<string>> $negations
     * @param list<list<string>> $phrases
     */
    private function passesQueryFilters(int $id, array $negations, array $phrases): bool
    {
        foreach ($negations as $tokens) {
            if ($tokens !== [] && $this->containsPhrase($id, $tokens)) {
                return false;
            }
        }
        foreach ($phrases as $tokens) {
            if (!$this->containsPhrase($id, $tokens)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $tokens Consecutive inside one field. */
    private function containsPhrase(int $id, array $tokens): bool
    {
        foreach ($this->fields[$id] as $field) {
            for ($i = 0; $i + count($tokens) <= count($field); $i++) {
                if (array_slice($field, $i, count($tokens)) === $tokens) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $filter String / list / FacetExclude of those, per field. */
    private function passesFilter(int $id, array $filter): bool
    {
        foreach ($filter as $field => $value) {
            $exclude = $value instanceof FacetExclude;
            $values  = $exclude ? $value->filter : $value;
            if (!is_string($values) && !is_array($values)) {
                throw new \LogicException('ReferenceRanker supports string filters only');
            }
            $has = isset($this->doc($id)[$field])
                && in_array(self::str($this->doc($id)[$field]), (array) $values, true);
            if ($has === $exclude) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function doc(int $id): array
    {
        return $this->docs[$id];
    }

    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : throw new \LogicException('integer id expected');
    }

    private static function num(mixed $v): int|float
    {
        return is_int($v) || is_float($v) ? $v : throw new \LogicException('number expected');
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : throw new \LogicException('scalar expected');
    }
}
