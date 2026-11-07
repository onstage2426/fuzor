<?php

declare(strict_types=1);

namespace Fuzor\Tests;

use Fuzor\Config;
use Fuzor\Limits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LimitsTest extends TestCase
{
    public function testFastIsTheDefault(): void
    {
        $this->assertEquals(new Limits(), Limits::fast());
        $this->assertEquals(new Limits(), (new Config())->limits);
        $this->assertSame(500, Limits::fast()->candidatesPerWord);
        $this->assertSame(50_000, Limits::fast()->facetCountDocs);
        $this->assertSame(1_000, Limits::fast()->totalHits);
    }

    public function testExactLiftsTheCapsButKeepsTotalHits(): void
    {
        $exact = Limits::exact();
        $this->assertGreaterThan(1_000_000_000, $exact->candidatesPerWord);
        $this->assertGreaterThan(1_000_000_000, $exact->facetCountDocs);
        $this->assertSame(1_000, $exact->totalHits);
        $this->assertSame(5_000, Limits::exact(totalHits: 5_000)->totalHits);
    }

    /** @return array<string, array{array<string, int>, string}> */
    public static function invalidLimits(): array
    {
        return [
            'candidatesPerWord 0' => [['candidatesPerWord' => 0], 'candidatesPerWord must be >= 1.'],
            'facetCountDocs -1'   => [['facetCountDocs' => -1], 'facetCountDocs must be >= 1.'],
            'totalHits 0'         => [['totalHits' => 0], 'totalHits must be >= 1.'],
        ];
    }

    /** @param array<string, int> $args */
    #[DataProvider('invalidLimits')]
    public function testEachLimitMustBePositive(array $args, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new Limits(...$args);
    }

    public function testExactTotalHitsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Limits::exact(totalHits: 0);
    }
}
