<?php

declare(strict_types=1);

use CodeGuardian\Laravel\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('codeguardian.index');
Route::get('/insights', [DashboardController::class, 'insights'])->name('codeguardian.insights');
Route::get('/new', [DashboardController::class, 'create'])->name('codeguardian.create');
Route::post('/runs', [DashboardController::class, 'store'])->name('codeguardian.store');
Route::get('/runs/{id}', [DashboardController::class, 'show'])->name('codeguardian.show');
Route::get('/runs/{id}/status', [DashboardController::class, 'status'])->name('codeguardian.status');
Route::get('/runs/{id}/report', [DashboardController::class, 'report'])->name('codeguardian.report');
Route::post('/runs/{id}/fix', [DashboardController::class, 'fix'])->name('codeguardian.fix');
Route::delete('/runs/{id}', [DashboardController::class, 'destroy'])->name('codeguardian.destroy');
