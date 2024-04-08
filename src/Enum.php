<?php

namespace Spatie\Enum;

use BadMethodCallException;
use Closure;
use JsonSerializable;
use ReflectionClass;
use Spatie\Enum\Exceptions\DuplicateLabelsException;
use Spatie\Enum\Exceptions\DuplicateValuesException;
use Spatie\Enum\Exceptions\UnknownEnumMethod;
use Spatie\Enum\Exceptions\UnknownEnumProperty;
use TypeError;

/**
 * @property-read string|int $value
 * @property-read string $label
 * @psalm-seal-properties
 *
 * @psalm-consistent-constructor
 */
abstract class Enum implements JsonSerializable, \Stringable
{
    /**
     * @var string|int
     * @psalm-readonly
     */
    protected $value;

    /** @psalm-readonly */
    protected string $label;

    protected $index;

    /** @psalm-var array<class-string, array<string, \Spatie\Enum\EnumDefinition>> */
    private static array $definitionCache = [];

    /** @psalm-var array<class-string, array<int|string, \Spatie\Enum\Enum>> */
    private static array $instances = [];

    /**
     * @return static[]
     */
    public static function cases(): array
    {
        $instances = array_map(
            fn (EnumDefinition $definition): Enum => static::from($definition->value),
            static::resolveDefinition()
        );

        return array_values($instances);
    }

    /**
     * @return string[]
     * @psalm-return array<string|int, string>
     */
    public static function toArray(): array
    {
        $array = [];

        foreach (static::resolveDefinition() as $definition) {
            $array[$definition->value] = $definition->label;
        }

        return $array;
    }

    /**
     * @return string[]|int[]
     */
    public static function toValues(): array
    {
        return array_keys(static::toArray());
    }

    /**
     * @return string[]
     */
    public static function toLabels(): array
    {
        return array_values(static::toArray());
    }

    /**
     * @return string[]
     */
    public static function toIndexes(): array
    {
        return array_values(static::toArray());
    }

    /**
     * @param string|int $value
     *
     * @return static
     * @deprecated Use `from()` instead
     */
    public static function make($value): Enum
    {
        return static::from($value);
    }

    /**
     * @param string|int $value
     *
     * @return static
     */
    final public static function from($value): Enum
    {
        $enum = new static($value);

        if (!isset(self::$instances[static::class][$enum->value])) {
            self::$instances[static::class][$enum->value] = $enum;
        }

        return self::$instances[static::class][$enum->value];
    }

    /**
     * @param string|int|mixed $value
     *
     * @return static|null
     */
    final public static function tryFrom($value): ?Enum
    {
        try {
            return static::from($value);
        } catch (BadMethodCallException) {
            return null;
        } catch (TypeError $exception) {
            if (
                $value === null
                || is_scalar($value)
                || (is_object($value) && method_exists($value, '__toString'))
            ) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param string|int $value
     *
     * @internal
     */
    public function __construct($value)
    {
        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string)$value;
        }

        if (! (is_int($value) || is_string($value))) {
            $enumClass = static::class;

            throw new TypeError("Only string and integer are allowed values for enum {$enumClass}.");
        }

        $definition = $this->findDefinition($value);

        if ($definition === null) {
            $enumClass = static::class;

            throw new BadMethodCallException("There's no value {$value} defined for enum {$enumClass}, consider adding it in the docblock definition.");
        }

        $this->value = $definition->value;
        $this->label = $definition->label;
        $this->index = $definition->index;
    }

    /**
     *
     * @return int|string
     * @throws UnknownEnumProperty
     */
    public function __get(string $name)
    {
        if ($name === 'label') {
            return $this->label;
        }

        if ($name === 'value') {
            return $this->value;
        }

        if ($name === 'index') {
            return $this->index;
        }

        throw UnknownEnumProperty::new(static::class, $name);
    }

    /**
     *
     * @return static
     */
    public static function __callStatic(string $name, array $arguments)
    {
        return static::from($name);
    }

    /**
     *
     * @return bool|mixed
     *
     * @throws UnknownEnumMethod
     */
    public function __call(string $name, array $arguments)
    {
        if (str_starts_with($name, 'is')) {
            $other = static::from(substr($name, 2));

            return $this->equals($other);
        }

        return self::__callStatic($name, $arguments);
    }

    public function equals(Enum ...$others): bool
    {
        foreach ($others as $other) {
            if (
                static::class === $other::class
                && $this->value === $other->value
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]|int[]|Closure
     * @psalm-return array<string, string|int> | Closure(string):(int|string)
     */
    protected static function values()
    {
        return [];
    }

    /**
     * @return string[]|Closure
     * @psalm-return array<string, string> | Closure(string):string
     */
    protected static function labels()
    {
        return [];
    }

    protected static function  indexes()
    {
        return [];
    }

    /**
     * @param string|int $input
     *
     * @return \Spatie\Enum\EnumDefinition|null
     */
    private function findDefinition($input): ?EnumDefinition
    {
        foreach (static::resolveDefinition() as $definition) {
            if ($definition->equals($input)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return \Spatie\Enum\EnumDefinition[]
     */
    private static function resolveDefinition(): array
    {
        $className = static::class;

        if (static::$definitionCache[$className] ?? null) {
            return static::$definitionCache[$className];
        }

        $reflectionClass = new ReflectionClass($className);

        $docComment = $reflectionClass->getDocComment();

        preg_match_all('/@method\s+static\s+self\s+([\w_]+)\(\)/', $docComment, $matches);

        $definition = [];

        $valueMap = static::values();

        if ($valueMap instanceof Closure) {
            $valueMap = array_map($valueMap, array_combine($matches[1], $matches[1]));
        }

        $labelMap = static::labels();

        if ($labelMap instanceof Closure) {
            $labelMap = array_map($labelMap, array_combine($matches[1], $matches[1]));
        }

        $indexMap = static::indexes();

        if ($indexMap instanceof Closure) {
            $indexMap = array_map($indexMap, array_combine($matches[1], $matches[1]));
        }

        foreach ($matches[1] as $methodName) {
            $value = $valueMap[$methodName] = $valueMap[$methodName] ?? $methodName;

            $label = $labelMap[$methodName] = $labelMap[$methodName] ?? $methodName;

            $index = $indexMap[$methodName] = $indexMap[$methodName] ?? $methodName;

            $definition[$methodName] = new EnumDefinition($methodName, $value, $label, $index);
        }

        if (self::arrayHasDuplicates($valueMap)) {
            throw new DuplicateValuesException(static::class);
        }

        if (self::arrayHasDuplicates($labelMap)) {
            throw new DuplicateLabelsException(static::class);
        }

        if (self::arrayHasDuplicates($indexMap)) {
            throw new DuplicateValuesException(static::class);
        }

        return static::$definitionCache[$className] ??= $definition;
    }

    private static function arrayHasDuplicates(array $array): bool
    {
        return count($array) > count(array_unique($array));
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
