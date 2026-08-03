<?php

declare(strict_types=1);

/**
 * A minimal reified-generics runtime for PHP.
 *
 * Strategy:
 *  - Collection tracks its own concrete type parameter (T) as a real
 *    runtime property, set on first add() or via an explicit constructor arg.
 *  - GenericClosure wraps a normal \Closure plus a declared expected
 *    parameter type (e.g. "Collection<int>"), parsed from a docblock-like
 *    string you supply explicitly (since native PHP erases docblocks).
 *  - When invoked, GenericClosure compares the runtime T of any Collection
 *    argument against the declared expected T and throws TypeError on
 *    mismatch, mimicking a real generics-checking engine.
 */

final class GenericTypeMismatch
{
    public static function throwFor(
        string $functionName,
        string $paramName,
        int $paramIndex,
        string $expected,
        string $actual,
        string $file,
        int $line
    ): never {
        throw new TypeError(sprintf(
            '%s(): Callback %s argument #%d expects %s, but %s was given, called in %s on line %d',
            $functionName,
            $paramName,
            $paramIndex,
            $expected,
            $actual,
            $file,
            $line
        ));
    }
}

/**
 * Reified generic collection. T is tracked as a real property,
 * not just a docblock annotation.
 */
class Collection
{
    private ?string $type = null; // the runtime-resolved T

    private array $items = [];

    /**
     * Optionally pin T explicitly: new Collection('int')
     */
    public function __construct(?string $type = null)
    {
        $this->type = $type;
    }

    public function add(mixed $item): void
    {
        $itemType = get_debug_type($item); // 'int', 'string', 'float', etc.

        if ($this->type === null) {
            // First insert resolves T, like type inference.
            $this->type = $itemType;
        } elseif ($this->type !== $itemType) {
            throw new TypeError(sprintf(
                'Collection<%s>::add(): Argument #1 ($item) must be of type %s, %s given',
                $this->type,
                $this->type,
                $itemType
            ));
        }

        $this->items[] = $item;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * For display purposes: "Collection<int>"
     */
    public function __toString(): string
    {
        return sprintf('Collection<%s>', $this->type ?? 'mixed');
    }

    public function toArray(): array
    {
        return $this->items;
    }
}

/**
 * Wraps a native \Closure with a declared generic signature,
 * e.g. "Collection<int>", and enforces it at call time.
 */
final class GenericClosure
{
    private Closure $closure;

    private string $expectedParamType; // e.g. "Collection<int>"

    public function __construct(Closure $closure, string $expectedParamType)
    {
        $this->closure = $closure;
        $this->expectedParamType = $expectedParamType;
    }

    public function __invoke(mixed ...$args): mixed
    {
        return ($this->closure)(...$args);
    }

    public function expectedParamType(): string
    {
        return $this->expectedParamType;
    }

    public function raw(): Closure
    {
        return $this->closure;
    }
}

/**
 * The generics-aware entry point. Declares its expected closure
 * signature via $expectedClosureParamType and enforces it against
 * the *runtime* type of $col before ever invoking the closure.
 */
function processCollectionClosure(
    GenericClosure $callback,
    Collection $col
): mixed {
    $expected = $callback->expectedParamType();   // "Collection<int>"
    $actual = (string) $col;                    // "Collection<string>"

    if ($expected !== $actual) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        GenericTypeMismatch::throwFor(
            functionName: 'processCollectionClosure',
            paramName: '$callback',
            paramIndex: 1,
            expected: $expected,
            actual: $actual,
            file: $trace['file'],
            line: $trace['line'],
        );
    }

    return $callback($col);
}

// ---- Usage ----

// 1. Create a Collection<string>
$strCol = new Collection();
$strCol->add('hello'); // T resolves to string

// 2. Declare a closure that says it expects Collection<int>
$callback = new GenericClosure(
    function (Collection $c) {
        return 100;
    },
    expectedParamType: 'Collection<int>'
);

// 3. Fails, exactly like a reified generics engine would:
processCollectionClosure($callback, $strCol);
