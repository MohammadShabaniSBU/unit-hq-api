<?php

use App\Http\Middleware\AssignRequestId;
use App\Support\Auth\DenialContext;
use App\Support\Auth\PermissionDeniedException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        $schedule->command('ai-usage:prune')->daily();
        $schedule->command('ai-usage:sweep')->everyFifteenMinutes();
        $schedule->command('automations:run-scheduled')->everyMinute();
        $schedule->command('automations:resume-waiting')->everyMinute();
        // External reporting date spine; early daily, site-agnostic.
        $schedule->command('analytics:refresh')->daily();

        // Activation must run at least as often as billing, and is registered
        // first so same-tick hourly runs activate move-ins before billing
        // evaluates eligibility (a reverse order loses a day of rent).
        $schedule->command('contracts:activate')->hourly();
        $schedule->command('billing:run --trigger=scheduled')->hourly();
        // Sweep catches contracts enabled after the morning run (S06-04).
        $schedule->command('autopay:collect --trigger=sweep')->hourly();
        // Idempotent ladder; daily is enough, hourly is safe if wanted.
        $schedule->command('delinquency:run')->daily();
        $schedule->command('comms:sweep-orphan-attachments')->daily();
        $schedule->command('comms:sweep-uncorrelated-call-intents')->everyMinute();
        // Authoritative WA template approval sync (webhooks are latency only).
        $schedule->command('whatsapp:sync-templates')->hourly();
        // E-sign: retry artifact download before completing; belt for provider expiry.
        $schedule->command('esign:sweep-completion-pending')->hourly();
        $schedule->command('esign:sweep-expired')->daily();
        // Authoritative access convergence (nudges are latency only).
        $schedule->command('access:sync')->hourly();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('broadcasting/*'),
        );

        // PermissionDeniedException::render handles direct throws. Gate::authorize
        // converts policy denials into AccessDeniedHttpException — use DenialContext.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $previous = $e->getPrevious();
            if ($previous instanceof PermissionDeniedException) {
                return response()->json([
                    'message' => 'errors.forbidden',
                    'data' => $previous->data(),
                ], 403);
            }

            $data = DenialContext::pull();
            if ($data === null) {
                return null;
            }

            return response()->json([
                'message' => 'errors.forbidden',
                'data' => $data,
            ], 403);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => 'errors.too_many_attempts',
                'data' => null,
            ], 429);
        });
    })->create();
