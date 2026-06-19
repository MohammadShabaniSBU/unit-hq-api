<?php

namespace App\Providers;

use App\Models\Insurance;
use App\Models\Unit;
use App\Session\MorphDatabaseSessionHandler;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'unit'      => Unit::class,
            'insurance' => Insurance::class,
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
    }
}
