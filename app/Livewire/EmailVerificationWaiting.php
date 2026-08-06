<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The "Almost there — verify your email" waiting page.
 *
 * Every ~2.5s (`wire:poll.2500ms`) we re-query the CURRENTLY-AUTHENTICATED
 * user directly from the database (`User::find(auth()->id())`) — never the
 * in-memory `auth()->user()` snapshot — and, the moment
 * `email_verified_at` becomes non-null, we redirect to the ambassador
 * dashboard. This means verification done on ANY device (phone, tablet,
 * incognito tab) flips the original browser within a couple of seconds
 * without a manual refresh, and without websockets.
 */
#[Layout('layouts.public')]
class EmailVerificationWaiting extends Component
{
    public bool $verified = false;

    public function mount(): void
    {
        // If the user is already verified when they land here (browser
        // came back after verification on another device, refresh, deep
        // link, ...) redirect immediately.
        if ($this->isVerifiedFresh()) {
            $this->redirectToDashboard();
        }
    }

    /** Called by wire:poll — cheap DB round trip, no in-memory reuse. */
    public function checkVerified(): void
    {
        if ($this->isVerifiedFresh()) {
            $this->verified = true;
            $this->redirectToDashboard();
        }
    }

    public function render(): View
    {
        return view('livewire.email-verification-waiting');
    }

    private function isVerifiedFresh(): bool
    {
        $id = auth()->id();
        if (! $id) {
            return false;
        }

        $fresh = User::query()->whereKey($id)->first();

        return $fresh !== null && $fresh->email_verified_at !== null;
    }

    private function redirectToDashboard(): void
    {
        $this->redirect(route('ambassador.dashboard'), navigate: false);
    }
}
