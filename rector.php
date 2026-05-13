<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        __DIR__ . '/src/Stemmers',
        // PHPStan stubs type preg_match_all PREG_OFFSET_CAPTURE offsets as int<-1, max>;
        // the @var casts narrowing to int<0, max> for full-match arrays are load-bearing.
        \Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector::class,
        \Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector::class,
    ])
    ->withPhpSets(php85: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
    ]);
