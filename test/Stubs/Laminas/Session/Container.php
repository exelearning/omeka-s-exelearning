<?php

declare(strict_types=1);

namespace Laminas\Session;

/**
 * Minimal stub for Laminas\Session\Container for tests.
 */
class Container implements \ArrayAccess, \Iterator, \Countable
{
    private array $data = [];
    private int $position = 0;
    private string $name;

    public function __construct(string $name = 'Default')
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        $keys = array_keys($this->data);
        return $this->data[$keys[$this->position]] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        $keys = array_keys($this->data);
        return $keys[$this->position] ?? null;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        $keys = array_keys($this->data);
        return isset($keys[$this->position]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function __set(string $name, $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * Return by reference to allow indirect modification of arrays.
     */
    public function &__get(string $name)
    {
        if (!isset($this->data[$name])) {
            $this->data[$name] = null;
        }
        return $this->data[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function getDefaultManager()
    {
        return new class {
            public function getStorage()
            {
                return new \ArrayObject();
            }
        };
    }

    public function setExpirationSeconds(int $seconds, ?string $data = null): self
    {
        // No-op for testing
        return $this;
    }

    public function setExpirationHops(int $hops, ?string $data = null): self
    {
        // No-op for testing
        return $this;
    }

    public function getManager()
    {
        return $this->getDefaultManager();
    }
}
