<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tanaad\Shared\Http\ProblemResponse;
use Tanaad\Shared\Ledger\Rejection;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->dontReport(Rejection::class);

        $exceptions->render(fn (Rejection $e) => ProblemResponse::fromRejection($e));
        $exceptions->render(fn (ValidationException $e) => ProblemResponse::make(422, 'invalid_payload', 'The request failed validation.', ['details' => ['errors' => $e->errors()]]));
        $exceptions->render(fn (AuthenticationException $e) => ProblemResponse::make(401, 'unauthenticated', 'Sign in first.'));
        $exceptions->render(fn (AuthorizationException $e) => ProblemResponse::make(403, 'forbidden', $e->getMessage() !== '' ? $e->getMessage() : 'This role may not perform this action here.'));
        $exceptions->render(fn (ModelNotFoundException $e) => ProblemResponse::make(404, 'not_found', 'Not found.'));
        $exceptions->render(fn (NotFoundHttpException $e) => ProblemResponse::make(404, 'not_found', 'Not found.'));
    })->create();
