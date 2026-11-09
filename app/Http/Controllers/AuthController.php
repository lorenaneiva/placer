<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function registerForm(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{8,30}$/'], 'password' => ['required', 'confirmed', Password::min(8)]]);
        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('catalog'))->with('success', 'Conta criada com sucesso.');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.hash('sha256', $data['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.']);
        }
        if (! Auth::attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended($request->user()->is_admin ? route('admin.dashboard') : route('catalog'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('catalog')->with('success', 'Você saiu da sua conta.');
    }
}
