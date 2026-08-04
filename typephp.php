<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Caching
    |--------------------------------------------------------------------------
    | When enabled, transformed PHP files are cached on disk for speed.
    | Set to false to run AST transformations purely in RAM (php://memory).
    */
    'cache' => true,

    /*
    |--------------------------------------------------------------------------
    | Inline Variable Validation (@var $x = ...)
    |--------------------------------------------------------------------------
    | Fine-grained control over which type categories are enforced on local
    | variable assignments with inline  @var Type $var  docblocks.
    |
    */
    'inline_vars' => [
        'generics'  => true,  // Prebinds Generic instances (e.g. Collection<Dog>)
        'callables' => true,  // Wraps inline callables (e.g. callable(int): string)
        'scalars'   => true, // Enforces scalar constraints (e.g. positive-int)
        'shapes'    => false, // Enforces array shapes (e.g. array{id: int})
        'objects'   => false, // Enforces class instances (e.g. /** @var User $user */)
    ],

    /*
    |--------------------------------------------------------------------------
    | Included Paths
    |--------------------------------------------------------------------------
    | Globs matching files that should be intercepted and type-checked.
    */
    'include' => [
        'src/**',
        'app/**',
        'internals/**',
        'tests/**',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    | Globs matching files that should be ignored by the type checker.
    */
    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];
