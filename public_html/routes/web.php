<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/terminal/tesoreria/{any?}', function () {
    return file_get_contents(public_path('terminal/tesoreria/index.html'));
})->where('any', '.*');