<?php

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\ServiceProvider;
use Saloon\Laravel\SaloonServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(SaloonServiceProvider::class);

        Artisan::starting(function ($artisan) {
            $artisan->setName(<<<'ASCII'
            
 __        _____ _   _ _   ___  __
 \ \      / /_ _| \ | | | | \ \/ /
  \ \ /\ / / | ||  \| | | | |\  /
   \ V  V /  | || |\  | |_| |/  \
    \_/\_/  |___|_| \_|\___//_/\_\


ASCII);
        });
    }
}
