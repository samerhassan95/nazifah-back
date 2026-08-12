<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust the local nginx reverse proxy so scheme/host detection (and
        // therefore signed URL verification, e.g. invoice share links) is
        // correct behind SSL termination.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(\App\Http\Middleware\ForceJsonResponse::class);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\SetLocale::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'banned' => \App\Http\Middleware\CheckBanned::class,
            'vendor.permission' => \Modules\Vendor\Http\Middleware\CheckVendorPermission::class,
        ]);

        // Disable TrimStrings and ConvertEmptyStringsToNull for API to prevent BadRequestException
        $middleware->validateCsrfTokens(except: ['*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Force all exceptions to return JSON (API-only project)
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return true; // Always return JSON
        });

        // Handle unauthenticated exceptions
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            return response()->json([
                'status' => false,
                'code' => 401,
                'message' => __('auth.unauthenticated'),
            ], 401);
        });

        // Use centralized exception handler for all other exceptions
        $exceptions->render(function (Throwable $e, $request) {
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return null; // Let the handler above handle it
            }

            return handleExceptionResponse($e);
        });
    })->create();
