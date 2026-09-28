<?php

use Illuminate\Support\Facades\Route;

// Public pages rendered outside the SPA, reachable without signing in. Google
// reviews both when approving Business Profile API access.
Route::view('/', 'public.landing')->name('home');
Route::view('/privacy', 'public.privacy')->name('privacy');

// Anything that is not a real route belongs to the SPA, which owns its own
// routing (React Router). A fallback is used rather than a catch-all pattern so
// that routes registered later still win.
Route::fallback(fn () => view('welcome'))->name('spa');
