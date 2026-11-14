<?php

declare(strict_types=1);

namespace Fuzor\Tests\Support;

use Fuzor\Stemmer;

/** A stemmer Fuzor cannot create: its constructor needs an argument. */
final class StemmerWithArgument implements Stemmer
{
    public function __construct(private string $suffix)
    {
    }

    public function id(): string
    {
        return 'argument@1';
    }

    public function stem(string $word): string
    {
        return str_ends_with($word, $this->suffix) ? substr($word, 0, -strlen($this->suffix)) : $word;
    }
}
