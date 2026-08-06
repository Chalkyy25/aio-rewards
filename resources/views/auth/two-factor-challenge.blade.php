@extends('layouts.public')
@section('title', 'Two-factor authentication')

@section('content')
    <div style="max-width:420px;margin:3rem auto;padding:0 1rem" data-testid="mfa-challenge-page">
        <div style="background:#fff;padding:2rem;border-radius:.75rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)">
            <h1 style="font-size:1.5rem;margin:0 0 .5rem">Enter your authenticator code</h1>
            <p style="color:#64748b;margin:0 0 1.5rem">Open your authenticator app and enter the 6-digit code. You can also use a one-time recovery code.</p>

            <form method="POST" action="{{ route('login.challenge.submit') }}" autocomplete="off">
                @csrf
                <label>Code
                    <input type="text" name="code" inputmode="numeric" required autofocus
                           data-testid="input-mfa-code"
                           style="width:100%;padding:.7rem;border:1px solid #cbd5e1;border-radius:.5rem;margin-top:.35rem;font-size:1.1rem;letter-spacing:.15em">
                </label>
                @error('code')
                    <div style="color:#dc2626;margin-top:.5rem" data-testid="mfa-error">{{ $message }}</div>
                @enderror
                <button type="submit" data-testid="btn-verify-mfa"
                        style="width:100%;padding:.85rem 1rem;background:#0f172a;color:#fff;border:0;border-radius:.5rem;font-weight:600;margin-top:1rem;cursor:pointer">
                    Verify
                </button>
            </form>
        </div>
    </div>
@endsection
