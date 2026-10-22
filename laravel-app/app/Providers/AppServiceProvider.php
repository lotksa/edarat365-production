<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\AssociationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (\App\Scopes\AssociationScope::MODELS as $name) {
            $class = 'App\\Models\\'.$name;
            $class::addGlobalScope(new \App\Scopes\AssociationScope);
            $class::saving(fn ($model) => app(\App\Services\WorkspaceWrites::class)->prepare($model));
        }
    }
}
