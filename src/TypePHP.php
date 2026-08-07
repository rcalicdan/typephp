<?php

declare(strict_types=1);

namespace TypePHP;

use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;
use TypePHP\Resolver\TemplateManager;

final class TypePHP
{
    /**
     * Boots TypePHP and registers the custom StreamWrapper protocol.
     */
    public static function boot(): void
    {
        StreamWrapper::register(Config::get());
    }

    /**
     * Returns the bound generic type for a template parameter on an object instance (Reified Generics).
     * If no template name is specified on a single-template class, returns the bound type automatically.
     */
    public static function getGenericType(object $instance, ?string $templateName = null): ?string
    {
        $types = self::getGenericTypes($instance);
        if (\count($types) === 0) {
            return null;
        }


        if ($templateName !== null && isset($types[$templateName])) {
            return $types[$templateName];
        }

        if (count($types) === 1) {
            return reset($types);
        }

        return $types['T'] ?? null;
    }

    /**
     * Returns all bound generic template parameters for an object instance as a key-value array.
     *
     * @return array<string, string>
     */
    public static function getGenericTypes(object $instance): array
    {
        $boundNodes = TemplateManager::getBoundTemplatesForInstance($instance);
        $types = [];

        foreach ($boundNodes as $name => $node) {
            $types[$name] = (string) $node;
        }

        return $types;
    }

    /**
     * Returns the current resolved global configuration settings.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return Config::get();
    }

    /**
     * Dynamically overrides configuration settings at runtime.
     * Useful for test environments and custom setup scripts.
     *
     * @param array<string, mixed> $config
     */
    public static function setConfig(array $config): void
    {
        Config::set($config);
    }

    /**
     * Resets the configuration cache back to typephp.php defaults.
     * Useful for test isolation between test runs.
     */
    public static function resetConfig(): void
    {
        Config::reset();
    }
}