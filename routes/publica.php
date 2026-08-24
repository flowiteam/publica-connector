<?php

use Flowiteam\PublicaConnector\Http\Controllers\DocumentController;
use Flowiteam\PublicaConnector\Http\Controllers\MediaController;
use Flowiteam\PublicaConnector\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

/*
 * Five routes, and nothing else opened to the outside.
 *
 * The prefix carries the API version, so a v2 that changes the payload can run
 * beside v1 while sites upgrade at their own pace — which they will, because
 * they are somebody else's sites.
 */
Route::get('/ping', PingController::class)->name('publica.ping');

Route::post('/documents', [DocumentController::class, 'store'])->name('publica.documents.store');
Route::put('/documents/{id}', [DocumentController::class, 'update'])->name('publica.documents.update');
Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])->name('publica.documents.destroy');

// The pictures go up first, and the article that arrives after them points at
// where they landed rather than back at PUBLICA's own storage.
Route::post('/media', MediaController::class)->name('publica.media.store');
