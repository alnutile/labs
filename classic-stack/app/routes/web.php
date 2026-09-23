<?php

use App\Http\Controllers\AgentRunController;
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
    Route::middleware('can:use-computer-agent')->group(function () {
        Route::get('/agent', [AgentRunController::class, 'index'])->name('agent.index');
        Route::post('/agent', [AgentRunController::class, 'store'])->middleware('throttle:5,1')->name('agent.store');
        Route::get('/agent/{run}', [AgentRunController::class, 'show'])->name('agent.show');
        Route::post('/agent/{run}/cancel', [AgentRunController::class, 'cancel'])->name('agent.cancel');
        Route::get('/agent/{run}/files/{artifact}', [AgentRunController::class, 'download'])->whereNumber('artifact')->name('agent.download');
    });
    Route::get('/dashboard', [DemoRunController::class, 'index'])->name('dashboard');
    Route::post('/demo-runs', [DemoRunController::class, 'store'])->middleware('throttle:600,1')->name('demo-runs.store');
    Route::get('/demo-runs/{run}/download', [DemoRunController::class, 'download'])->name('demo-runs.download');
});
