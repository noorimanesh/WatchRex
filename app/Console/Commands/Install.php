<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class Install extends Command
{
    protected $signature = 'watchrex:install {--name=} {--email=} {--password=}';

    protected $description = 'Create the first WatchRex administrator';

    public function handle(): int
    {
        $this->line('<fg=green>🦖 WatchRex</> — Infrastructure & Uptime Monitoring · by Fabapars');

        $name = $this->option('name') ?: text('Admin name', default: 'Administrator', required: true);
        $email = $this->option('email') ?: text('Admin e-mail', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Invalid e-mail');
        $pass = $this->option('password') ?: password('Password (min 10 chars)', required: true, validate: fn ($v) => strlen($v) < 10 ? 'At least 10 characters' : null);

        $validator = validator(['password' => $pass], ['password' => [Password::min(10)]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => strtolower($email)], [
            'name' => $name,
            'password' => $pass,
            'role' => UserRole::Admin,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);

        $this->info("Administrator {$user->email} is ready. Sign in at ".url('/login'));

        return self::SUCCESS;
    }
}
