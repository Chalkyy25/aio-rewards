@extends('layouts.public')

@section('title', 'Choose a new password')

@push('head')
<style>
    .card { max-width: 420px; margin: 2rem auto; background: #fff; border-radius: 1rem;
            padding: 2rem; box-shadow: 0 2px 30px -12px rgba(15,23,42,.12); }
    label { display: block; margin: .8rem 0 .3rem; font-weight: 500; }
    input { width: 100%; padding: .7rem .75rem; border: 1px solid #cbd5e1; border-radius: .5rem;
            font-size: 1rem; box-sizing: border-box; }
    button.primary { width: 100%; padding: .85rem 1rem; background: #0f172a; color: #fff;
                     border: 0; border-radius: .5rem; font-weight: 600; margin-top: 1rem;
                     cursor: pointer; }
    .alert-error { background: #fef2f2; color: #991b1b; padding: .75rem 1rem; border-radius: .5rem; }
</style>
@endpush

@section('content')
    <div class="card">
        <h1 style="margin:0 0 1rem;font-size:1.5rem">Choose a new password</h1>

        @if ($errors->any())
            <div class="alert-error" data-testid="reset-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" data-testid="reset-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="email" value="{{ $email }}" data-testid="reset-email">

            <label for="password">New password (min 12 chars)</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" data-testid="reset-password">

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" data-testid="reset-password-confirm">

            <button class="primary" type="submit" data-testid="reset-submit">Reset password</button>
        </form>
    </div>
@endsection
