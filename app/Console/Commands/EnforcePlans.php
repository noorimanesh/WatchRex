<?php

namespace App\Console\Commands;

use App\Services\Billing\Billing;
use Illuminate\Console\Command;

class EnforcePlans extends Command
{
    protected $signature = 'watchrex:plans';

    protected $description = 'Send plan renewal reminders and downgrade expired plans';

    public function handle(Billing $billing): int
    {
        $stats = $billing->enforceExpiry();
        $this->line(json_encode($stats));

        return self::SUCCESS;
    }
}
