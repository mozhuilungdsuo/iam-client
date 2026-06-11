<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nagaland\IamClient\Http\Controllers\IamAuthController;

Route::get('login', [IamAuthController::class, 'redirect'])->name('iam.login');
Route::get('callback', [IamAuthController::class, 'callback'])->name('iam.callback');
Route::post('logout', [IamAuthController::class, 'logout'])->name('iam.logout');
