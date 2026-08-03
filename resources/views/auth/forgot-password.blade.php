@extends('layouts.public')

@section('title', 'Reset your password')

@push('head')
<style>
    .card { max-width: 420px; margin: 2rem auto; background: #fff; border-radius: 1rem;
            padding: 2rem; box-shadow: 0 2px 30px -12px rgba(15,23,42,.12); }
    label { display: block; margin: .8rem 0 .3rem; font-weight: 500; }
    input[type=email] { width: 100%; padding: .7rem .75rem; border: 1px solid #cbd5e1;
                        border-radius: .5rem; font-size: 1rem; box-sizing: border-box; }
    button.primary { width: 100%; padding: .85rem 1rem; background: #0f172a; color: #fff;
                     border: 0; border-radius: .5rem; font-weight: 600; margin-top: 1rem;
                     cursor: pointer; }
    .flash-status { background: #ecfdf5; color: #065f46; padding: .75rem 1rem; border-radius: .5rem;
                    margin-bottom: 1rem; }
    .alert-error { background: #fef2f2; color: #991b1b; padding: .75rem 1rem; border-radius: .5rem; }
    .subtle { color: #64748b; font-size: .9rem; margin-top: 1rem; text-align: center; }
</style>
@endpush

@section('content')
    <div class="card">
        <h1 style="margin:0 0 .5rem;font-size:1.5rem">Forgot your password?</h1>
        <p style="color:#64748b;margin:0 0 1.5rem">Enter your email and we'll send you a reset link.</p>

        @if (session('status'))
            <div class="flash-status" data-testid="forgot-flash">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-error" data-testid="forgot-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" data-testid="forgot-form">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email" data-testid="forgot-email">
            <button class="primary" type="submit" data-testid="forgot-submit">Email password reset link</button>
        </form>

        <p class="subtle"><a href="{{ route('login') }}">Back to sign in</a></p>
    </div>
@endsection
