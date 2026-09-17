<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        // Require authentication even locally; production also needs the email allowlist.
        Horizon::auth(fn ($request) => $request->user() &&
            (app()->environment('local') || Gate::allows('viewHorizon')));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user) => in_array($user->email, config('stack.horizon_emails'), true));
    }
}
