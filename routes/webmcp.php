<?php

use Illuminate\Support\Facades\Route;
use SytxLabs\LaravelWebMcp\Http\Controllers\InvokeController;
use SytxLabs\LaravelWebMcp\Http\Controllers\ManifestController;

Route::get('{server}/manifest', ManifestController::class)->where('server', '[a-z0-9][a-z0-9_-]*')->name('webmcp.manifest');
Route::post('{server}/tools/{tool}', [InvokeController::class, 'tool'])->where(['server' => '[a-z0-9][a-z0-9_-]*', 'tool' => '[A-Za-z0-9_.\-]{1,128}'])->name('webmcp.tools');
Route::post('{server}/resources/{resource}', [InvokeController::class, 'resource'])->where(['server' => '[a-z0-9][a-z0-9_-]*', 'resource' => '[A-Za-z0-9_.\-]{1,128}'])->name('webmcp.resources');
