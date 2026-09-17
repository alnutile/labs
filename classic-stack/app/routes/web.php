<?php

use App\Http\Controllers\DemoRunController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::get('/ready', function () {
    DB::select('select 1');
    Redis::connection()->ping();

    return response()->json(['status' => 'ok']);
});
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DemoRunController::class, 'index'])->name('dashboard');
    Route::post('/demo-runs', [DemoRunController::class, 'store'])->middleware('throttle:600,1')->name('demo-runs.store');
    Route::get('/demo-runs/{run}/download', [DemoRunController::class, 'download'])->name('demo-runs.download');
});
