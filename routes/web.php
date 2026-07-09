<?php

declare(strict_types=1);

use CodeGuardian\Laravel\Http\Controllers\DashboardController;
use CodeGuardian\Laravel\Http\Controllers\Integrations\SentryController;
use CodeGuardian\Laravel\Http\Controllers\Integrations\SlackController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('codeguardian.index');
Route::get('/insights', [DashboardController::class, 'insights'])->name('codeguardian.insights');

// Integration panels (plugin-style; nav is built from the IntegrationRegistry).
Route::get('/sentry', [SentryController::class, 'index'])->name('codeguardian.sentry.index');
Route::post('/sentry/{id}/fix', [SentryController::class, 'fix'])->name('codeguardian.sentry.fix')->where('id', '[^/]+');
Route::get('/sentry/{id}', [SentryController::class, 'show'])->name('codeguardian.sentry.show')->where('id', '[^/]+');
Route::get('/slack', [SlackController::class, 'index'])->name('codeguardian.slack.index');
Route::get('/slack/{channel}/{ts}', [SlackController::class, 'show'])->name('codeguardian.slack.show')
    ->where('channel', '[A-Za-z0-9._-]+')->where('ts', '[0-9.]+');
Route::get('/new', [DashboardController::class, 'create'])->name('codeguardian.create');
Route::post('/runs', [DashboardController::class, 'store'])->name('codeguardian.store');
Route::get('/runs/{id}', [DashboardController::class, 'show'])->name('codeguardian.show');
Route::get('/runs/{id}/status', [DashboardController::class, 'status'])->name('codeguardian.status');
Route::get('/runs/{id}/report', [DashboardController::class, 'report'])->name('codeguardian.report');
Route::post('/runs/{id}/fix', [DashboardController::class, 'fix'])->name('codeguardian.fix');
Route::delete('/runs/{id}', [DashboardController::class, 'destroy'])->name('codeguardian.destroy');
