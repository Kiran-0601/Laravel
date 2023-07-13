<?php

use App\Http\Controllers\AddressController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\ForgotPasswordController;
use App\Http\Controllers\Admin\ResetPasswordController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\HomeController;

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
    Route::get('/edit-profile', [HomeController::class, 'editProfile'])->name('edit-profile');
    Route::post('/update-profile', [HomeController::class, 'updateProfile'])->name('update-profile');
    Route::get('/change-password', [HomeController::class, 'changePassword'])->name('change-password');
    Route::post('/save-change-password', [HomeController::class, 'saveChangePassword'])->name('save-change-password');
    Route::get('/logout', [App\Http\Controllers\HomeController::class, 'logout'])->name('logout');

    // Feedback Form Routes
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback');
    Route::post('/feedback-submit', [FeedbackController::class, 'submit'])->name('feedback-submit');

    // Addresses Form Routes
    Route::get('/address', [AddressController::class, 'index'])->name('address');
    Route::get('address/create', [AddressController::class, 'create'])->name('address.create');
    Route::post('address/store', [AddressController::class, 'store'])->name('address.store');
    Route::get('address/edit/{id}', [AddressController::class, 'edit'])->name('address.edit');
    Route::post('address/update', [AddressController::class, 'update'])->name('address.update');
    Route::get('address/delete', [AddressController::class, 'delete'])->name('address.delete');
});

// Admin Routes
Route::group(['prefix' => 'admin'], function () {
    Route::get('login', [AuthController::class, 'showLoginForm'])->name('admin.login');  // Show login form
    Route::post('login', [AuthController::class, 'login'])->name('admin.login');     // Handle login form submission
    Route::middleware(['auth'])->group(function () {
        Route::get('home', [AuthController::class, 'index'])->name('admin.home');
        Route::get('logout', [AuthController::class, 'logout'])->name('admin.logout');
        
        // Feedback-Form Routes
        Route::get('feedback', [AdminFeedbackController::class, 'feedbackList'])->name('admin.feedback');

        // Show-Users Routes
        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::get('users/view/{id}', [UserController::class, 'view'])->name('users.view');
        Route::get('users/edit/{id}', [UserController::class, 'edit'])->name('users.edit');
        Route::post('users/update/', [UserController::class, 'update'])->name('users.update');
        Route::post('users/update-status/', [UserController::class, 'updateStatus'])->name('users.update-status');
        Route::post('users/{id}', [UserController::class, 'delete'])->name('users.delete');
    });
    // Forgot Password & Reset Password Routes
    Route::get('password/reset', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('admin.password.request');
    Route::post('password/email', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('admin.password.email');
    Route::get('password/reset/{token}', [ResetPasswordController::class, 'showResetForm'])->name('admin.password.reset');
    Route::post('password/reset', [ResetPasswordController::class, 'reset'])->name('admin.password.update');
});