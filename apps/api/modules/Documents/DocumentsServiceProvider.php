<?php

declare(strict_types=1);

namespace Tanaad\Documents;

use Illuminate\Support\ServiceProvider;

/** Read-only: documents are assembled from read tables on request; nothing is written. */
final class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void {}
}
