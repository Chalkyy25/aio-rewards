@extends('layouts.ambassador')

@section('title', 'Dashboard')

@section('content')
    <h1 style="margin:0 0 1rem">Welcome, {{ auth()->user()->name }}</h1>
    <p style="color:#475569">
        Your referral link, click counters, pending conversions and reward progress will appear here in Phase 6.
    </p>
@endsection
