<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;

final class SpecialTypeResolver
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $fileUseImports = [];

    /**
     * @var array<string, string>
     */
    private static array $fileNamespaces = [];

    public static function checkThisIdentity(TypeNode $returnTypeNode, mixed $value, ?object $thisObj, string $function): ?\TypeError
    {
        $isThisType = ($returnTypeNode instanceof ThisTypeNode)
            || ($returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === '$this');

        if ($thisObj !== null && $isThisType && $value !== $thisObj) {
            return ErrorFactory::createError($function . '(): Return value must be $this instance, ' . TypeFormatter::formatGivenValue($value) . ' returned');
        }

        return null;
    }

    public static function resolve(TypeNode $node, string $function, ?object $thisObj = null): TypeNode
    {
        if (str_contains($function, '::')) {
            [$className, $methodName] = explode('::', $function, 2);
            $ref = new \ReflectionMethod($className, $methodName);
        } else {
            $ref = new \ReflectionFunction($function);
        }

        $declaringClass = str_contains($function, '::') ? explode('::', $function, 2)[0] : null;
        $runtimeClass = $thisObj !== null ? get_class($thisObj) : $declaringClass;

        if ($node instanceof ThisTypeNode) {
            return $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);

            if ($lower === '$this' || $lower === 'static') {
                return $node;
            }

            if ($lower === 'self' && $declaringClass !== null) {
                return new IdentifierTypeNode($declaringClass);
            }
            if ($lower === 'parent' && $declaringClass !== null) {
                $parentClass = get_parent_class($declaringClass);
                if ($parentClass !== false) {
                    return new IdentifierTypeNode($parentClass);
                }
            }

            $fqcn = self::resolveFqcn($node->name, $ref);
            if ($fqcn !== $node->name) {
                return new IdentifierTypeNode($fqcn);
            }
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolve($node->type, $function, $thisObj);
            $innerTypes = array_map(
                fn($t) => self::resolve($t, $function, $thisObj),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($node->type, $function, $thisObj));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($node->type, $function, $thisObj));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn($t) => self::resolve($t, $function, $thisObj),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn($t) => self::resolve($t, $function, $thisObj),
                $node->types
            ));
        }

        return $node;
    }

    /**
     * Seeds the in-memory cache directly from StreamWrapper to prevent double file reads and re-parsing.
     *
     * @param array<string, string> $imports
     */
    public static function seedFileMetadata(string $fileName, string $namespace, array $imports): void
    {
        if ($fileName !== '') {
            self::$fileNamespaces[$fileName] = $namespace;
            self::$fileUseImports[$fileName] = $imports;
        }
    }

    /**
     * Resolves nodes specifically using a file context instead of reflection.
     * Useful for pre-binding @var annotations where reflection is unavailable.
     */
    public static function resolveForFile(TypeNode $node, string $file): TypeNode
    {
        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            if (\in_array($lower, ['self', 'static', 'parent', '$this'], true)) {
                return clone $node;
            }

            $fqcn = self::resolveFqcnForFile($node->name, $file);
            if ($fqcn !== $node->name) {
                return new IdentifierTypeNode($fqcn);
            }
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolveForFile($node->type, $file);
            $innerTypes = array_map(
                fn($t) => self::resolveForFile($t, $file),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolveForFile($node->type, $file));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolveForFile($node->type, $file));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn($t) => self::resolveForFile($t, $file),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn($t) => self::resolveForFile($t, $file),
                $node->types
            ));
        }

        return clone $node;
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod $ref
     *
     * @return array<string, string>
     */
    public static function getUseImports(\ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref): array
    {
        $fileName = $ref->getFileName();
        if ($fileName === false || ! file_exists($fileName)) {
            return [];
        }

        return self::getUseImportsFromFile($fileName);
    }

    /**
     * Extracts and caches use imports directly from a file path.
     *
     * @return array<string, string>
     */
    public static function getUseImportsFromFile(string $fileName): array
    {
        if ($fileName === '' || ! file_exists($fileName)) {
            return [];
        }

        if (isset(self::$fileUseImports[$fileName])) {
            return self::$fileUseImports[$fileName];
        }

        $source = file_get_contents($fileName);
        if ($source === false) {
            return self::$fileUseImports[$fileName] = [];
        }

        self::parseFileMetadata($fileName, $source);

        return self::$fileUseImports[$fileName] ?? [];
    }

    /**
     * Extracts and caches the namespace directly from a file path.
     */
    public static function getNamespaceFromFile(string $fileName): string
    {
        if ($fileName === '' || ! file_exists($fileName)) {
            return '';
        }

        if (isset(self::$fileNamespaces[$fileName])) {
            return self::$fileNamespaces[$fileName];
        }

        $source = file_get_contents($fileName);
        if ($source === false) {
            return self::$fileNamespaces[$fileName] = '';
        }

        self::parseFileMetadata($fileName, $source);

        return self::$fileNamespaces[$fileName] ?? '';
    }

    /**
     * Parses the AST of the file once to extract both namespace and use statements.
     */
    private static function parseFileMetadata(string $fileName, string $source): void
    {
        self::$fileNamespaces[$fileName] = '';
        self::$fileUseImports[$fileName] = [];

        static $parser = null;
        if ($parser === null) {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        try {
            $stmts = $parser->parse($source);
            if ($stmts === null) {
                return;
            }

            $imports = [];
            $namespace = '';

            // PSR-4 codebases typically have top-level statements or a single namespace block
            $nodesToScan = $stmts;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Stmt\Namespace_) {
                    $namespace = $stmt->name ? $stmt->name->toString() : '';
                    $nodesToScan = $stmt->stmts;
                    break;
                }
            }

            foreach ($nodesToScan as $stmt) {
                // Regular imports: use App\Models\User;
                if ($stmt instanceof Stmt\Use_) {
                    if ($stmt->type !== Stmt\Use_::TYPE_NORMAL) {
                        continue; // Skip function/const imports
                    }
                    foreach ($stmt->uses as $use) {
                        $fqcn = $use->name->toString();
                        $alias = $use->getAlias()->toString();
                        $imports[$alias] = $fqcn;
                    }
                }
                // Grouped imports: use App\Models\{User, Post};
                elseif ($stmt instanceof Stmt\GroupUse) {
                    $prefix = $stmt->prefix->toString();
                    foreach ($stmt->uses as $use) {
                        if ($use->type !== Stmt\Use_::TYPE_NORMAL && $use->type !== Stmt\Use_::TYPE_UNKNOWN && $stmt->type !== Stmt\Use_::TYPE_NORMAL) {
                            continue;
                        }
                        $fqcn = $prefix . '\\' . $use->name->toString();
                        $alias = $use->getAlias()->toString();
                        $imports[$alias] = $fqcn;
                    }
                }
            }

            self::$fileNamespaces[$fileName] = $namespace;
            self::$fileUseImports[$fileName] = $imports;
        } catch (\Throwable $e) {
            // Silently fall back to empty metadata if parsing fails
        }
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod $ref
     */
    public static function resolveFqcn(string $name, \ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref): string
    {
        $lower = strtolower($name);
        if (\in_array($lower, [
            'int',
            'integer',
            'string',
            'float',
            'double',
            'bool',
            'boolean',
            'array',
            'list',
            'object',
            'callable',
            'iterable',
            'resource',
            'null',
            'true',
            'false',
            'mixed',
            'scalar',
            'void',
            'self',
            'static',
            'parent',
            '$this',
            'positive-int',
            'negative-int',
            'non-positive-int',
            'non-negative-int',
            'non-zero-int',
            'unsigned-int',
            'class-string',
            'callable-string',
            'numeric-string',
            'non-empty-string',
            'lowercase-string',
            'non-empty-lowercase-string',
            'literal-string',
            'non-empty-array',
            'non-empty-list',
            'number',
            'numeric',
            'truthy',
            'falsy',
            'falsey',
            'min',
            'max',
            '*',
        ], true)) {
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Ensure the name is a valid class identifier before hitting autoloader
        if (! ClassNameValidator::isValid($name)) {
            return $name;
        }

        // Check `use` imports in file
        $imports = self::getUseImports($ref);
        if (isset($imports[$name])) {
            return $imports[$name];
        }

        // Check same namespace
        $namespace = match (true) {
            $ref instanceof \ReflectionClass => $ref->getNamespaceName(),
            $ref instanceof \ReflectionMethod => $ref->getDeclaringClass()->getNamespaceName(),
            $ref instanceof \ReflectionFunction => $ref->getNamespaceName(),
        };

        if ($namespace !== '') {
            $namespacedClass = $namespace . '\\' . $name;
            if (class_exists($namespacedClass) || interface_exists($namespacedClass) || trait_exists($namespacedClass) || enum_exists($namespacedClass)) {
                return $namespacedClass;
            }
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return $name;
        }

        return $name;
    }

    /**
     * Resolves an FQCN purely based on the file context (namespace and use imports).
     */
    public static function resolveFqcnForFile(string $name, string $file): string
    {
        $lower = strtolower($name);
        if (\in_array($lower, [
            'int',
            'integer',
            'string',
            'float',
            'double',
            'bool',
            'boolean',
            'array',
            'list',
            'object',
            'callable',
            'iterable',
            'resource',
            'null',
            'true',
            'false',
            'mixed',
            'scalar',
            'void',
            'self',
            'static',
            'parent',
            '$this',
            'positive-int',
            'negative-int',
            'non-positive-int',
            'non-negative-int',
            'non-zero-int',
            'unsigned-int',
            'class-string',
            'callable-string',
            'numeric-string',
            'non-empty-string',
            'lowercase-string',
            'non-empty-lowercase-string',
            'literal-string',
            'non-empty-array',
            'non-empty-list',
            'number',
            'numeric',
            'truthy',
            'falsy',
            'falsey',
            'min',
            'max',
            '*',
        ], true)) {
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (! ClassNameValidator::isValid($name)) {
            return $name;
        }

        // Check `use` imports in file
        $imports = self::getUseImportsFromFile($file);
        if (isset($imports[$name])) {
            return $imports[$name];
        }

        // Check same namespace
        $namespace = self::getNamespaceFromFile($file);
        if ($namespace !== '') {
            $namespacedClass = $namespace . '\\' . $name;
            if (class_exists($namespacedClass) || interface_exists($namespacedClass) || trait_exists($namespacedClass) || enum_exists($namespacedClass)) {
                return $namespacedClass;
            }
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return $name;
        }

        return $name;
    }
}
