<?php

namespace Flowiteam\PublicaConnector;

use Flowiteam\PublicaConnector\Contracts\ReceivesDocuments;
use Flowiteam\PublicaConnector\Http\Middleware\VerifyPublicaSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the package does to a site, in one file.
 *
 * The whole point of this package is that installing it is the entire
 * integration: the routes register themselves, the migration is there when it
 * is wanted, and a site with nothing configured beyond a token is already a
 * working destination. Anything that would need a paragraph of instructions
 * belongs here instead of in a README nobody reaches.
 */
class PublicaConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/publica.php', 'publica');

        /*
         * The receiver is resolved from the container, so a site can bind its
         * own without touching the config at all — and the config route stays
         * for sites that would rather name a class than write a binding.
         */
        $this->app->bind(ReceivesDocuments::class, function ($app) {
            $custom = config('publica.receiver');

            return $custom ? $app->make($custom) : $app->make(EloquentReceiver::class);
        });
    }

    public function boot(): void
    {
        $this->registerRoutes();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/publica.php' => config_path('publica.php'),
            ], 'publica-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'publica-migrations');
        }
    }

    /**
     * The four routes, behind the signature check.
     *
     * `api` rather than `web`, because there is no session, cookie or CSRF
     * token in a machine-to-machine call — putting the web group on would open
     * a session on this site for every article that arrives, for nobody.
     */
    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => trim((string) config('publica.prefix', 'publica'), '/').'/v1',
            'middleware' => array_merge(
                (array) config('publica.middleware', ['api']),
                [VerifyPublicaSignature::class],
            ),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/publica.php');
        });
    }
}
