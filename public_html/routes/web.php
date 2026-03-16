<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/terminals/tesoreria/{any?}', function () {
    return file_get_contents(public_path('terminals/tesoreria/index.html'));
})->where('any', '.*');