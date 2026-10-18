<?php

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\Language;
use Fuzor\Stemmers\SnowballEnglish;
use Fuzor\Stemmers\SnowballStemmer;
use Fuzor\Tokenizer;
use PHPUnit\Framework\TestCase;

class LanguageTest extends TestCase
{
    // --- Language::all ---

    public function testAllReturnsArrayOfTagToName(): void
    {
        $languages = Language::all();
        $this->assertNotEmpty($languages);
        $this->assertSame('English', $languages['en']);
        $this->assertSame('French', $languages['fr']);
        $this->assertSame('Chinese', $languages['zh']);
    }

    public function testAllContainsNgramLanguagesWithoutAStemmer(): void
    {
        $languages = Language::all();
        foreach (['zh', 'ja', 'ko', 'th'] as $tag) {
            $this->assertArrayHasKey($tag, $languages);
        }
    }

    public function testAllLeavesOutLanguagesWithNothingToApply(): void
    {
        // Neither a stemmer nor n-grams: the tag would change nothing.
        $languages = Language::all();
        $this->assertArrayNotHasKey('af', $languages);
        $this->assertArrayNotHasKey('cs', $languages);
        foreach (array_keys($languages) as $tag) {
            $this->assertTrue(Language::hasStemmer($tag) || Tokenizer::ngramSize($tag) > 0, $tag);
        }
    }

    public function testAllContainsStemmerLanguages(): void
    {
        $languages = Language::all();
        $this->assertArrayHasKey('ne', $languages);
        $this->assertArrayHasKey('sr', $languages);
        $this->assertArrayHasKey('ta', $languages);
        $this->assertArrayHasKey('yi', $languages);
    }

    public function testAllValuesAreNonEmptyStrings(): void
    {
        foreach (Language::all() as $tag => $name) {
            $this->assertNotEmpty($tag);
            $this->assertNotEmpty($name);
        }
    }

    // --- Language::supports ---

    public function testSupportsReturnsTrueForKnownLanguage(): void
    {
        $this->assertTrue(Language::supports('en'));
        $this->assertTrue(Language::supports('zh'));
        $this->assertTrue(Language::supports('ne'));
    }

    public function testSupportsReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Language::supports('xx'));
        $this->assertFalse(Language::supports(''));
        $this->assertFalse(Language::supports('en-GB'));
    }

    // --- Language::hasStemmer ---

    public function testHasStemmerReturnsTrueForLanguagesWithStemmer(): void
    {
        $this->assertTrue(Language::hasStemmer('en'));
        $this->assertTrue(Language::hasStemmer('fr'));
        $this->assertTrue(Language::hasStemmer('ne'));
    }

    public function testHasStemmerReturnsFalseForNgramLanguages(): void
    {
        $this->assertFalse(Language::hasStemmer('zh'));
        $this->assertFalse(Language::hasStemmer('ja'));
    }

    public function testHasStemmerReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Language::hasStemmer('xx'));
    }

    // --- Language::stemmerClass ---

    public function testStemmerClassReturnsClassStringForKnownLanguage(): void
    {
        $class = Language::stemmerClass('en');
        $this->assertNotNull($class);
        $this->assertSame(SnowballEnglish::class, $class);
    }

    public function testStemmerClassReturnsNullForNgramLanguage(): void
    {
        $this->assertNull(Language::stemmerClass('zh'));
    }

    public function testStemmerClassReturnsNullForUnknownLanguage(): void
    {
        $this->assertNull(Language::stemmerClass('xx'));
    }

    public function testStemmerClassIsInstantiable(): void
    {
        $class = Language::stemmerClass('fr');
        $this->assertNotNull($class);
        $instance = new $class();
        $this->assertInstanceOf(SnowballStemmer::class, $instance);
    }
}
