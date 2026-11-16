<?php

declare(strict_types=1);

namespace Tanaad\Config\Handlers;

use Tanaad\Config\Commands\UpdateCompanyProfile;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class UpdateCompanyProfileHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof UpdateCompanyProfile) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        if ($command->context()->siteId === null) {
            throw Rejection::invalidPayload(['site_id' => 'required']);
        }
        $p = new Payload($command->payload);
        $payload = [
            'address' => $p->text('address'),
            'phone' => $p->text('phone', 255),
            'email' => $p->text('email', 255),
            'tin' => $p->text('tin', 255),
            'bank_details' => $p->text('bank_details'),
            'mobile_money' => $p->text('mobile_money', 255),
            'vat_rate_pct' => $p->num('vat_rate_pct'),
            'efris_connected' => $p->bool('efris_connected', false),
        ];
        if ($payload['vat_rate_pct'] > 100) {
            $p->error('vat_rate_pct', 'must be at most 100');
        }
        if ($payload['email'] !== '' && filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false) {
            $p->error('email', 'must be an email address');
        }
        $p->check();

        return [Event::from($command->context(), 'config.company_profile_changed', $payload)];
    }
}
