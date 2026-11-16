<?php

declare(strict_types=1);

namespace Tanaad\Shared\Money;

use Brick\Math\BigDecimal;
use JsonSerializable;

/** A weight in kilograms, exact to three decimals. */
final readonly class Kg implements JsonSerializable
{
    private function __construct(public BigDecimal $value) {}

    public static function of(int|float|string|BigDecimal $kg): self
    {
        return new self(Rounding::to($kg, 3));
    }

    public function plus(Kg $other): self
    {
        return new self(Rounding::to($this->value->plus($other->value), 3));
    }

    public function minus(Kg $other): self
    {
        return new self(Rounding::to($this->value->minus($other->value), 3));
    }

    public function times(int|float|string|BigDecimal $factor): self
    {
        return new self(Rounding::to($this->value->multipliedBy(BigDecimal::of(Rounding::to($factor, 6))), 3));
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function toFloat(): float
    {
        return $this->value->toFloat();
    }

    public function jsonSerialize(): float
    {
        return $this->toFloat();
    }
}
