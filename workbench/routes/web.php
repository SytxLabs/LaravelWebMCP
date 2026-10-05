<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Catalog;

Route::get('/', fn () => view('workbench::welcome'));
Route::get('/second', fn () => view('workbench::second'));

// WebMCP switched off for this page (Permissions-Policy feature "tools"): registration must fail softly.
Route::get('/no-tools', fn () => response(view('workbench::welcome'))->header('Permissions-Policy', 'tools=()'));

// Target of the declarative form (a normal page for user submits; agents get the tool result instead).
Route::get('/search', function (Request $request) {
    $found = Catalog::search((string) $request->query('query', ''), $request->query('category'), $request->filled('max_price') ? (float) $request->query('max_price') : null, $request->boolean('in_stock'));

    return response()->json($found);
});

// A throwaway login so the example can show manifests changing with the user.
Route::post('/demo/login', function () {
    session(['demo_user' => 1]);

    return response()->json(['ok' => true]);
});

Route::post('/demo/logout', function () {
    session()->forget('demo_user');

    return response()->json(['ok' => true]);
});
