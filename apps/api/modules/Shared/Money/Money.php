<?php

declare(strict_types=1);

namespace Tanaad\Shared\Money;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount in one currency, exact to two decimals. Arithmetic across currencies needs an
 * explicit rate (ADR-009 §6).
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(public BigDecimal $amount, public Currency $currency) {}

    public static function of(int|float|string|BigDecimal $amount, Currency $currency): self
    {
        return new self(Rounding::to($amount, 2), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(BigDecimal::zero()->toScale(2), $currency);
    }

    public function plus(Money $other): self
    {
        return new self(Rounding::to($this->amount->plus($this->same($other)->amount), 2), $this->currency);
    }

    public function minus(Money $other): self
    {
        return new self(Rounding::to($this->amount->minus($this->same($other)->amount), 2), $this->currency);
    }

    public function times(int|float|string|BigDecimal $factor): self
    {
        return new self(Rounding::to($this->amount->multipliedBy(BigDecimal::of(Rounding::to($factor, 6))), 2), $this->currency);
    }

    /** Convert with the rate the command carried; rate is quote units per one base unit. */
    public function convert(Currency $to, int|float|string|BigDecimal $rate): self
    {
        if ($to === $this->currency) {
            return $this;
        }

        return new self(Rounding::to($this->amount->multipliedBy(BigDecimal::of(Rounding::to($rate, 6))), 2), $to);
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function toFloat(): float
    {
        return $this->amount->toFloat();
    }

    public function __toString(): string
    {
        return $this->amount->__toString().' '.$this->currency->value;
    }

    /** @return array{amount: float, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toFloat(), 'currency' => $this->currency->value];
    }

    private function same(Money $other): Money
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency->value} with {$other->currency->value} without a rate.");
        }

        return $other;
    }
}
