<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        // PHPStan stubs type preg_match_all PREG_OFFSET_CAPTURE offsets as int<-1, max>;
        // the @var casts narrowing to int<0, max> for full-match arrays are load-bearing.
        \Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector::class,
        \Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector::class,
        // Static helpers are static on purpose: the constructor validates the schema before the
        // index exists, and PHPUnit data providers must be static.
        \Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector::class,
        // Promotion drops the @var list<…> types of the public result properties.
        \Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class,
        // Public value objects keep `readonly` on each property, as the docs describe them.
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        \Rector\CodeQuality\Rector\Class_\RemoveReadonlyPropertyVisibilityOnReadonlyClassRector::class,
        // Named arguments are ordered for reading (schemas, options), not by parameter position.
        \Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector::class,
        // `if (a || b) continue;` reads better than two ifs.
        \Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector::class,
        // array_keys() copies the array: these loops run over every candidate of a search.
        \Rector\CodeQuality\Rector\Foreach_\UnusedForeachValueToArrayKeysRector::class,
        // Setting variables to null closes the PDO handles they hold (a test then writes the file).
        \Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector::class,
        // Noise in short closures and tests: fully qualified return types, `$counter` variables,
        // (string) casts of values that are strings.
        \Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector::class,
        \Rector\CodeQuality\Rector\For_\ForRepeatedCountToOwnVariableRector::class,
        \Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector::class,
    ])
    // src/Index.php and tests/IndexTest.php are over 13,000 lines each: one of them alone takes a
    // worker past the default 120 s, so each worker gets one file and more time.
    ->withParallel(timeoutSeconds: 900, jobSize: 1)
    ->withPhpSets(php85: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
        SetList::EARLY_RETURN,
    ]);
