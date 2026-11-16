<?php

declare(strict_types=1);

namespace Tanaad\Labels\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Label vocabulary and quantity handling. Kinds mirror packages/domain LabelKind. */
final class LabelBook
{
    public const array KINDS = ['roll', 'rack', 'bundle', 'sack', 'bag', 'batch', 'pack'];

    public const array STATUSES = ['created', 'in_stock', 'consumed', 'dispatched', 'scrapped'];

    /** Event types other phases emit about a label; the projector appends them to its history. type => resulting status (null = status unchanged). */
    public const array HOOKS = [
        'stock.label_moved' => null,
        'stock.label_scanned' => null,
        'stock.label_scrapped' => 'scrapped',
        'dispatch.label_dispatched' => 'dispatched',
    ];

    /** Quantities are stored at 3 decimals, exactly. */
    public static function qty(float|string $v): string
    {
        return BigDecimal::of((string) $v)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    public static function num(string $v): float
    {
        return (float) BigDecimal::of($v)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    /** First three letters of a word, uppercased (site code "mats" -> MAT, kind "bundle" -> BUN). */
    public static function abbr(string $s): string
    {
        return strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $s) ?? '', 0, 3));
    }
}
