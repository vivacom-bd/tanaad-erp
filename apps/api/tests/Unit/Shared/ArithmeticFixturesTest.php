<?php

declare(strict_types=1);

use Tanaad\Shared\Arithmetic\Arithmetic;

/**
 * contracts/fixtures/arithmetic.json is generated from the TypeScript reference
 * (tools/fixtures.mjs). Every case must hold here too.
 */
$fixtures = json_decode((string) file_get_contents(dirname(__DIR__, 5).'/contracts/fixtures/arithmetic.json'), true, 512, JSON_THROW_ON_ERROR);

$cases = [];
foreach ($fixtures as $fn => $list) {
    foreach ($list as $c) {
        $cases["{$fn}: {$c['name']}"] = [$fn, $c['input'], $c['expected']];
    }
}

it('matches the shared arithmetic fixture', function (string $fn, array $input, mixed $expected): void {
    $actual = Arithmetic::$fn(...$input);
    expect(json_decode(json_encode($actual, JSON_THROW_ON_ERROR), true))->toEqual($expected);
})->with($cases);

it('rounds half-up away from zero without float noise', function (): void {
    expect(Arithmetic::round2(1.005))->toBe(1.01)
        ->and(Arithmetic::round2(-2.345))->toBe(-2.35)
        ->and(Arithmetic::round3(2.3455))->toBe(2.346)
        ->and(Arithmetic::round3(-2.3455))->toBe(-2.346);
});
