<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Insurance;
use App\Models\Note;
use App\Models\Offer;
use App\Models\Reservation;
use App\Models\Task;
use App\Models\Unit;
use App\Events\ModelCreated;
use App\Events\ModelDeleted;
use App\Events\ModelUpdated;
use App\Listeners\QueueAutomationMatching;
use App\Session\MorphDatabaseSessionHandler;
use App\Support\RequestId;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
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
            'task'        => Task::class,
            'note'        => Note::class,
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

        Event::listen(ModelCreated::class, [QueueAutomationMatching::class, 'handle']);
        Event::listen(ModelUpdated::class, [QueueAutomationMatching::class, 'handle']);
        Event::listen(ModelDeleted::class, [QueueAutomationMatching::class, 'handle']);
    }
}
