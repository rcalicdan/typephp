<?php

declare(strict_types=1);

namespace TypePHP\Contract;

/**
 * Resolves class, interface, trait, and method inheritance hierarchies from child to root.
 */
final class HierarchyResolver
{
    /**
     * Builds an array of ReflectionMethods representing the inheritance hierarchy from child to root.
     *
     * @return array<int, \ReflectionMethod>
     */
    public static function getMethodHierarchy(\ReflectionMethod $ref): array
    {
        $hierarchy = [$ref];
        $methodName = $ref->getName();
        $declaringClass = $ref->getDeclaringClass();

        $parent = $declaringClass->getParentClass();
        while ($parent !== false) {
            if ($parent->hasMethod($methodName)) {
                $hierarchy[] = $parent->getMethod($methodName);
            }
            $parent = $parent->getParentClass();
        }

        foreach ($declaringClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $hierarchy[] = $interface->getMethod($methodName);
            }
        }

        foreach ($declaringClass->getTraits() as $trait) {
            if ($trait->hasMethod($methodName)) {
                $hierarchy[] = $trait->getMethod($methodName);
            }
        }

        return $hierarchy;
    }

    /**
     * Builds an array of ReflectionClasses representing the inheritance hierarchy from child to root.
     *
     * @return array<int, \ReflectionClass>
     */
    public static function getClassHierarchy(\ReflectionClass $ref): array
    {
        $hierarchy = [$ref];

        $parent = $ref->getParentClass();
        while ($parent !== false) {
            $hierarchy[] = $parent;
            $parent = $parent->getParentClass();
        }

        foreach ($ref->getInterfaces() as $interface) {
            $hierarchy[] = $interface;
        }

        foreach ($ref->getTraits() as $trait) {
            $hierarchy[] = $trait;
        }

        return $hierarchy;
    }
}
