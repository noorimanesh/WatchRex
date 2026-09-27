<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ProbeController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

// Push monitors (cron jobs, backups, scripts).
Route::match(['get', 'post'], '/push/{token}', PushController::class)
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware('throttle:120,1')
    ->name('api.push');

// Server agent reports.
Route::post('/agent/report', [AgentController::class, 'report'])->middleware('throttle:30,1')->name('api.agent');

// Remote probes (multi-location monitoring).
Route::get('/probe/jobs', [ProbeController::class, 'jobs'])->middleware('throttle:30,1')->name('api.probe.jobs');
Route::post('/probe/results', [ProbeController::class, 'results'])->middleware('throttle:60,1')->name('api.probe.results');

// REST API v1 (Authorization: Bearer wrx_api_…).
Route::prefix('v1')->middleware(['api.token', 'throttle:120,1'])->group(function () {
    Route::get('/summary', [V1Controller::class, 'summary']);
    Route::get('/monitors', [V1Controller::class, 'monitors']);
    Route::get('/monitors/{monitor}', [V1Controller::class, 'monitor']);
    Route::get('/monitors/{monitor}/heartbeats', [V1Controller::class, 'heartbeats']);
    Route::post('/monitors/{monitor}/toggle', [V1Controller::class, 'toggle']);
    Route::get('/incidents', [V1Controller::class, 'incidents']);
    Route::get('/servers', [V1Controller::class, 'servers']);
});
