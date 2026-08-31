<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Internal\ParserJobController;
use App\Http\Controllers\Internal\PartialSyncResultController;
use App\Http\Controllers\Internal\SyncResultController;
use App\Http\Controllers\Internal\SyncRunController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/auth/login', [AccountController::class, 'login'])->middleware('throttle:10,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/account', [AccountController::class, 'me']);
    Route::patch('/account', [AccountController::class, 'updateProfile']);
    Route::post('/auth/logout', [AccountController::class, 'logout']);
    Route::get('/dashboard', [AccountController::class, 'dashboard']);
    Route::get('/workplan', [AccountController::class, 'workplan']);
    Route::get('/change-history', [AccountController::class, 'changeHistory']);
    Route::get('/workplan/flights/{flightSegment}', [AccountController::class, 'flight']);
    Route::get('/admin/users', [AdminUserController::class, 'index']);
    Route::patch('/admin/users/{user}', [AdminUserController::class, 'update']);
    Route::get('/admin/permissions', [AdminUserController::class, 'permissions']);
});

Route::prefix('internal')
    ->middleware('internal.api')
    ->group(function () {
        Route::post('/sync-runs/start', [SyncRunController::class, 'start']);
        Route::post('/sync-runs/{syncRun}/finish', [SyncRunController::class, 'finish']);
        Route::post('/sync-runs/{syncRun}/log', [SyncRunController::class, 'log']);
        Route::post('/sync-runs/{syncRun}/partial-result', [PartialSyncResultController::class, 'store']);
        Route::post('/parser-jobs/claim', [ParserJobController::class, 'claim']);
        Route::post('/parser-jobs/{syncRun}/heartbeat', [ParserJobController::class, 'heartbeat']);
        Route::post('/sync-result', [SyncResultController::class, 'store']);
    });

Route::post('/telegram/webhook', TelegramWebhookController::class);
Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'show']);
