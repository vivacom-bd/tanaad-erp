<?php

declare(strict_types=1);

namespace Tanaad\Config\Support;

/** The RefList names from packages/domain/src/types.ts. */
final class RefLists
{
    public const array ALL = [
        'unit', 'sector', 'expense_category', 'expense_subcategory', 'quick_expense',
        'downtime_reason', 'breakdown_cause', 'colour', 'bag_size', 'bag_colour', 'brand',
        'material', 'pellet_grade', 'deduction_option', 'price_option', 'contract_term',
        'daily_rate', 'denomination', 'vehicle', 'waste_source', 'pellet_source',
        'payment_method', 'market', 'credit_term',
    ];
}
