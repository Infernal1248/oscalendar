<?php

use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\Internal\ParserJobController;
use App\Http\Controllers\Internal\SyncResultController;
use App\Http\Controllers\Internal\SyncRunController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('internal')
    ->middleware('internal.api')
    ->group(function () {
        Route::post('/sync-runs/start', [SyncRunController::class, 'start']);
        Route::post('/sync-runs/{syncRun}/finish', [SyncRunController::class, 'finish']);
        Route::post('/sync-runs/{syncRun}/log', [SyncRunController::class, 'log']);
        Route::post('/parser-jobs/claim', [ParserJobController::class, 'claim']);
        Route::post('/parser-jobs/{syncRun}/heartbeat', [ParserJobController::class, 'heartbeat']);
        Route::post('/sync-result', [SyncResultController::class, 'store']);
    });

Route::post('/telegram/webhook', TelegramWebhookController::class);
Route::get('/calendar/{token}.ics', [CalendarFeedController::class, 'show']);
