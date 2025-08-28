<?php

namespace Dedsec\LaravelAutoSeeder;

use Illuminate\Support\ServiceProvider;

class AutoSeederServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autoseeder.php', 'autoseeder');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/autoseeder.php' => config_path('autoseeder.php'),
            ], 'config');

            $this->commands([
                Console\MakeAutoSeedersCommand::class,
            ]);
        }
    }
}
