<?php

namespace Fuzor\Tests;

use Fuzor\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    // --- ASCII fast-path ---

    public function testAsciiLowercases(): void
    {
        $this->assertSame(['hello', 'world'], Tokenizer::split('Hello World'));
    }

    public function testAsciiStripsNonWordChars(): void
    {
        $this->assertSame(['foo', 'bar', 'baz'], Tokenizer::split('foo, bar! baz.'));
    }

    public function testAsciiPreservesAtSign(): void
    {
        $this->assertSame(['user@example', 'com'], Tokenizer::split('user@example.com'));
    }

    public function testAsciiPreservesUnderscoreAndDigits(): void
    {
        $tokens = Tokenizer::split('bmw_3 series');
        $this->assertContains('bmw_3', $tokens);
        $this->assertContains('series', $tokens);
    }

    public function testAsciiSplitsOnHyphen(): void
    {
        $this->assertSame(['e', 'mail'], Tokenizer::split('e-mail'));
    }

    public function testAsciiEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], Tokenizer::split(''));
    }

    public function testAsciiWhitespaceOnlyReturnsEmptyArray(): void
    {
        $this->assertSame([], Tokenizer::split('   '));
    }

    public function testAsciiNumericTokens(): void
    {
        $this->assertSame(['3', 'series'], Tokenizer::split('3 series'));
    }

    // --- Unicode fallback ---

    public function testUnicodeLowercases(): void
    {
        $tokens = Tokenizer::split('Ünïcödé');
        $this->assertSame(['ünïcödé'], $tokens);
    }

    public function testUnicodeStripsNonLetterChars(): void
    {
        $tokens = Tokenizer::split('café, résumé!');
        $this->assertSame(['café', 'résumé'], $tokens);
    }

    public function testUnicodePreservesAtSign(): void
    {
        $tokens = Tokenizer::split('ünïcödé@example.com');
        $this->assertContains('ünïcödé@example', $tokens);
    }

    public function testUnicodeSplitsOnHyphen(): void
    {
        $this->assertSame(['café', 'résumé'], Tokenizer::split('café-résumé'));
    }

    public function testUnicodeMixedScripts(): void
    {
        $tokens = Tokenizer::split('BMW 宝马');
        $this->assertContains('bmw', $tokens);
        $this->assertContains('宝马', $tokens);
    }

    // --- tokenizeWithOffsets ---

    public function testTokenizeWithOffsetsAsciiReturnsCorrectOffsets(): void
    {
        $text   = 'Hello World';
        $result = Tokenizer::splitWithOffsets($text);
        $this->assertCount(2, $result);
        foreach ($result as [$token, $offset]) {
            $this->assertSame($token, strtolower(substr($text, $offset, strlen($token))));
        }
    }

    public function testTokenizeWithOffsetsTokensAreLowercased(): void
    {
        $result = Tokenizer::splitWithOffsets('Hello World');
        $this->assertSame('hello', $result[0][0]);
        $this->assertSame('world', $result[1][0]);
    }

    public function testTokenizeWithOffsetsEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], Tokenizer::splitWithOffsets(''));
    }

    public function testTokenizeWithOffsetsUnicodeReturnsCorrectByteOffsets(): void
    {
        // 'café' is 5 bytes (é is 2 bytes); 'résumé' follows after a space.
        $text   = 'café résumé';
        $result = Tokenizer::splitWithOffsets($text);
        $this->assertCount(2, $result);
        foreach ($result as [$token, $offset]) {
            // substr() uses bytes; mb_strtolower matches what tokenizeWithOffsets stores.
            $this->assertSame($token, mb_strtolower(substr($text, $offset, strlen($token)), 'UTF-8'));
        }
    }

    public function testTokenizeWithOffsetsFirstTokenOffsetIsZeroWhenNoLeadingDelimiter(): void
    {
        $result = Tokenizer::splitWithOffsets('hello world');
        $this->assertSame(0, $result[0][1]);
    }

    public function testTokenizeWithOffsetsSecondTokenOffsetIsCorrect(): void
    {
        $text   = 'foo bar';
        $result = Tokenizer::splitWithOffsets($text);
        $this->assertSame(4, $result[1][1]);
    }

    // --- ngram ---

    public function testNgramBigramProducesCorrectTokens(): void
    {
        $this->assertSame(['轿车', '车测', '测试'], Tokenizer::ngram('轿车测试', 2));
    }

    public function testNgramTrigramProducesCorrectTokens(): void
    {
        // 'กรุงเทพ' = 7 codepoints → 5 trigrams
        $result = Tokenizer::ngram('กรุงเทพ', 3);
        $this->assertCount(5, $result);
        $this->assertSame('กรุ', $result[0]);
        $this->assertSame('เทพ', $result[4]);
    }

    public function testNgramLowercasesInput(): void
    {
        $result = Tokenizer::ngram('AB', 2);
        $this->assertSame(['ab'], $result);
    }

    public function testNgramIncludesUnigrams(): void
    {
        $result = Tokenizer::ngram('东京', 2, includeUnigrams: true);
        $this->assertContains('东', $result);
        $this->assertContains('京', $result);
        $this->assertContains('东京', $result);
    }

    public function testNgramShorterThanWindowProducesNoGrams(): void
    {
        $this->assertSame([], Tokenizer::ngram('京', 2));
    }

    public function testNgramSingleUnigram(): void
    {
        $result = Tokenizer::ngram('京', 2, includeUnigrams: true);
        $this->assertSame(['京'], $result);
    }

    public function testNgramWithOffsetsReturnsCorrectByteOffsets(): void
    {
        // Each CJK character is 3 bytes in UTF-8.
        // '轿车测试': 轿=0, 车=3, 测=6, 试=9
        // bigrams: ['轿车',0], ['车测',3], ['测试',6]
        $result = Tokenizer::ngramWithOffsets('轿车测试', 2);
        $this->assertCount(3, $result);
        $this->assertSame('轿车', $result[0][0]);
        $this->assertSame(0, $result[0][1]);
        $this->assertSame('车测', $result[1][0]);
        $this->assertSame(3, $result[1][1]);
        $this->assertSame('测试', $result[2][0]);
        $this->assertSame(6, $result[2][1]);
    }

    public function testNgramWithOffsetsOffsetMatchesSubstr(): void
    {
        $text   = '轿车测试';
        $result = Tokenizer::ngramWithOffsets($text, 2);
        foreach ($result as [$token, $offset]) {
            $this->assertSame($token, mb_strtolower(substr($text, $offset, strlen($token)), 'UTF-8'));
        }
    }

    public function testNgramSizeReturnsCorrectSizes(): void
    {
        $this->assertSame(2, Tokenizer::ngramSize('zh'));
        $this->assertSame(2, Tokenizer::ngramSize('ja'));
        $this->assertSame(2, Tokenizer::ngramSize('ko'));
        $this->assertSame(3, Tokenizer::ngramSize('th'));
        $this->assertSame(0, Tokenizer::ngramSize('en'));
        $this->assertSame(0, Tokenizer::ngramSize('fr'));
    }

    public function testIsNgramTokenReturnsTrueForCjk(): void
    {
        $this->assertTrue(Tokenizer::isNgramToken('轿车'));
        $this->assertTrue(Tokenizer::isNgramToken('東京'));
        $this->assertTrue(Tokenizer::isNgramToken('서울'));
    }

    public function testIsNgramTokenReturnsTrueForThai(): void
    {
        $this->assertTrue(Tokenizer::isNgramToken('กรุง'));
    }

    public function testIsNgramTokenReturnsFalseForLatin(): void
    {
        $this->assertFalse(Tokenizer::isNgramToken('sedan'));
        $this->assertFalse(Tokenizer::isNgramToken('café'));
    }

    // --- tokenize ---

    public function testTokenizeNonNgramLanguageReturnsSplitTokens(): void
    {
        // 'de' is not in NGRAM_SCRIPTS so ?? 0 kicks in; CJK text must be returned
        // as a single split token, not broken into n-grams.
        $this->assertSame(['轿车'], Tokenizer::tokenize('轿车', 'de'));
    }

    public function testTokenizeIncludeUnigramsFalseOmitsUnigrams(): void
    {
        // With includeUnigrams=false the condition is (false && n===2) = false.
        // The || mutation turns this into (false || true) = true, adding unigrams.
        $this->assertSame(['轿车', '车测', '测试'], Tokenizer::tokenize('轿车测试', 'zh', false));
    }

    // --- tokenizeWithOffsets ---

    public function testTokenizeWithOffsetsNonNgramLanguageReturnsSplitOffsets(): void
    {
        // Non-ngram language must fall back to splitWithOffsets, not ngramWithOffsets.
        $this->assertSame([['hello', 0], ['world', 6]], Tokenizer::tokenizeWithOffsets('hello world', 'de'));
    }

    // --- splitWithOffsets uppercase Unicode ---

    public function testSplitWithOffsetsLowercasesUnicodeUppercase(): void
    {
        $result = Tokenizer::splitWithOffsets('CAFÉ');
        $this->assertSame('café', $result[0][0]);
    }

    // --- ngram uppercase Unicode ---

    public function testNgramLowercasesUnicodeUppercase(): void
    {
        $this->assertSame(['äö', 'öü'], Tokenizer::ngram('ÄÖÜ', 2));
    }

    // --- ngramWithOffsets uppercase Unicode ---

    public function testNgramWithOffsetsLowercasesUnicodeUppercase(): void
    {
        $result = Tokenizer::ngramWithOffsets('ÄÖÜ', 2);
        $this->assertSame('äö', $result[0][0]);
        $this->assertSame('öü', $result[1][0]);
    }
}
