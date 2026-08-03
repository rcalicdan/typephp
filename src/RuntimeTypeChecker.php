<?php

declare(strict_types=1);

namespace TypePHP;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;
use TypePHP\Wrapper\IterableWrapper;

final class RuntimeTypeChecker
{
    private static ?TypeValidatorRegistry $registry = null;

    private static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }

    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?\TypeError
    {
        return TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);
    }

    public static function bindInstance(object $instance, string $typeString): object
    {
        return TemplateManager::bindInstance($instance, $typeString);
    }

    public static function checkParams(string $function, array $vars, ?object $thisObj = null): ?\TypeError
    {
        $contract = ContractParser::parse($function);
        if (! $contract || ! $contract['types']) {
            return null;
        }

        $registry = self::getRegistry();
        $templates = $contract['templates'];
        $aliases = $contract['aliases'];

        if ($thisObj === null) {
            TemplateManager::clearCallBindings($function, $templates);
        }

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! \array_key_exists($paramName, $vars)) {
                continue;
            }

            $typeNode = SpecialTypeResolver::resolve($typeNode, $function, $thisObj);
            $val = $vars[$paramName];

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            // Resolve class-string<T>
            if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
                if ($err = self::resolveClassStringTemplate($typeNode, $val, $paramName, $function, $thisObj, $templates)) {
                    return $err;
                }

                continue;
            }

            // Resolve @template T & variadic templates (@param T ...$items)
            if (self::getTemplateName($typeNode, $templates) !== null) {
                if ($err = self::resolveTemplateParam($typeNode, $val, $paramName, $function, $thisObj, $templates, $registry)) {
                    return $err;
                }

                continue;
            }

            // Standard Type Validation
            if ($err = $registry->validate($val, $typeNode, $function . '(): Argument $' . $paramName)) {
                return $err;
            }
        }

        return null;
    }

    public static function checkReturn(string $function, mixed $value, ?object $thisObj = null, array $vars = []): mixed
    {
        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return $value;
        }

        $registry = self::getRegistry();

        // Strict $this identity check
        if ($err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $function)) {
            throw $err;
        }

        // Resolve special type names (self, static, parent, $this)
        $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

        // Resolve aliases (@phpstan-type)
        $aliases = $contract['aliases'] ?? [];
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        // Substitute template placeholders (T[] -> int[])
        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $contract['templates']);
        if (! empty($boundTemplates)) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates);
        }

        // Parameter-based Conditional Return Types
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            $paramName = ltrim($returnTypeNode->parameterName, '$');
            $paramValue = $vars[$paramName] ?? null;

            $targetErr = $registry->validate($paramValue, $returnTypeNode->targetType, 'condition');
            $isTargetMatch = ($targetErr === null);

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            if ($err = $registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value')) {
                throw $err;
            }

            return $value;
        }

        // Template-based Conditional Return Types
        if ($returnTypeNode instanceof ConditionalTypeNode) {
            $subjectTypeNode = $returnTypeNode->subjectType;

            if ($subjectTypeNode instanceof IdentifierTypeNode && isset($boundTemplates[$subjectTypeNode->name])) {
                $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
            }

            $subStr = (string) $subjectTypeNode;
            $targetStr = (string) $returnTypeNode->targetType;

            $isTargetMatch = ($subStr === $targetStr) ||
                ((class_exists($subStr) || interface_exists($subStr)) && (class_exists($targetStr) || interface_exists($targetStr)) && is_a($subStr, $targetStr, true));

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $effectiveReturnTypeNode = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            if ($err = $registry->validate($value, $effectiveReturnTypeNode, $function . '(): Return value')) {
                throw $err;
            }

            return $value;
        }

        // Standard Return Validation
        if ($err = $registry->validate($value, $returnTypeNode, $function . '(): Return value')) {
            throw $err;
        }

        return $value;
    }

    public static function wrapCallable(string $function, string $paramName, mixed $callable): mixed
    {
        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry());
    }

    public static function wrapIterable(string $function, string $paramName, mixed $iterable): mixed
    {
        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry());
    }

    public static function inferTypeFromValue(mixed $value): IdentifierTypeNode|GenericTypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    private static function isClassStringTemplate(GenericTypeNode $typeNode, array $templates): bool
    {
        return isset($typeNode->genericTypes[0])
            && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
            && strtolower($typeNode->type->name) === 'class-string'
            && isset($templates[$typeNode->genericTypes[0]->name]);
    }

    private static function resolveClassStringTemplate(GenericTypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates): ?\TypeError
    {
        /** @var IdentifierTypeNode $innerType */
        $innerType = $typeNode->genericTypes[0];
        $templateName = $innerType->name;
        $templateNode = $templates[$templateName];

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            if (! is_string($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
            }

            if ($templateNode->bound !== null) {
                $boundName = $templateNode->bound instanceof IdentifierTypeNode ? $templateNode->bound->name : (string) $templateNode->bound;
                if (! is_a($val, $boundName, true)) {
                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundName . ", '$val' given");
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, new IdentifierTypeNode($val));
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);
            $targetClass = $expectedTypeNode instanceof IdentifierTypeNode ? $expectedTypeNode->name : (string) $expectedTypeNode;
            if (! is_string($val) || ! is_a($val, $targetClass, true)) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetClass . ", '$val' given");
            }
        }

        return null;
    }

    private static function getTemplateName(TypeNode $typeNode, array $templates): ?string
    {
        if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
            return $typeNode->name;
        }

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
        }

        return null;
    }

    private static function resolveTemplateParam(TypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates, TypeValidatorRegistry $registry): ?\TypeError
    {
        $templateName = self::getTemplateName($typeNode, $templates);
        $templateNode = $templates[$templateName];
        $isVariadic = $typeNode instanceof ArrayTypeNode;

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            $sampleVal = $isVariadic ? ($val[0] ?? null) : $val;
            $inferredType = TemplateManager::inferTypeFromValue($sampleVal);

            if ($templateNode->bound !== null) {
                if ($err = $registry->validate($sampleVal, $templateNode->bound, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ')')) {
                    return $err;
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, $inferredType);

            if ($isVariadic && is_array($val)) {
                foreach ($val as $idx => $item) {
                    if ($err = $registry->validate($item, $inferredType, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $inferredType . ')')) {
                        return $err;
                    }
                }
            }
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);

            if ($isVariadic && is_array($val)) {
                foreach ($val as $idx => $item) {
                    if ($err = $registry->validate($item, $expectedTypeNode, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $expectedTypeNode . ')')) {
                        return $err;
                    }
                }
            } else {
                if ($err = $registry->validate($val, $expectedTypeNode, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')')) {
                    return $err;
                }
            }
        }

        return null;
    }
}
