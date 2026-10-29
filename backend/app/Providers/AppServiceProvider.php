<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Contracts\BusinessEngineInterface;
use App\Core\Business\BusinessEngine;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BusinessEngineInterface::class, BusinessEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Applies to HTTP, queue workers, schedules and CLI, including future named connections.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\ConnectionEstablished::class,static function($event){\App\Support\ReleaseWriteFence::attach($event->connection);});
        foreach(\Illuminate\Support\Facades\DB::getConnections()as$connection)\App\Support\ReleaseWriteFence::attach($connection);
        if($this->app->runningInConsole())\App\Support\ReleaseWriteFence::assertDatabaseAccess();
        \App\Support\ReleaseRuntimeConfig::apply();
    }
}
