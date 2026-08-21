<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureApiAuthLevel;
use App\Http\Middleware\RecordAuditIpAddress;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // API 4.1 — Base URL `.../api/v1`. Prefiks versinya ada di dalam
        // routes/api.php supaya `api` tetap menjadi prefiks bawaan Laravel dan
        // versi berikutnya dapat hidup berdampingan.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetUserLocale::class,
            // Arsitektur 3.4 — bagian "Custom Middleware" dari audit log.
            RecordAuditIpAddress::class,
        ]);

        // Jalur API memakai token Bearer, bukan sesi: tidak ada CSRF dan tidak
        // ada cookie. IP tetap dicatat supaya jejak audit dari API sama
        // lengkapnya dengan dari panel.
        $middleware->api(append: [
            RecordAuditIpAddress::class,
        ]);

        $middleware->alias([
            'auth_level' => EnsureApiAuthLevel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Amplop galat API 4.1. Panel Filament tidak tersentuh — renderernya
        // mengembalikan NULL untuk request di luar `api/*`.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
