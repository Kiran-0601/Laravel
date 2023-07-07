<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ForgotPasswordController;
use App\Http\Controllers\Admin\ResetPasswordController;
use App\Http\Controllers\Admin\UserController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Auth::routes(['verify' => true]);
Route::middleware(['auth','verified'])->group(function () {
    Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
    Route::get('/logout', [App\Http\Controllers\HomeController::class, 'logout'])->name('logout');
});

// Admin Routes
Route::group(['prefix' => 'admin'], function () {
    Route::get('login', [AuthController::class, 'showLoginForm'])->name('admin.login');  // Show login form
    Route::post('login', [AuthController::class, 'login'])->name('admin.login');     // Handle login form submission
    Route::middleware(['auth'])->group(function () {
        Route::get('home', [AuthController::class, 'index'])->name('admin.home');
        Route::get('logout', [AuthController::class, 'logout'])->name('admin.logout');

        // Show-Users Routes
        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::get('users/view/{id}', [UserController::class, 'view'])->name('users.view');
        Route::get('users/edit/{id}', [UserController::class, 'edit'])->name('users.edit');
        Route::post('users/update/', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{id}', [UserController::class, 'delete'])->name('users.delete');
    });
    // Forgot Password & Reset Password Routes
    Route::get('password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('admin.password.request');
    Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('admin.password.email');
    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('admin.password.reset');
    Route::post('password/reset', [ResetPasswordController::class, 'reset'])->name('admin.password.update');
});