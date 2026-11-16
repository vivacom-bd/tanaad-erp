<?php

declare(strict_types=1);

use Tanaad\Shared\Money\Currency;
use Tanaad\Shared\Money\Kg;
use Tanaad\Shared\Money\Money;

it('adds and rounds money exactly', function (): void {
    $a = Money::of(0.1, Currency::UGX)->plus(Money::of(0.2, Currency::UGX));
    expect($a->toFloat())->toBe(0.3)
        ->and(Money::of(1.005, Currency::USD)->toFloat())->toBe(1.01)
        ->and((string) Money::of(2500, Currency::UGX))->toBe('2500.00 UGX');
});

it('refuses to mix currencies without a rate', function (): void {
    expect(fn () => Money::of(1, Currency::UGX)->plus(Money::of(1, Currency::USD)))->toThrow(InvalidArgumentException::class);
    expect(Money::of(10, Currency::USD)->convert(Currency::UGX, 3700)->toFloat())->toBe(37000.0);
});

it('keeps kilograms to three decimals', function (): void {
    expect(Kg::of(1067.2004)->toFloat())->toBe(1067.2)
        ->and(Kg::of(0.0005)->toFloat())->toBe(0.001)
        ->and(json_encode(['kg' => Kg::of(12.5)]))->toBe('{"kg":12.5}');
});
