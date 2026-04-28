<?php

declare(strict_types=1);

use Crumbls\Fanout\Testing\TestSinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test sink routes
|--------------------------------------------------------------------------
|
| Loaded only when config('fanout.testing.sink_enabled') is true.
| NEVER enable in production — captures are stored unencrypted by design.
|
*/

Route::post('{name}',          [TestSinkController::class, 'capture'])->name('fanout.sink.capture');
Route::get('{name}/captures',  [TestSinkController::class, 'index'])  ->name('fanout.sink.index');
Route::delete('{name}',        [TestSinkController::class, 'clear'])  ->name('fanout.sink.clear');
