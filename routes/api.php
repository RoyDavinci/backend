<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DisputeController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'createUser']);
Route::delete('/delete/user/{id}', [AuthController::class, 'deleteUser']);
Route::post('/update/user', [AuthController::class, 'updateUser']);
Route::get('/disputes/categories', [DisputeController::class, 'getCategoriesAndSubcategories']);
Route::post('/dispute', [DisputeController::class, 'store']);
Route::get('/disputes', [DisputeController::class, 'fetchDisputes']);
Route::delete('/delete/dispute', [DisputeController::class, 'deleteDispute']);
Route::get('/disputes/{id}', [DisputeController::class, 'getDisputeById']);
Route::get('/disputes/view/{id}', [DisputeController::class, 'getDisputeByIdForView']);
Route::get('/users/{id}', [AuthController::class, 'getUserById']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/dispute/update', [DisputeController::class, 'updateDispute']);
Route::post('/dispute/reply', [DisputeController::class, 'addReply']);
Route::get('/users', [AuthController::class, 'fetchUsers']);
