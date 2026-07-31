<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\modpackhub\ModpackClientController;

Route::get('/config', [ModpackClientController::class, 'config']);

Route::middleware('throttle:40,1')->group(function () {
    Route::get('/search', [ModpackClientController::class, 'search']);
    Route::get('/packs/{source}/{packId}/versions', [ModpackClientController::class, 'versions']);

    Route::get('/packs/{source}/{packId}/versions/{versionId}/preflight', [ModpackClientController::class, 'preflight']);
});

Route::get('/servers/{server}/installs', [ModpackClientController::class, 'installs']);

Route::middleware('throttle:10,1')->post('/servers/{server}/install', [ModpackClientController::class, 'install']);
