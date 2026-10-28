<?php

namespace App\Providers;

use App\Classes\UpdateCheck;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Saloon\Laravel\SaloonServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandFinished::class, [UpdateCheck::class, 'afterCommand']);
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
