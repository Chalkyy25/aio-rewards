<?php

namespace App\Console\Commands;

use App\Domain\Credits\AccountCreditReservationService;
use Illuminate\Console\Command;

class ExpireStaleAccountCreditReservationsCommand extends Command
{
    protected $signature = 'aio:expire-account-credit-reservations';

    protected $description = 'Expire stale pending Account Credit reservations so held balances become spendable again.';

    public function handle(AccountCreditReservationService $reservations): int
    {
        $count = $reservations->expireStale();
        $this->info("Expired {$count} stale Account Credit reservation(s).");

        return self::SUCCESS;
    }
}
