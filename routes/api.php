<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\UserController;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AddressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('register', [AuthController::class, 'register']);

Route::get('show-users', [UserController::class, 'index']);

Route::get('show/{id}', [UserController::class, 'show']);

Route::put('update/{id}', [UserController::class, 'update']);

Route::delete('delete/{id}', [UserController::class, 'delete']);

// Route::get('/hello', function () {
//     return response()->json("Kiran");
// });
