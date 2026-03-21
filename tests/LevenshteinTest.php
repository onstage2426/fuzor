<?php

namespace Fuzor\Tests;

use Fuzor\Levenshtein;
use PHPUnit\Framework\TestCase;

class LevenshteinTest extends TestCase
{
    public function testIdenticalStringsReturnZero(): void
    {
        $this->assertSame(0, Levenshtein::distance('sedan', 'sedan'));
    }

    public function testBothEmptyReturnZero(): void
    {
        $this->assertSame(0, Levenshtein::distance('', ''));
    }

    public function testEmptyVsNonEmptyReturnsLength(): void
    {
        $this->assertSame(5, Levenshtein::distance('', 'sedan'));
        $this->assertSame(5, Levenshtein::distance('sedan', ''));
    }

    public function testSingleInsertion(): void
    {
        $this->assertSame(1, Levenshtein::distance('sedan', 'sedaan'));
    }

    public function testSingleDeletion(): void
    {
        $this->assertSame(1, Levenshtein::distance('sedaan', 'sedan'));
    }

    public function testSingleSubstitution(): void
    {
        $this->assertSame(1, Levenshtein::distance('sedan', 'secan'));
    }

    // Standard Levenshtein counts a transposition as two edits (not one as in
    // Damerau-Levenshtein), because it models only insert/delete/substitute.
    public function testTranspositionCountsAsTwo(): void
    {
        $this->assertSame(2, Levenshtein::distance('ab', 'ba'));
    }

    // --- Unicode ---

    public function testUnicodeIdenticalReturnZero(): void
    {
        $this->assertSame(0, Levenshtein::distance('café', 'café'));
    }

    // Byte-level levenshtein() would return 2 here because 'é' is two bytes;
    // the Unicode-aware implementation must return 1.
    public function testUnicodeAccentedVsPlainCountsOneEdit(): void
    {
        $this->assertSame(1, Levenshtein::distance('café', 'cafe'));
    }

    public function testUnicodeMultipleEdits(): void
    {
        // "résumé" → "resume": substitute é→e twice = 2 edits
        $this->assertSame(2, Levenshtein::distance('résumé', 'resume'));
    }
}
