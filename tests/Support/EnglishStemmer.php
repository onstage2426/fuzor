<?php

declare(strict_types=1);

namespace Fuzor\Tests\Support;

use Fuzor\Stemmer;
use Fuzor\Tests\Support\Snowball\SnowballEnglish;

/** Snowball English as a Stemmer, for the tests (Fuzor ships no stemmers). */
final class EnglishStemmer implements Stemmer
{
    private SnowballEnglish $snowball;

    public function __construct()
    {
        $this->snowball = new SnowballEnglish();
    }

    public function id(): string
    {
        return 'snowball-english@3.0.0';
    }

    public function stem(string $word): string
    {
        return $this->snowball->stemWord($word);
    }
}
