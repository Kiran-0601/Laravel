<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use AuthenticatesUsers;

    protected $redirectTo = RouteServiceProvider::AHOME;

    public function showLoginForm()
    {
        return view('admin.login');
    }
    public function login(Request $request)
    {
        $this->validateLogin($request);
        if (
            method_exists($this, 'hasTooManyLoginAttempts') &&
            $this->hasTooManyLoginAttempts($request)
        ) {
            $this->fireLockoutEvent($request);
            return $this->sendLockoutResponse($request);
        }
        if ($this->attemptLogin($request)) {
            if ($request->hasSession()) {
                $request->session()->put('auth.password_confirmed_at', time());
            }
            return $this->sendLoginResponse($request);
        }
        $this->incrementLoginAttempts($request);
        return $this->sendFailedLoginResponse($request);
    }
    protected function attemptLogin(Request $request)
    {
        $credentials = $this->credentials($request);
        $credentials['user_type'] = 1; // Add user_type condition

        return $this->guard()->attempt(
            $credentials,
            $request->filled('remember')
        );
    }
    public function redirectPath()
    {
        if (method_exists($this, 'redirectTo')) {
            return $this->redirectTo();
        }

        return property_exists($this, 'redirectTo') ? $this->redirectTo : '/admin/home';
    }
    public function logout()
    {
        Auth::logout();
        return redirect()->route('admin.login');
    }
    public function index()
    {
        return view('admin.home');
    }
}
