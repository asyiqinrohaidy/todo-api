<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\MultiAgentController;

// ✅ CRITICAL: Handle OPTIONS preflight requests for CORS
Route::options('{any}', function (Request $request) {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin')
        ->header('Access-Control-Allow-Credentials', 'true')
        ->header('Access-Control-Max-Age', '86400');
})->where('any', '.*');

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Tasks
    Route::apiResource('tasks', TaskController::class);
    Route::get('/tasks/stats', [TaskController::class, 'stats']);
    
    // AI
    Route::post('/ai/chat', [AIController::class, 'chat']);
    Route::post('/ai/analyze', [AIController::class, 'analyzeTask']);
    
    // Multi-agent
    Route::post('/multi-agent/process', [MultiAgentController::class, 'process']);
});