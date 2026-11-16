<?php

declare(strict_types=1);

namespace Tanaad\Config\Support;

use Illuminate\Support\Str;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Reads and validates a command payload field by field, collecting field-keyed errors.
 * Call check() once at the end; it throws Rejection::invalidPayload with every error.
 */
final class Payload
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data, private readonly string $prefix = '', private readonly ?self $parent = null) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function error(string $key, string $message): void
    {
        if ($this->parent !== null) {
            $this->parent->error($this->prefix.$key, $message);

            return;
        }
        $this->errors[$key] ??= $message;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw Rejection::invalidPayload($this->errors);
        }
    }

    /** Required, non-blank string. */
    public function str(string $key, int $max = 255): string
    {
        $v = $this->data[$key] ?? null;
        if (! is_string($v) || trim($v) === '') {
            $this->error($key, 'required');

            return '';
        }
        if (mb_strlen($v) > $max) {
            $this->error($key, "at most {$max} characters");
        }

        return trim($v);
    }

    /** Optional string; blank becomes null. */
    public function optStr(string $key, int $max = 255): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v)) {
            $this->error($key, 'must be a string');

            return null;
        }
        if (mb_strlen($v) > $max) {
            $this->error($key, "at most {$max} characters");
        }

        return trim($v) === '' ? null : trim($v);
    }

    /** String that may be empty (document fields left blank on purpose). */
    public function text(string $key, int $max = 2000): string
    {
        return $this->optStr($key, $max) ?? '';
    }

    public function num(string $key, ?float $min = 0.0, bool $positive = false): float
    {
        $v = $this->optNum($key, $min, $positive);
        if ($v === null) {
            $this->error($key, 'required');
        }

        return $v ?? 0.0;
    }

    public function optNum(string $key, ?float $min = 0.0, bool $positive = false): ?float
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_int($v) && ! is_float($v) && ! (is_string($v) && is_numeric($v))) {
            $this->error($key, 'must be a number');

            return null;
        }
        $f = (float) $v;
        if ($positive && $f <= 0) {
            $this->error($key, 'must be greater than 0');
        } elseif ($min !== null && $f < $min) {
            $this->error($key, "must be at least {$min}");
        }

        return $f;
    }

    public function int(string $key, ?int $min = 0, ?int $default = null): int
    {
        $v = $this->optInt($key, $min);
        if ($v === null && $default === null) {
            $this->error($key, 'required');
        }

        return $v ?? $default ?? 0;
    }

    public function optInt(string $key, ?int $min = 0): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_int($v) && ! (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) && ! (is_float($v) && floor($v) === $v)) {
            $this->error($key, 'must be a whole number');

            return null;
        }
        $i = (int) $v;
        if ($min !== null && $i < $min) {
            $this->error($key, "must be at least {$min}");
        }

        return $i;
    }

    public function bool(string $key, bool $default): bool
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (! is_bool($v)) {
            $this->error($key, 'must be true or false');

            return $default;
        }

        return $v;
    }

    public function uuid(string $key): string
    {
        $v = $this->optUuid($key);
        if ($v === null && ! isset($this->errors[$key])) {
            $this->error($key, 'required');
        }

        return $v ?? '';
    }

    public function optUuid(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || ! Str::isUuid($v)) {
            $this->error($key, 'must be a UUID');

            return null;
        }

        return strtolower($v);
    }

    /** @param list<string> $allowed */
    public function oneOf(string $key, array $allowed): string
    {
        $v = $this->data[$key] ?? null;
        if (! is_string($v) || ! in_array($v, $allowed, true)) {
            $this->error($key, 'must be one of: '.implode(', ', $allowed));

            return '';
        }

        return $v;
    }

    public function date(string $key): string
    {
        $v = $this->optDate($key);
        if ($v === null) {
            $this->error($key, 'required');
        }

        return $v ?? '';
    }

    public function optDate(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            $this->error($key, 'must be a date (YYYY-MM-DD)');

            return null;
        }

        return $v;
    }

    public function currency(string $key): string
    {
        $v = $this->data[$key] ?? null;
        if (! is_string($v) || preg_match('/^[A-Z]{3}$/', $v) !== 1) {
            $this->error($key, 'must be a 3-letter currency code');

            return '';
        }

        return $v;
    }

    /** @return list<self> one reader per element of a list field, errors keyed "key.i.field" */
    public function children(string $key): array
    {
        $v = $this->data[$key] ?? [];
        if (! is_array($v) || ! array_is_list($v)) {
            $this->error($key, 'must be a list');

            return [];
        }
        $out = [];
        foreach ($v as $i => $item) {
            if (! is_array($item)) {
                $this->error("{$key}.{$i}", 'must be an object');

                continue;
            }
            /** @var array<string, mixed> $item */
            $out[] = new self($item, "{$key}.{$i}.", $this);
        }

        return $out;
    }
}
