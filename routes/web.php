<?php

use Illuminate\Support\Facades\Route;

// Anything that is not a real route belongs to the SPA, which owns its own
// routing (React Router). A fallback is used rather than a catch-all pattern so
// that routes registered later still win.
Route::fallback(fn () => view('welcome'))->name('spa');
