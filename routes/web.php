<?php

// Routes for Web Pages (HTML)

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
