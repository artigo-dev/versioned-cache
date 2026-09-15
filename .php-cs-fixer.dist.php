<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/benchmarks', __DIR__.'/examples'])
;

return (new PhpCsFixer\Config())
    // Sequential on purpose. The parallel runner reports a clean tree on
    // Windows while CI, running the same version against the same files,
    // finds plenty - and a check that is wrong in one direction only is worse
    // than a slow one. Fourteen files take under a second either way.
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::sequential())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder)
;
