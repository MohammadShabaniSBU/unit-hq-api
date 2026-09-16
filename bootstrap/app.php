<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RejectCountryMismatch;
use App\Support\Country\CountryProfiles;
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
        $middleware->appendToGroup('api', [RejectCountryMismatch::class]);

        // API-only: never call route('login') — that named route does not exist and
        // throws RouteNotFoundException while building AuthenticationException.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') || $request->expectsJson()
            ? null
            : '/');
    })
    ->withSchedule(function (Schedule $schedule): void {
        $skipMismatch = static fn (): bool => CountryProfiles::isLockedMismatch();

        $schedule->command('system-events:maintain')->daily()->skip($skipMismatch);
        $schedule->command('activitylog:prune-tiers')->daily()->skip($skipMismatch);
        $schedule->command('ai-usage:prune')->daily()->skip($skipMismatch);
        $schedule->command('ai-usage:sweep')->everyFifteenMinutes()->skip($skipMismatch);
        $schedule->command('automations:run-scheduled')->everyMinute()->skip($skipMismatch);
        $schedule->command('automations:resume-waiting')->everyMinute()->skip($skipMismatch);
        $schedule->command('analytics:refresh')->daily()->skip($skipMismatch);
        $schedule->command('insights:validate')->daily()->skip($skipMismatch);
        $schedule->command('agents:check-model-prices')->daily()->skip($skipMismatch);

        // Activation must run at least as often as billing, and is registered
        // first so same-tick runs activate move-ins before billing evaluates
        // eligibility (a reverse order loses a day of rent). Hourly plus
        // immediately before the daily billing run.
        $schedule->command('contracts:activate')->hourly()->skip($skipMismatch);

        $billingAt = '00:30';
        $billingTz = 'UTC';
        try {
            $profile = CountryProfiles::current();
            $billingAt = $profile->billingRunAt();
            $billingTz = $profile->schedulerTimezone();
        } catch (\Throwable) {
            // Schedule still registers; skip() below no-ops a broken profile.
        }

        $schedule->command('contracts:activate')
            ->dailyAt($billingAt)
            ->timezone($billingTz)
            ->skip($skipMismatch);
        $schedule->command('billing:run --trigger=scheduled')
            ->dailyAt($billingAt)
            ->timezone($billingTz)
            ->skip($skipMismatch);
        $schedule->command('autopay:collect --trigger=sweep')->hourly()->skip($skipMismatch);
        $schedule->command('delinquency:run')->daily()->skip($skipMismatch);
        $schedule->command('comms:sweep-orphan-attachments')->daily()->skip($skipMismatch);
        $schedule->command('comms:sweep-uncorrelated-call-intents')->everyMinute()->skip($skipMismatch);
        $schedule->command('agents:sweep-pending-actions')->everyTenMinutes()->skip($skipMismatch);
        $schedule->command('whatsapp:sync-templates')->hourly()->skip($skipMismatch);
        $schedule->command('esign:sweep-completion-pending')->hourly()->skip($skipMismatch);
        $schedule->command('esign:sweep-expired')->daily()->skip($skipMismatch);
        $schedule->command('access:sync')->hourly()->skip($skipMismatch);
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
