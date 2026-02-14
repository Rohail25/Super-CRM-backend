<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: CORS runs for every request (including OPTIONS that don't match a route)
        $middleware->prepend(\App\Http\Middleware\AddCorsHeaders::class);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\EnforceTenantIsolation::class,
            \App\Http\Middleware\ScopeByCompany::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'storage/*', // Allow public access to storage files
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, \Throwable $e, Request $request) {
            try {
                $path = $request->path();
                if ((str_starts_with($path, 'api/') || $path === 'api') && !$response->headers->has('Access-Control-Allow-Origin')) {
                    $origin = $request->header('Origin');
                    $allowedOrigins = [
                        'https://app.leox24.com',
                        'http://localhost:3000',
                        'http://localhost:5173',
                    ];
                    $allowOrigin = in_array($origin, $allowedOrigins) ? $origin : '*';
                    
                    $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
                    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-XSRF-TOKEN');
                    if ($origin && in_array($origin, $allowedOrigins)) {
                        $response->headers->set('Access-Control-Allow-Credentials', 'true');
                    }
                }
            } catch (\Throwable $t) {
                // avoid breaking the response if CORS logic fails
            }
            return $response;
        });
    })->create();
