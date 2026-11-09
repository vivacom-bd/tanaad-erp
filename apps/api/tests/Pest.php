<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../modules/*/Tests/Feature', '../modules/*/Tests/Replay');

pest()->extend(TestCase::class)
    ->in('Architecture', '../modules/*/Tests/Unit', 'Unit');
