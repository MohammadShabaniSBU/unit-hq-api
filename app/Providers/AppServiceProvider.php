<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Insurance;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Unit;
use App\Session\MorphDatabaseSessionHandler;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'contact'     => Contact::class,
            'deal'        => Deal::class,
            'offer'       => Offer::class,
            'reservation' => Reservation::class,
            'unit'        => Unit::class,
            'contract'    => Contract::class,
            'insurance'   => Insurance::class,
        ]);

        Session::extend('database', function ($app) {
            $config = $app->make('config');
            $connection = $app['db']->connection($config->get('session.connection'));

            return new MorphDatabaseSessionHandler(
                $connection,
                $config->get('session.table'),
                $config->get('session.lifetime'),
                $app,
            );
        });

        Queue::createPayloadUsing(function (): array {
            $requestId = RequestId::get();

            return $requestId !== null ? ['request_id' => $requestId] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            $requestId = $event->job->payload()['request_id'] ?? null;

            if (is_string($requestId) && $requestId !== '') {
                RequestId::set($requestId);
            }
        });
    }
}
