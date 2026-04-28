<?php

declare(strict_types=1);

use Crumbls\Fanout\Http\Controllers\ReceiverController;
use Illuminate\Support\Facades\Route;

Route::post('{profile}', ReceiverController::class)
    ->where('profile', '[a-zA-Z0-9_\-]+')
    ->name('fanout.receive');
