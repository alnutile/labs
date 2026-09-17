<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('stack:user {email} {--name=Operator}', function () {
    $password = $this->secret('Password (at least 12 characters)');
    Validator::make(['email' => $this->argument('email'), 'password' => $password], [
        'email' => ['required', 'email', 'unique:users,email'],
        'password' => ['required', 'string', 'min:12'],
    ])->validate();
    User::create(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password]);
    $this->info('User created. Horizon access is controlled by HORIZON_ALLOWED_EMAILS.');
})->purpose('Create an account when public registration is disabled');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
