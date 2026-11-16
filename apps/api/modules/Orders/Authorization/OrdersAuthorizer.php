<?php

declare(strict_types=1);

namespace Tanaad\Orders\Authorization;

use Illuminate\Support\Facades\DB;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Orders\Commands\ApproveOrder;
use Tanaad\Orders\Commands\ConfirmOrderCash;
use Tanaad\Orders\Commands\HoldOrder;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Commands\RecordPayment;
use Tanaad\Orders\Commands\RegisterClient;
use Tanaad\Orders\Commands\UpdateClient;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * As the prototype's nav and sales screens: the site's sales person or manager (and the group roles)
 * keep clients and take orders; cash is confirmed by the CASH_ROLES (cashier, site manager, CEO, group
 * admin); only the CEO or group admin decide an order on the credit desk; invoices and payments are
 * recorded by sales, the cashier, the site manager or the group roles. A client's commercial terms
 * (credit limit, credit days, channel, currency) are set or changed only by the CEO or group admin:
 * sales and the site manager may register a client without credit and edit its contact details.
 */
final class OrdersAuthorizer implements Authorizer
{
    public const string CLIENTS = 'clients';

    public const string ORDERS = 'orders';

    public const string MONEY = 'money';

    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $actorId = $command->context()->actorId;
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        $siteId = $command->context()->siteId;
        if ($siteId === null) {
            throw Rejection::forbidden('Sales are recorded at a site.');
        }
        $group = [Role::Ceo, Role::GroupAdmin];
        [$roles, $why] = match (true) {
            $command instanceof ApproveOrder, $command instanceof HoldOrder => [$group, 'Only the CEO or group admin decide an order on the credit desk.'],
            $command instanceof ConfirmOrderCash => [[Role::Cashier, Role::SiteManager, ...$group], 'Only the cashier or site manager of this site may confirm cash.'],
            $command instanceof IssueInvoice, $command instanceof RecordPayment => [[Role::Sales, Role::Cashier, Role::SiteManager, ...$group], 'Only sales, the cashier or the site manager of this site may invoice or record a payment.'],
            default => [[Role::Sales, Role::SiteManager, ...$group], 'Only sales or the site manager of this site may keep clients and take orders.'],
        };
        if (! $this->access->holdsAny($actor, $siteId, $roles)) {
            throw Rejection::forbidden($why);
        }
        if (($command instanceof RegisterClient || $command instanceof UpdateClient) && ! $this->access->holdsAny($actor, $siteId, $group)) {
            $this->guardTerms($command);
        }
    }

    /** Refuses a non-group actor who sets credit terms on a new client or changes any term of an existing one. */
    private function guardTerms(RegisterClient|UpdateClient $command): void
    {
        $why = 'Only the CEO or group admin may set or change a client\'s credit limit, credit days, channel or currency.';
        $p = $command->payload;
        $num = static fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0;
        if ($command instanceof RegisterClient) {
            if ($num($p['credit_limit'] ?? 0) > 0 || $num($p['credit_days'] ?? 0) > 0) {
                throw Rejection::forbidden($why);
            }

            return;
        }
        $id = $p['client_id'] ?? null;
        $row = is_string($id) ? DB::table('clients')->where('id', $id)->first(['channel', 'currency_pref', 'credit_limit', 'credit_days']) : null;
        if ($row === null) {
            return; // The handler answers an unknown client.
        }
        $changed = (array_key_exists('channel', $p) && $p['channel'] !== $row->channel)
            || (array_key_exists('currency_pref', $p) && $p['currency_pref'] !== $row->currency_pref)
            || (array_key_exists('credit_limit', $p) && $num($p['credit_limit']) !== (float) $row->credit_limit)
            || (array_key_exists('credit_days', $p) && (int) $num($p['credit_days']) !== (int) $row->credit_days);
        if ($changed) {
            throw Rejection::forbidden($why);
        }
    }

    /** @param self::CLIENTS|self::ORDERS|self::MONEY $what */
    public function canRead(User $actor, string $siteId, string $what): bool
    {
        $roles = match ($what) {
            self::ORDERS => [Role::Sales->value, Role::SiteManager->value, Role::Cashier->value, Role::Storekeeper->value],
            default => [Role::Sales->value, Role::SiteManager->value, Role::Cashier->value],
        };

        return $this->access->canAct($actor, $siteId, $roles);
    }

    /** Group roles see a client's whole statement; site staff only their site's part. */
    public function seesGroup(User $actor): bool
    {
        return $this->access->hasGroupRole($actor);
    }
}
