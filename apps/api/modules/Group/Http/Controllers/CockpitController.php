<?php

declare(strict_types=1);

namespace Tanaad\Group\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tanaad\Group\Queries\ApprovalsQuery;
use Tanaad\Group\Queries\AuditQuery;
use Tanaad\Group\Queries\CockpitQuery;
use Tanaad\Group\Queries\GroupScope;
use Tanaad\Shared\Http\ApiController;

/** Group HQ read views: cockpit tiles, approvals, audit feed. */
final class CockpitController extends ApiController
{
    public function __construct(private readonly GroupScope $scope) {}

    public function cockpit(Request $request, CockpitQuery $query): JsonResponse
    {
        $this->scope->groupOnly($request, 'the cockpit');

        return new JsonResponse(['data' => $query->build()]);
    }

    public function approvals(Request $request, ApprovalsQuery $query): JsonResponse
    {
        $this->scope->groupOnly($request, 'the approvals list');

        return new JsonResponse(['data' => $query->build()]);
    }

    public function audit(Request $request, AuditQuery $query): JsonResponse
    {
        $readable = $this->scope->readableSites($this->scope->user($request));

        return new JsonResponse($query->page($readable, $request->only(['site', 'type', 'from', 'to', 'page', 'per_page'])));
    }
}
