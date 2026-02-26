<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MultiAgentController;
use App\Http\Controllers\CategoryController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Tasks
    Route::apiResource('tasks', TaskController::class);
    Route::get('/tasks/stats', [TaskController::class, 'stats']);
    
    // AI
    Route::post('/ai/chat', [AIController::class, 'chat']);
    Route::post('/ai/analyze-task', [AIController::class, 'analyzeTask']); // ← ADD THIS LINE
    
    // Documents
    Route::post('/documents/analyze', [DocumentController::class, 'analyze']);
    
    // Multi-agent
    Route::post('/multi-agent/process', [MultiAgentController::class, 'process']);
    
    // Categories
    Route::apiResource('categories', CategoryController::class);
    Route::post('/tasks/{task}/categories', [CategoryController::class, 'attachToTask']);
});