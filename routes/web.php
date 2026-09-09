<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Only client-side pages return the SPA; API and missing assets keep their own responses.
Route::get('/{page?}', function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 503, 'Фронтенд не установлен. Загрузите содержимое dist в public.');

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache',
    ]);
})->where('page', 'login|dashboard|profile|workplan|history|deviations|admin/(users|permissions)');
