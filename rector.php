<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Set\SymfonySetList;

/*
 * Rector configuration for the crashler project.
 *
 * Starts deliberately minimal: PHP 8.5 + Symfony quality refactors only.
 * Run via `composer rector:dry` to preview, `composer rector` to apply.
 *
 * The pre-commit hook does NOT run Rector — refactor rules are too
 * aggressive for the hot path. Rector is on-demand only.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/var',
        __DIR__.'/vendor',
        __DIR__.'/tools',
        __DIR__.'/migrations',
    ])
    ->withPhpSets(php85: true)
    ->withSets([
        SymfonySetList::SYMFONY_CODE_QUALITY,
    ])
    ->withImportNames(removeUnusedImports: true);
