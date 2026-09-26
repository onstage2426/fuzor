<?php

namespace Fuzor\Tests;

use Fuzor\BooleanParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BooleanParserTest extends TestCase
{
    /** @return list<string> */
    private function postfix(string $query): array
    {
        // Callers prepend '|' as the OR identity (see Index::searchBoolean()).
        return BooleanParser::toPostfix('|' . $query)[0];
    }

    /**
     * Well-formed queries: output locked to what the parser produced before the lexer rewrite.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function wellFormedQueries(): array
    {
        $cases = [
            'sedan coupe' => ['sedan', 'coupe', '&', '|'],
            'sedan or suv' => ['sedan', '|', 'suv', '|'],
            'sedan -bmw' => ['sedan', 'bmw', '~', '&', '|'],
            'php&(laravel or nodejs)' => ['php', 'laravel', 'nodejs', '|', '&', '|'],
            '( café or latté )' => ['café', 'latté', '|', '|'],
            '(sedan or coupe) -electric' => ['sedan', 'coupe', '|', 'electric', '~', '&', '|'],
            'alpha&(beta or gamma)&delta' => ['alpha', 'beta', 'gamma', '|', '&', 'delta', '&', '|'],
            'php' => ['php', '|'],
            'a ~b' => ['a', 'b', '~', '&', '|'],
            'shirt|jeans' => ['shirt', '|', 'jeans', '|'],
            'NAÏVE' => ['naïve', '|'],
            'php -laravel' => ['php', 'laravel', '~', '&', '|'],
            'sedan or coupe truck' => ['sedan', '|', 'coupe', 'truck', '&', '|'],
            'a&~b' => ['a', 'b', '~', '&', '|'],
            '~a' => ['a', '~', '|'],
            'a|(b&c)' => ['a', '|', 'b', 'c', '&', '|'],
            'e-mail sedan' => ['e-mail', 'sedan', '&', '|'],
        ];
        $out = [];
        foreach ($cases as $query => $postfix) {
            $out[$query] = [$query, $postfix];
        }
        return $out;
    }

    /** @param list<string> $expected */
    #[DataProvider('wellFormedQueries')]
    public function testWellFormedQueriesKeepTheirPostfix(string $query, array $expected): void
    {
        $this->assertSame($expected, $this->postfix($query));
    }

    public function testSpacingAroundOperatorsDoesNotChangeMeaning(): void
    {
        $or = $this->postfix('shirt|jeans');
        foreach (['shirt | jeans', 'shirt or jeans', "shirt  |  jeans", "shirt\t|\njeans", 'shirt OR jeans'] as $q) {
            $this->assertSame($or, $this->postfix($q), $q);
        }
        $and = $this->postfix('shirt jeans');
        foreach (['shirt & jeans', 'shirt&jeans', 'shirt  jeans', "shirt\tjeans", 'shirt && jeans'] as $q) {
            $this->assertSame($and, $this->postfix($q), $q);
        }
        $not = $this->postfix('shirt -jeans');
        foreach (['shirt ~jeans', 'shirt ~ jeans', 'shirt & ~jeans', 'shirt ~~jeans'] as $q) {
            $this->assertSame($not, $this->postfix($q), $q);
        }
    }

    public function testLeadingAndTrailingNoiseIsIgnored(): void
    {
        $shirt = ['shirt', '|'];
        $noisy = [
            'shirt ', ' shirt', 'shirt |', '| shirt', 'shirt or', 'or shirt', 'shirt &', 'shirt ~', '(shirt', 'shirt)',
        ];
        foreach ($noisy as $q) {
            $this->assertSame($shirt, $this->postfix($q), $q);
        }
    }

    public function testWordFollowedByGroupIsImplicitAnd(): void
    {
        $this->assertSame(['a', 'b', 'c', '|', '&', '|'], $this->postfix('a (b | c)'));
        $this->assertSame(['a', 'b', '|', 'c', '&', '|'], $this->postfix('(a | b) c'));
    }

    public function testParenthesesAreBalanced(): void
    {
        $this->assertSame(['shirt', 'jeans', '|', '|'], $this->postfix('(shirt | jeans'));
        $this->assertSame(['shirt', 'jeans', '&', '|'], $this->postfix('shirt) (jeans'));
        $this->assertSame(['shirt', '|'], $this->postfix('shirt ()'));
        $this->assertSame(['|'], $this->postfix('( )'));
    }

    public function testRunsWithoutSearchableCharactersAreDropped(): void
    {
        $this->assertSame($this->postfix('shirt jeans'), $this->postfix('shirt - jeans'));
        $this->assertSame($this->postfix('shirt jeans'), $this->postfix('shirt + jeans'));
        $this->assertSame(['|'], $this->postfix('- + …'));
    }

    public function testHyphenInsideWordIsKept(): void
    {
        $this->assertSame(['e-mail', '|'], $this->postfix('e-mail'));
        $this->assertSame(['x', 'e-mail', '~', '&', '|'], $this->postfix('x -e-mail'));
    }

    public function testOperatorOnlyInputYieldsIdentityOnly(): void
    {
        foreach (['', '   ', '|', '&', '~', '-', 'or', '()'] as $q) {
            $this->assertSame(['|'], $this->postfix($q), json_encode($q) ?: '');
        }
    }

    public function testLastTermIsTheLastWord(): void
    {
        $this->assertSame('jeans', BooleanParser::toPostfix('|shirt | jeans ')[1]);
        $this->assertNull(BooleanParser::toPostfix('| ~ ')[1]);
    }
}
