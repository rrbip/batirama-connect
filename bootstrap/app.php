<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Configuration des proxies de confiance pour CWP/Apache
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Enregistrement des alias de middleware pour l'API whitelabel
        $middleware->alias([
            'deployment.key' => \App\Http\Middleware\ValidateDeploymentKey::class,
            'deployment.domain' => \App\Http\Middleware\ValidateDeploymentDomain::class,
            'deployment.rate' => \App\Http\Middleware\RateLimitDeployment::class,
            'deployment.cors' => \App\Http\Middleware\DynamicCors::class,
            'editor.quota' => \App\Http\Middleware\CheckEditorQuota::class,
            'editor.auth' => \App\Http\Middleware\EditorApiAuth::class,
            'partner.auth' => \App\Http\Middleware\PartnerApiAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Ensure API routes always return JSON responses
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            // Return JSON for API routes or when Accept header requests JSON
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
