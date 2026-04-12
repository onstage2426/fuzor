<?php

namespace Fuzor\Tests;

use Fuzor\Snippeter;
use Fuzor\Stemmer;
use PHPUnit\Framework\TestCase;

class SnippeterTest extends TestCase
{
    // --- basic windowing ---

    public function testReturnsExcerptContainingMatchedTerm(): void
    {
        $snip = new Snippeter(windowSize: 50);
        $text = str_repeat('filler words here ', 10) . 'target term appears here ' . str_repeat('more filler ', 10);
        $this->assertStringContainsString('target', $snip->snippet('target', $text));
    }

    public function testReturnedSnippetIsNoLongerThanWindowSizePlusEllipsis(): void
    {
        $snip   = new Snippeter(windowSize: 80);
        $text   = str_repeat('the quick brown fox jumped over the lazy dog ', 20);
        $result = $snip->snippet('fox', $text);
        // Strip ellipsis and surrounding spaces before measuring.
        $clean  = str_replace(['… ', ' …', '…'], '', $result);
        $this->assertLessThanOrEqual(80 + 5, mb_strlen($clean, 'UTF-8'));
    }

    public function testShortTextReturnedInFull(): void
    {
        $snip = new Snippeter(windowSize: 200);
        $text = 'short text with fox';
        $this->assertSame($text, $snip->snippet('fox', $text));
    }

    public function testEmptyTextReturnsEmptyString(): void
    {
        $snip = new Snippeter();
        $this->assertSame('', $snip->snippet('fox', ''));
    }

    public function testEmptyQueryReturnsTruncatedStart(): void
    {
        $snip   = new Snippeter(windowSize: 10);
        $text   = 'abcdefghij klmnopqrstuvwxyz';
        $result = $snip->snippet('', $text);
        // Result is the first $windowSize chars (snapped to word boundary) + ellipsis.
        $this->assertStringStartsWith('abcdefghij', $result);
        $this->assertStringNotContainsString('klmn', $result);
    }

    public function testNoMatchFallsBackToStartOfText(): void
    {
        $snip   = new Snippeter(windowSize: 20);
        $text   = 'the quick brown fox jumped over the lazy dog';
        $result = $snip->snippet('zzznomatch', $text);
        $this->assertStringStartsWith('the', $result);
    }

    // --- ellipsis decoration ---

    public function testEllipsisAddedAtStartWhenWindowDoesNotReachStart(): void
    {
        $snip   = new Snippeter(windowSize: 50);
        $prefix = str_repeat('a ', 60);
        $text   = $prefix . 'target word here';
        $result = $snip->snippet('target', $text);
        $this->assertStringStartsWith('…', $result);
    }

    public function testEllipsisAddedAtEndWhenWindowDoesNotReachEnd(): void
    {
        $snip   = new Snippeter(windowSize: 50);
        $text   = 'target word here ' . str_repeat('z ', 60);
        $result = $snip->snippet('target', $text);
        $this->assertStringEndsWith('…', $result);
    }

    public function testNoEllipsisWhenWindowCoversWholeText(): void
    {
        $snip   = new Snippeter(windowSize: 200);
        $text   = 'short text with target inside';
        $result = $snip->snippet('target', $text);
        $this->assertStringNotContainsString('…', $result);
    }

    public function testCustomEllipsis(): void
    {
        $snip   = new Snippeter(windowSize: 30, ellipsis: '...');
        $prefix = str_repeat('x ', 40);
        $text   = $prefix . 'target is here';
        $result = $snip->snippet('target', $text);
        $this->assertStringStartsWith('...', $result);
    }

    // --- window selection quality ---

    public function testWindowWithMoreMatchingTermsIsPreferred(): void
    {
        // Region A: one match. Region B: three matches, clearly clustered.
        $snip  = new Snippeter(windowSize: 60);
        $regionA = str_repeat('filler ', 15) . 'fox ' . str_repeat('filler ', 5);
        $regionB = 'fox quick fox brown fox';
        $text    = $regionA . str_repeat('filler ', 10) . $regionB . str_repeat(' filler', 10);
        $result  = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox quick fox brown fox', $result);
    }

    public function testWindowSelectsAroundClusteredMatches(): void
    {
        $snip    = new Snippeter(windowSize: 80);
        $sparse  = 'fox ' . str_repeat('word ', 20) . 'fox';
        $cluster = 'fox fox fox';
        $text    = $sparse . ' ' . str_repeat('filler ', 5) . $cluster;
        $result  = $snip->snippet('fox', $text);
        $this->assertStringContainsString('fox fox fox', $result);
    }

    // --- stemmer-aware matching ---

    public function testStemmedQueryMatchesSurfaceFormInBody(): void
    {
        $snip   = new Snippeter(windowSize: 200, stemmer: new Stemmer('en'));
        $prefix = str_repeat('unrelated words here ', 5);
        $text   = $prefix . 'running and jumping every single day keeps you healthy';
        $result = $snip->snippet('running', $text);
        $this->assertStringContainsString('running', $result);
    }

    public function testStemmerConnectsStemVariantsInBody(): void
    {
        // Query "connections" stems to "connect"; body has "connected"
        $snip   = new Snippeter(windowSize: 200, stemmer: new Stemmer('en'));
        $prefix = str_repeat('something else entirely ', 4);
        $text   = $prefix . 'connected to the server via the network';
        $result = $snip->snippet('connections', $text);
        $this->assertStringContainsString('connected', $result);
    }

    public function testNoStemmerNoStemMatch(): void
    {
        // Without stemmer: "run" does not match "running"; falls back to start of text.
        $snip   = new Snippeter(windowSize: 80);
        $text   = str_repeat('other words here ', 5) . 'running quickly every morning';
        $result = $snip->snippet('run', $text);
        // Should fall back; result must NOT start with ellipsis (it starts at text beginning).
        $this->assertStringStartsWith('other', $result);
    }

    // --- multiple snippets ---

    public function testMaxSnippetsOneReturnsSingleWindow(): void
    {
        $snip   = new Snippeter(windowSize: 40, maxSnippets: 1);
        $text   = 'fox here ' . str_repeat('gap ', 20) . 'fox there';
        $result = $snip->snippet('fox', $text);
        // A single window — at most one internal ellipsis joining two regions.
        $this->assertLessThanOrEqual(1, substr_count($result, '…'));
    }

    public function testMaxSnippetsTwoCanReturnTwoRegions(): void
    {
        $snip    = new Snippeter(windowSize: 40, maxSnippets: 2);
        $text    = 'fox here ' . str_repeat('gap filler word here ', 15) . 'fox there';
        $result  = $snip->snippet('fox', $text);
        // Two non-adjacent matches — expect the joining ellipsis to appear.
        $this->assertStringContainsString('…', $result);
    }

    // --- snippetMany ---

    public function testSnippetManyAppliesQueryToEachField(): void
    {
        $snip   = new Snippeter(windowSize: 200);
        $prefix = str_repeat('filler ', 5);
        $fields = [
            'title' => $prefix . 'fast sedan review',
            'body'  => $prefix . 'the sedan is really fast on the highway',
        ];
        $result = $snip->snippetMany('sedan', $fields);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('body', $result);
        $this->assertStringContainsString('sedan', $result['title']);
        $this->assertStringContainsString('sedan', $result['body']);
    }

    public function testSnippetManyPreservesFieldKeys(): void
    {
        $snip   = new Snippeter();
        $fields = ['alpha' => 'some text', 'beta' => 'other text'];
        $result = $snip->snippetMany('text', $fields);
        $this->assertSame(['alpha', 'beta'], array_keys($result));
    }

    public function testSnippetManyEmptyFieldReturnsEmptyString(): void
    {
        $snip   = new Snippeter();
        $result = $snip->snippetMany('fox', ['title' => '', 'body' => 'the fox ran']);
        $this->assertSame('', $result['title']);
        $this->assertStringContainsString('fox', $result['body']);
    }

    public function testSnippetManyEmptyFieldsReturnsEmptyArray(): void
    {
        $snip = new Snippeter();
        $this->assertSame([], $snip->snippetMany('fox', []));
    }

    // --- Unicode ---

    public function testUnicodeCjkSnippetLengthMeasuredInCharacters(): void
    {
        // CJK characters are 3 bytes each; window must be measured in chars, not bytes.
        $snip    = new Snippeter(windowSize: 20);
        $text    = str_repeat('这个 ', 30) . '目标 ' . str_repeat('文字 ', 30);
        $result  = $snip->snippet('目标', $text);
        $clean   = str_replace(['… ', ' …', '…'], '', $result);
        $this->assertLessThanOrEqual(20 + 5, mb_strlen($clean, 'UTF-8'));
    }

    public function testUnicodeMultibyteTermMatchedInWindow(): void
    {
        $snip   = new Snippeter(windowSize: 80);
        $prefix = str_repeat('слово ', 10);
        $text   = $prefix . 'быстрый лис прыгнул';
        $result = $snip->snippet('быстрый', $text);
        $this->assertStringContainsString('быстрый', $result);
    }

    public function testBoundarySnapProducesValidUtf8(): void
    {
        $snip   = new Snippeter(windowSize: 30);
        $text   = str_repeat('слово ', 20) . 'цель слово ' . str_repeat('слово ', 20);
        $result = $snip->snippet('цель', $text);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }
}
