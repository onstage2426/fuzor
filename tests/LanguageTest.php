<?php

namespace Fuzor\Tests;

use Fuzor\Index;
use Fuzor\Language;
use Fuzor\Stemmers\SnowballEnglish;
use Fuzor\Stemmers\SnowballStemmer;
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

    public function testAllContainsStopwordOnlyLanguages(): void
    {
        $languages = Language::all();
        // Languages with stopwords but no stemmer
        $this->assertArrayHasKey('af', $languages);
        $this->assertArrayHasKey('ja', $languages);
    }

    public function testAllContainsStemmerOnlyLanguages(): void
    {
        $languages = Language::all();
        // Languages with stemmer but no stopwords
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
        $this->assertTrue(Language::supports('ne')); // stemmer only
    }

    public function testSupportsReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Language::supports('xx'));
        $this->assertFalse(Language::supports(''));
        $this->assertFalse(Language::supports('en-GB'));
    }

    // --- Language::hasStopwords ---

    public function testHasStopwordsReturnsTrueForLanguagesWithStopwordList(): void
    {
        $this->assertTrue(Language::hasStopwords('en'));
        $this->assertTrue(Language::hasStopwords('fr'));
        $this->assertTrue(Language::hasStopwords('zh'));
    }

    public function testHasStopwordsReturnsFalseForStemmerOnlyLanguages(): void
    {
        $this->assertFalse(Language::hasStopwords('ne'));
        $this->assertFalse(Language::hasStopwords('sr'));
        $this->assertFalse(Language::hasStopwords('ta'));
        $this->assertFalse(Language::hasStopwords('yi'));
    }

    public function testHasStopwordsReturnsFalseForUnknownLanguage(): void
    {
        $this->assertFalse(Language::hasStopwords('xx'));
    }

    // --- Language::hasStemmer ---

    public function testHasStemmerReturnsTrueForLanguagesWithStemmer(): void
    {
        $this->assertTrue(Language::hasStemmer('en'));
        $this->assertTrue(Language::hasStemmer('fr'));
        $this->assertTrue(Language::hasStemmer('ne')); // stemmer only
    }

    public function testHasStemmerReturnsFalseForStopwordsOnlyLanguages(): void
    {
        $this->assertFalse(Language::hasStemmer('af'));
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

    public function testStemmerClassReturnsNullForStopwordsOnlyLanguage(): void
    {
        $this->assertNull(Language::stemmerClass('af'));
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

    // --- Index::languages ---

    public function testIndexLanguagesMatchesLanguageAll(): void
    {
        $this->assertSame(Language::all(), Index::languages());
    }
}
