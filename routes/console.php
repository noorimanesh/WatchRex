<?php

use Illuminate\Support\Facades\Schedule;

// Monitor checks — sub-minute scheduling keeps 30s / 20s intervals accurate.
Schedule::command('watchrex:dispatch')->everyTenSeconds()->withoutOverlapping(1);

// Domain intelligence (WHOIS, DNS changes, SSL, subdomains, blacklists).
Schedule::command('watchrex:domains')->everyThirtyMinutes()->withoutOverlapping();

// Plan renewal reminders and expiry (SaaS billing).
Schedule::command('watchrex:plans')->dailyAt('08:05')->withoutOverlapping();

// Data retention.
Schedule::command('watchrex:prune')->dailyAt('03:17')->withoutOverlapping();
