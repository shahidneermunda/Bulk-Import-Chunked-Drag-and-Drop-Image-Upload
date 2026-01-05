<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\ProductController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/import', function () {
    return view('import');
})->name('import');

// Products routes
Route::get('/api/products', [ProductController::class, 'index']);

// CSV Import routes
Route::post('/api/csv/import', [CsvImportController::class, 'import']);

// Chunked upload routes
Route::post('/api/upload/init', [ChunkedUploadController::class, 'init']);
Route::post('/api/upload/chunk', [ChunkedUploadController::class, 'uploadChunk']);
Route::post('/api/upload/complete', [ChunkedUploadController::class, 'complete']);
Route::get('/api/upload/status', [ChunkedUploadController::class, 'status']);
Route::post('/api/upload/attach', [ChunkedUploadController::class, 'attachToProduct']);
