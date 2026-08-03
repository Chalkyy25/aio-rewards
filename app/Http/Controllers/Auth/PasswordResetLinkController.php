<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function show(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);

        Password::sendResetLink(['email' => strtolower($data['email'])]);

        // Always report success to avoid user enumeration.
        return back()->with('status', __('If an account with that email exists, we have sent a password reset link.'));
    }
}
