<?php

use App\Http\Controllers\EstimateGenerationController;
use App\Http\Controllers\GenerationIndexController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\RecoverGenerationController;
use App\Http\Controllers\StoreGenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', GenerationIndexController::class)->name('generations.index');
    Route::post('generations/estimate', EstimateGenerationController::class)->name('generations.estimate');
    Route::post('generations', StoreGenerationController::class)->middleware('throttle:generation-submissions')->name('generations.store');
    Route::post('generations/{generation}/recover', RecoverGenerationController::class)->whereNumber('generation')->name('generations.recover');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/generations')->name('dashboard');
});

require __DIR__.'/settings.php';
