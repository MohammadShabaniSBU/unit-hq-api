<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        // API-only: never call route('login') — that named route does not exist and
        // throws RouteNotFoundException while building AuthenticationException.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') || $request->expectsJson()
            ? null
            : '/');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('system-events:maintain')->daily();
        $schedule->command('activitylog:prune-tiers')->daily();
        $schedule->command('automations:run-scheduled')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('broadcasting/*'),
        );
    })->create();
