<?php

namespace App\Services\Checks;

use App\Models\Monitor;

interface Checker
{
    public function check(Monitor $monitor): CheckResult;
}
