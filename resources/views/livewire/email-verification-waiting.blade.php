<div wire:poll.2500ms="checkVerified"
     data-testid="verify-waiting-root"
     style="max-width:620px;margin:3rem auto;background:#fff;padding:2rem;border-radius:1rem;box-shadow:0 2px 30px -12px rgba(15,23,42,.12)">
    <h1 style="margin:0 0 .8rem" data-testid="verify-waiting-title">Almost there — verify your email</h1>
    <p style="color:#334155">
        Thanks for activating your Ambassador account. We've sent a verification link
        to <strong>{{ auth()->user()->email }}</strong>. Please click the link in that
        email to unlock your dashboard.
    </p>
    <p data-testid="verify-waiting-hint" style="color:#64748b;font-size:.9rem;margin-top:.5rem">
        This page will detect verification automatically — even if you open the email on your phone.
    </p>

    @if (session('status'))
        <p data-testid="verify-flash" style="background:#ecfdf5;color:#065f46;padding:.75rem 1rem;border-radius:.5rem">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" style="margin-top:1.5rem">
        @csrf
        <button type="submit" data-testid="verify-resend"
                style="background:#0f172a;color:#fff;border:0;padding:.7rem 1.2rem;border-radius:.5rem;font-weight:600;cursor:pointer">
            Resend verification email
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" style="margin-top:1rem">
        @csrf
        <button type="submit" data-testid="verify-logout"
                style="background:none;border:0;color:#0f172a;text-decoration:underline;cursor:pointer;padding:0">
            Sign out
        </button>
    </form>
</div>
