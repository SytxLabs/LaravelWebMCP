<?php

use Illuminate\Support\Facades\Route;
use SytxLabs\LaravelWebMcp\Http\Controllers\EmbedController;

Route::get('_assets/webmcp-core.js', [EmbedController::class, 'asset'])->name('webmcp.embed.asset');
Route::get('{server}/embed.js', [EmbedController::class, 'script'])->where('server', '[a-z0-9][a-z0-9_-]*')->name('webmcp.embed.script');
Route::get('{server}/embed/manifest', [EmbedController::class, 'manifest'])->where('server', '[a-z0-9][a-z0-9_-]*')->name('webmcp.embed.manifest');
