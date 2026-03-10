<?php

namespace Fuzor\Tests;

use Fuzor\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    // --- ASCII fast-path ---

    public function testAsciiLowercases(): void
    {
        $this->assertSame(['hello', 'world'], Tokenizer::tokenize('Hello World'));
    }

    public function testAsciiStripsNonWordChars(): void
    {
        $this->assertSame(['foo', 'bar', 'baz'], Tokenizer::tokenize('foo, bar! baz.'));
    }

    public function testAsciiPreservesAtSign(): void
    {
        $this->assertSame(['user@example', 'com'], Tokenizer::tokenize('user@example.com'));
    }

    public function testAsciiPreservesUnderscoreAndDigits(): void
    {
        $tokens = Tokenizer::tokenize('bmw_3 series');
        $this->assertContains('bmw_3', $tokens);
        $this->assertContains('series', $tokens);
    }

    public function testAsciiSplitsOnHyphen(): void
    {
        $this->assertSame(['e', 'mail'], Tokenizer::tokenize('e-mail'));
    }

    public function testAsciiEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], Tokenizer::tokenize(''));
    }

    public function testAsciiWhitespaceOnlyReturnsEmptyArray(): void
    {
        $this->assertSame([], Tokenizer::tokenize('   '));
    }

    public function testAsciiNumericTokens(): void
    {
        $this->assertSame(['3', 'series'], Tokenizer::tokenize('3 series'));
    }

    // --- Unicode fallback ---

    public function testUnicodeLowercases(): void
    {
        $tokens = Tokenizer::tokenize('Ünïcödé');
        $this->assertSame(['ünïcödé'], $tokens);
    }

    public function testUnicodeStripsNonLetterChars(): void
    {
        $tokens = Tokenizer::tokenize('café, résumé!');
        $this->assertSame(['café', 'résumé'], $tokens);
    }

    public function testUnicodePreservesAtSign(): void
    {
        $tokens = Tokenizer::tokenize('ünïcödé@example.com');
        $this->assertContains('ünïcödé@example', $tokens);
    }

    public function testUnicodeSplitsOnHyphen(): void
    {
        $this->assertSame(['café', 'résumé'], Tokenizer::tokenize('café-résumé'));
    }

    public function testUnicodeMixedScripts(): void
    {
        $tokens = Tokenizer::tokenize('BMW 宝马');
        $this->assertContains('bmw', $tokens);
        $this->assertContains('宝马', $tokens);
    }
}
