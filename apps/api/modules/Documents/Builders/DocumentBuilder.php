<?php

declare(strict_types=1);

namespace Tanaad\Documents\Builders;

use Tanaad\Identity\Models\User;

interface DocumentBuilder
{
    /** False when the tables this kind reads from do not exist yet (the kind is then an unknown 404). */
    public function available(): bool;

    /**
     * Assemble the document data, after checking the user may see it.
     *
     * @return array<string, mixed>
     *
     * @throws \Tanaad\Shared\Ledger\Rejection not_found, forbidden or state_conflict
     */
    public function build(User $user, string $id): array;
}
