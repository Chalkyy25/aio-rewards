@extends('layouts.public')

@section('title', 'Email verified')

@section('content')
    <section style="max-width:620px;margin:3rem auto;background:#fff;padding:2rem;border-radius:1rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)"
             data-testid="verify-success-root">
        <h1 style="margin:0 0 .8rem" data-testid="verify-success-title">Email verified successfully.</h1>
        <p style="color:#334155">Your AIO Rewards account is now active.</p>
        <p style="color:#334155;margin-top:.5rem">You can return to AIO Rewards on your original device — it will pick up automatically within a few seconds.</p>
    </section>
@endsection
