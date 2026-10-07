<?php

declare(strict_types=1);

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

    // Optimal string alignment: a swap of two adjacent characters is one edit.
    public function testAdjacentTranspositionCountsAsOne(): void
    {
        $this->assertSame(1, Levenshtein::distance('ab', 'ba'));
        $this->assertSame(1, Levenshtein::distance('casaul', 'casual'));
        $this->assertSame(1, Levenshtein::distance('teh', 'the'));
    }

    public function testTranspositionCombinesWithOtherEdits(): void
    {
        $this->assertSame(2, Levenshtein::distance('casaulx', 'casualy'));
        $this->assertSame(2, Levenshtein::distance('abdc', 'bacd'));
        $this->assertSame(3, Levenshtein::distance('abcdef', 'badcfe'));
    }

    // OSA never edits a substring twice, unlike unrestricted Damerau-Levenshtein ('ca' → 'abc' is 2 there).
    public function testTransposedCharactersAreNotEditedAgain(): void
    {
        $this->assertSame(3, Levenshtein::distance('ca', 'abc'));
    }

    public function testUnicodeTranspositionCountsAsOne(): void
    {
        $this->assertSame(1, Levenshtein::distance('éa', 'aé'));
        $this->assertSame(1, Levenshtein::distance('crème', 'crèem'));
    }

    public function testMaxCapsTheReportedDistance(): void
    {
        $this->assertSame(2, Levenshtein::distance('abcdef', 'ghijkl', 1));
        $this->assertSame(3, Levenshtein::distance('abcdefgh', 'a', 2));
        $this->assertSame(1, Levenshtein::distance('casaul', 'casual', 1));
        // Distances within the cap are exact.
        $this->assertSame(2, Levenshtein::distance('casaulx', 'casualy', 5));
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
        $this->assertSame(2, Levenshtein::distance('résumé', 'resume'));
    }

    public function testUnicodeThreeDistinctCodepointsVsAscii(): void
    {
        $this->assertSame(3, Levenshtein::distance('éàè', '~'));
    }

    public function testUnicodeCodepointRemapDoesNotCollideWithDelByte(): void
    {
        $this->assertSame(1, Levenshtein::distance('ñello', chr(127) . 'ello'));
    }

    public function testUnicodeCodepointRemapDoesNotCollideWithChr129(): void
    {
        $this->assertSame(1, Levenshtein::distance('ñhello', chr(129) . 'hello'));
    }
}
