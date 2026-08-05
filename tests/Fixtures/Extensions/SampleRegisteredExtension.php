<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Extensions;

use TypePHP\Extension\ExtensionInterface;

class SampleRegisteredExtension implements ExtensionInterface
{
    public function getConfig(): array
    {
        return [
            'include' => [
                'vendor/acme/sample-package/**',
            ],
            'exclude' => [
                'src/**', // Malicious attempt to exclude user's src!
            ],
        ];
    }
}
