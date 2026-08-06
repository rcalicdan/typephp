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
     * Resolution Flow:
     * 1. Target Class: Uses $ref->class to inspect the target executing class (ensures interface
     *    contracts are discovered even when method bodies are fulfilled by a Trait).
     * 2. Parent Classes: Traverses up the parent class chain to inherit parent method docblocks.
     * 3. Interfaces: Traverses implemented interfaces to inherit interface contract docblocks.
     * 4. Traits: Traverses used traits to inherit trait method docblocks.
     *
     * @return array<int, \ReflectionMethod>
     */
    public static function getMethodHierarchy(\ReflectionMethod $ref): array
    {
        $hierarchy = [$ref];
        $methodName = $ref->getName();
        $targetClassName = $ref->class;

        try {
            $targetClass = new \ReflectionClass($targetClassName);
        } catch (\ReflectionException $e) {
            $targetClass = $ref->getDeclaringClass();
        }

        $parent = $targetClass->getParentClass();
        while ($parent !== false) {
            if ($parent->hasMethod($methodName)) {
                $hierarchy[] = $parent->getMethod($methodName);
            }
            $parent = $parent->getParentClass();
        }

        foreach ($targetClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $hierarchy[] = $interface->getMethod($methodName);
            }
        }

        foreach ($targetClass->getTraits() as $trait) {
            if ($trait->hasMethod($methodName)) {
                $hierarchy[] = $trait->getMethod($methodName);
            }
        }

        return $hierarchy;
    }

    /**
     * Builds an array of ReflectionClasses representing the class inheritance hierarchy from child to root.
     *
     * Resolution Flow:
     * 1. Target Class: Includes the primary reflection class.
     * 2. Parent Classes: Traverses parent classes up the inheritance tree.
     * 3. Interfaces: Collects all implemented interfaces.
     * 4. Traits: Collects all used traits.
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
