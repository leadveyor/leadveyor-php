<?php

declare(strict_types=1);

namespace Leadveyor;

/**
 * An object returned by the API. Read fields as properties or as array keys:
 *
 * ```php
 * $lead->evaluation->tier;
 * $lead['evaluation']['tier'];
 * $lead->toArray();          // plain PHP arrays
 * json_encode($lead);        // the API's JSON
 * ```
 *
 * A missing field reads as null. Typed subclasses live in `Leadveyor\Model` (Lead, Inbound, Profile…).
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class LeadveyorObject implements \ArrayAccess, \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * Fields that convert to a typed class: `'evaluation' => Evaluation::class`, lists as `'leads' => [Lead::class]`.
     * Filled in by the generated Model classes.
     *
     * @var array<string, class-string<LeadveyorObject>|array{0: class-string<LeadveyorObject>}>
     */
    protected const TYPES = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @param array<string, mixed> $values */
    final public function __construct(array $values = [])
    {
        foreach ($values as $k => $v) {
            $this->values[(string) $k] = $v;
        }
    }

    /** Builds the object (and its typed children) from decoded JSON. */
    public static function constructFrom(\stdClass $data): static
    {
        $values = [];
        foreach (get_object_vars($data) as $k => $v) {
            $type = static::TYPES[$k] ?? null;
            if (is_string($type) && $v instanceof \stdClass) {
                $values[$k] = $type::constructFrom($v);
            } elseif (is_array($type) && is_array($v)) {
                $values[$k] = array_map(
                    static fn ($item) => $item instanceof \stdClass ? $type[0]::constructFrom($item) : self::convert($item),
                    $v
                );
            } else {
                $values[$k] = self::convert($v);
            }
        }
        return new static($values);
    }

    /** Decoded JSON → LeadveyorObject for objects, arrays of those for lists, scalars as they are. */
    public static function convert(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::constructFrom($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'convert'], $value);
        }
        return $value;
    }

    /** @return array<string, mixed> The object as plain PHP arrays, all the way down. */
    public function toArray(): array
    {
        return self::plain($this->values);
    }

    /** @internal */
    public function toStdClass(): \stdClass
    {
        $o = new \stdClass();
        foreach ($this->values as $k => $v) {
            $o->{$k} = self::std($v);
        }
        return $o;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->values[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->values[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \LogicException('LeadveyorObject fields need a name.');
        }
        $this->values[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[(string) $offset]);
    }

    /** @return \ArrayIterator<string, mixed> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toStdClass();
    }

    public function __toString(): string
    {
        return static::class . ' JSON: ' . json_encode($this, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->values;
    }

    private static function plain(mixed $v): mixed
    {
        if ($v instanceof self) {
            return $v->toArray();
        }
        if (is_array($v)) {
            return array_map([self::class, 'plain'], $v);
        }
        return $v;
    }

    private static function std(mixed $v): mixed
    {
        if ($v instanceof self) {
            return $v->toStdClass();
        }
        if (is_array($v)) {
            return array_map([self::class, 'std'], $v);
        }
        return $v;
    }
}
