<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('hostname')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('os')->nullable();
            $table->string('panel', 24)->nullable();
            $table->string('agent_version', 16)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->json('thresholds')->nullable();
            $table->json('latest')->nullable();
            $table->unsignedInteger('report_interval')->default(60);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->float('cpu')->nullable();
            $table->float('ram')->nullable();
            $table->float('disk')->nullable();
            $table->float('swap')->nullable();
            $table->float('load1')->nullable();
            $table->unsignedBigInteger('net_rx')->nullable();
            $table->unsignedBigInteger('net_tx')->nullable();
            $table->unsignedInteger('mail_queue')->nullable();
            $table->unsignedInteger('mail_sent')->nullable();
            $table->unsignedInteger('mail_received')->nullable();
            $table->unsignedInteger('mail_bounced')->nullable();
            $table->unsignedInteger('mail_deferred')->nullable();
            $table->unsignedInteger('mail_rejected')->nullable();
            $table->unsignedInteger('login_ok')->nullable();
            $table->unsignedInteger('login_failed')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['server_id', 'created_at']);
        });

        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('monitors')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 16)->index();
            $table->string('target', 2048)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('method', 8)->default('GET');
            $table->unsignedInteger('interval')->default(60);
            $table->unsignedSmallInteger('timeout')->default(10);
            $table->unsignedTinyInteger('retries')->default(1);
            $table->json('settings')->nullable();
            $table->text('credentials')->nullable();
            $table->string('group')->nullable()->index();
            $table->json('tags')->nullable();
            $table->string('push_token', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->string('last_message', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_check_at']);
        });

        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            // 0 = down, 1 = up, 2 = warning (degraded), 3 = maintenance
            $table->unsignedTinyInteger('status');
            $table->unsignedInteger('response_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('details')->nullable();
            $table->string('location', 32)->default('local');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['monitor_id', 'created_at']);
        });

        Schema::create('monitor_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('up')->default(0);
            $table->unsignedInteger('down')->default(0);
            $table->unsignedInteger('warning')->default(0);
            $table->unsignedBigInteger('response_sum')->default(0);
            $table->unsignedInteger('response_count')->default(0);
            $table->unsignedInteger('min_ms')->nullable();
            $table->unsignedInteger('max_ms')->nullable();
            $table->unique(['monitor_id', 'date']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('severity', 16)->default('critical');
            $table->string('status', 16)->default('open')->index();
            $table->string('cause', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->timestamps();
            $table->index(['monitor_id', 'started_at']);
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->default('note');
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 24);
            $table->text('config');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_notification_channel', function (Blueprint $table) {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'notification_channel_id']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('registrar')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->json('nameservers')->nullable();
            $table->json('dns')->nullable();
            $table->string('dns_hash', 64)->nullable();
            $table->timestamp('dns_changed_at')->nullable();
            $table->json('ssl')->nullable();
            $table->json('email_security')->nullable();
            $table->json('subdomains')->nullable();
            $table->json('network')->nullable();
            $table->json('blacklists')->nullable();
            $table->json('alerts_sent')->nullable();
            $table->unsignedSmallInteger('warn_days')->default(30);
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('logo_url', 500)->nullable();
            $table->string('accent', 16)->default('#10b981');
            $table->string('footer_text', 500)->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('show_uptime')->default(true);
            $table->boolean('show_response')->default(true);
            $table->boolean('hide_branding')->default(false);
            $table->timestamps();
        });

        Schema::create('monitor_status_page', function (Blueprint $table) {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('display_name')->nullable();
            $table->primary(['status_page_id', 'monitor_id']);
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('maintenance_window_monitor', function (Blueprint $table) {
            $table->foreignId('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->primary(['maintenance_window_id', 'monitor_id']);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->boolean('can_write')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs', 'api_tokens', 'maintenance_window_monitor', 'maintenance_windows',
            'monitor_status_page', 'status_pages', 'domains', 'monitor_notification_channel',
            'notification_channels', 'incident_updates', 'incidents', 'monitor_daily_stats',
            'heartbeats', 'monitors', 'server_metrics', 'servers',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
