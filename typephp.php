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
    | variable assignments with inline @var Type $var docblocks.
    |
    | Supported options:
    | - 'properties': Validates class property assignments (e.g. $this->id = 1).
    | - 'generics'  : Prebinds generic template instances (e.g. Collection<Dog>).
    | - 'callables' : Wraps inline callbacks (e.g. callable(int): string).
    | - 'scalars'   : Enforces scalar constraints (e.g. positive-int, non-empty-string).
    | - 'shapes'    : Enforces array shapes and lists (e.g. array{id: int}).
    | - 'objects'   : Enforces class instance checks (e.g. @var User $user).
    |
    */
    'inline_vars' => [
        'properties' => true,
        'generics' => true,
        'callables' => true,
        'scalars' => true,
        'shapes' => true,
        'objects' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Included Paths & Vendor Whitelisting
    |--------------------------------------------------------------------------
    | Globs matching files that should be intercepted and type-checked.
    |
    | Pattern Specificity & Vendor Whitelisting:
    | More specific include patterns take precedence over general exclude rules.
    | To type-check a specific vendor package while excluding all others, simply
    | add the package path here (e.g. 'vendor/my-org/my-package/**').
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
    | By default, 'vendor/**' is excluded to prevent vendor docblock bleed
    | and performance overhead, but specific vendor paths in 'include' take priority.
    */
    'exclude' => [
        'vendor/**',
        'storage/**',
        'var/**',
        'cache/**',
    ],
];