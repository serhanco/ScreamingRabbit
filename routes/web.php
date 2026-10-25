<?php

use Illuminate\Support\Facades\Route;

// This is a private tool: the root address only forwards to the admin panel.
Route::redirect('/', '/admin');
