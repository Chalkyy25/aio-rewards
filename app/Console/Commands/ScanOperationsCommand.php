<?php

namespace App\Console\Commands;

use App\Domain\Operations\EscalationSweeper;
use App\Domain\Operations\OperationsScanner;
use Illuminate\Console\Command;

class ScanOperationsCommand extends Command
{
    protected $signature = 'aio:ops-scan';

    protected $description = 'Scan business events and (re)build Operations Centre work items, then run the escalation sweep.';

    public function handle(OperationsScanner $scanner, EscalationSweeper $escalator): int
    {
        $stats = $scanner->scan();
        $esc = $escalator->sweep();

        $this->info('Ops scan complete.');
        $this->line('  by_type: '.json_encode($stats['by_type']));
        $this->line('  auto_resolved: '.$stats['auto_resolved']);
        $this->line('  escalated: '.$esc['escalated']);

        return self::SUCCESS;
    }
}
