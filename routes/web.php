<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => 'MindEase API', 'version' => 'v1', 'docs' => '/docs/api', 'developers' => '/developers']));

// Developer portal: guides + reference + "Try it" console for the mobile team.
Route::get('developers', function () {
    abort_unless(config('mindease.developer_docs'), 404);

    return view('developers', ['baseUrl' => url('/v1')]);
});
