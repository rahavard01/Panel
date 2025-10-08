<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // ...
    ];

    public function boot(): void
    {
        $this->registerPolicies();

        // ✅ ادمین یعنی نقش = 1
        Gate::define('admin', function ($user) {
            return (int)($user->role ?? 0) === 1;
        });
    }
}
