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
    | Included Paths
    |--------------------------------------------------------------------------
    | Globs matching files that should be intercepted and type-checked.
    */
    'include' => [
        'src/**',
        'app/**',
        'internals/**',
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
