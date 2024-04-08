<?php

namespace Spatie\Enum;

/**
 * @internal
 * @psalm-internal Spatie\Enum
 * @psalm-immutable
 */
class EnumDefinition
{
    private readonly string $methodName;

    /**
     * @param string|int $value
     */
    public function __construct(string $methodName, public $value, public string $label, public $index)
    {
        $this->methodName = strtolower($methodName);
    }

    /**
     * @param string|int $input
     *
     * @return bool
     */
    public function equals($input): bool
    {
        if ($this->value === $input) {
            return true;
        }

        if (is_string($input) && is_int($this->value) && $input === (string)$this->value) {
            return true;
        }

        if (is_string($input) && $this->methodName === strtolower($input)) {
            return true;
        }

        return false;
    }
}
