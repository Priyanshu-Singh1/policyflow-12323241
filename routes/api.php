<?php

use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\PolicyReconciliationController;
use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\PolicySubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow REST API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicySubmissionController::class, 'submit']);
    Route::post('/{id}/reconcile', [PolicyReconciliationController::class, 'reconcile']);
});

Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [MockInsurerController::class, 'callback']);
    Route::get('/policies/{reference}', [MockInsurerController::class, 'status']);
});

Route::get('/operations/summary', [OperationsController::class, 'summary']);
