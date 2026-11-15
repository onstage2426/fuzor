<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
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
        // Setting variables to null closes the PDO handles they hold (a test then writes the file).
        \Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector::class,
        // Fully qualified return types on short closures are noise.
        \Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector::class,
        // `!== null` says the same as `instanceof` with a fully qualified class name, and
        // dirname(__DIR__) reads better than a `/../` path.
        \Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector::class,
        \Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector::class,
        // `!(capped && distinct)` states the exception; its expanded form hides it.
        \Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector::class,
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
