<?php

declare(strict_types=1);

use CodeGuardian\Laravel\Http\Controllers\SlackController;
use Illuminate\Support\Facades\Route;

// Slack App endpoints. No `web`/CSRF or dashboard auth — each request is
// verified by its Slack signature (VerifySlackSignature middleware).
Route::post('/slack/command', [SlackController::class, 'command'])->name('codeguardian.slack.command');
Route::post('/slack/interact', [SlackController::class, 'interact'])->name('codeguardian.slack.interact');
