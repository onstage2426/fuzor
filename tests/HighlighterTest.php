<?php

namespace Fuzor\Tests;

use Fuzor\Highlighter;
use PHPUnit\Framework\TestCase;

class HighlighterTest extends TestCase
{
    // --- basic term matching ---

    public function testHighlightWrapsMatchedTerm(): void
    {
        $hl = new Highlighter();
        $this->assertSame('Find the <mark>sedan</mark> here.', $hl->highlight('sedan', 'Find the sedan here.'));
    }

    public function testHighlightIsCaseInsensitive(): void
    {
        $hl = new Highlighter();
        $this->assertSame('<mark>SEDAN</mark>', $hl->highlight('sedan', 'SEDAN'));
        $this->assertSame('<mark>Sedan</mark>', $hl->highlight('SEDAN', 'Sedan'));
    }

    public function testHighlightMultipleTerms(): void
    {
        $hl = new Highlighter();
        $result = $hl->highlight('fast sedan', 'A fast sedan.');
        $this->assertSame('A <mark>fast</mark> <mark>sedan</mark>.', $result);
    }

    public function testHighlightNoMatchReturnsOriginal(): void
    {
        $hl = new Highlighter();
        $this->assertSame('no match here', $hl->highlight('suv', 'no match here'));
    }

    public function testHighlightEmptyPhraseReturnsOriginal(): void
    {
        $hl = new Highlighter();
        $this->assertSame('sedan car', $hl->highlight('', 'sedan car'));
    }

    // --- word boundaries ---

    public function testHighlightDoesNotMatchInsideWord(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        // 'car' must not match inside 'scarce'.
        $this->assertSame('scarce', $hl->highlight('car', 'scarce'));
    }

    public function testHighlightMatchesTermAtStartOfString(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        $this->assertSame('<mark>sedan</mark> car', $hl->highlight('sedan', 'sedan car'));
    }

    public function testHighlightMatchesTermAtEndOfString(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        $this->assertSame('fast <mark>sedan</mark>', $hl->highlight('sedan', 'fast sedan'));
    }

    // --- asYouType prefix ---

    public function testAsYouTypePrefixHighlightsFullWord(): void
    {
        $hl = new Highlighter();
        // 'merc' prefix should highlight the full word 'Mercedes'.
        $this->assertSame('<mark>Mercedes</mark> Benz', $hl->highlight('merc', 'Mercedes Benz'));
    }

    public function testAsYouTypeOnlyAppliesToLastToken(): void
    {
        $hl = new Highlighter();
        // 'fast' is NOT the last token: must match exactly, not as a prefix.
        // 'sed' IS the last token: must expand to 'sedan'.
        $result = $hl->highlight('fast sed', 'A fast sedan.');
        $this->assertSame('A <mark>fast</mark> <mark>sedan</mark>.', $result);
    }

    public function testAsYouTypeDisabledMatchesExactOnly(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        // 'merc' must not expand to 'Mercedes' when asYouType is off.
        $this->assertSame('Mercedes Benz', $hl->highlight('merc', 'Mercedes Benz'));
    }

    public function testAsYouTypeExactTermStillMatchesFullWord(): void
    {
        $hl = new Highlighter();
        // Even with asYouType, an exact last token must still match when the text matches exactly.
        $this->assertSame('<mark>sedan</mark>', $hl->highlight('sedan', 'sedan'));
    }

    // --- custom tags ---

    public function testCustomOpenCloseTags(): void
    {
        $hl = new Highlighter('<b>', '</b>');
        $this->assertSame('a <b>sedan</b> car', $hl->highlight('sedan', 'a sedan car'));
    }

    // --- highlightMany ---

    public function testHighlightManyAppliesAcrossFields(): void
    {
        $hl     = new Highlighter();
        $result = $hl->highlightMany('sedan', ['title' => 'Fast sedan', 'body' => 'A nice sedan car.']);
        $this->assertSame('<mark>sedan</mark>', substr($result['title'], 5));
        $this->assertStringContainsString('<mark>sedan</mark>', $result['body']);
    }

    public function testHighlightManyEmptyPhraseReturnsFieldsUnchanged(): void
    {
        $hl     = new Highlighter();
        $fields = ['title' => 'Fast sedan', 'body' => 'A car.'];
        $this->assertSame($fields, $hl->highlightMany('', $fields));
    }

    public function testHighlightManyPreservesFieldKeys(): void
    {
        $hl     = new Highlighter();
        $result = $hl->highlightMany('sedan', ['title' => 'sedan', 'body' => 'sedan']);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('body', $result);
    }

    // --- Unicode ---

    public function testHighlightUnicodeText(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        $this->assertSame('Un <mark>café</mark> chaud.', $hl->highlight('café', 'Un café chaud.'));
    }

    public function testHighlightUnicodeCaseInsensitive(): void
    {
        $hl = new Highlighter('<mark>', '</mark>', false);
        $this->assertSame('<mark>CAFÉ</mark>', $hl->highlight('café', 'CAFÉ'));
    }

    // --- CJK / ngram ---

    public function testCjkHighlightsBigramInsideLongerText(): void
    {
        // Without language, word-boundary regex prevents matches inside continuous CJK text.
        // With language: 'zh', the plain-substring regex must match '轿车' within the sentence.
        $hl   = new Highlighter(language: 'zh');
        $text = '这是一辆轿车测试';
        $this->assertStringContainsString('<mark>轿车</mark>', $hl->highlight('轿车', $text));
    }

    public function testCjkHighlightWorksEvenWithoutLanguage(): void
    {
        // The highlighter classifies tokens via isNgramToken() regardless of language,
        // so CJK substring matching works even without setting language: 'zh'.
        $hl   = new Highlighter();
        $text = '这是一辆轿车测试';
        $this->assertStringContainsString('<mark>轿车</mark>', $hl->highlight('轿车', $text));
    }

    public function testCjkHighlightMixedQueryHighlightsBothScripts(): void
    {
        $hl   = new Highlighter(language: 'zh');
        $text = 'BMW 轿车测试';
        $out  = $hl->highlight('bmw 轿车', $text);
        $this->assertStringContainsString('<mark>BMW</mark>', $out);
        $this->assertStringContainsString('<mark>轿车</mark>', $out);
    }

    public function testCjkBigramQueryDoesNotHighlightIndividualChars(): void
    {
        $hl = new Highlighter(language: 'zh');
        $this->assertSame('这是一辆<mark>轿车</mark>测试', $hl->highlight('轿车', '这是一辆轿车测试'));
    }
}
