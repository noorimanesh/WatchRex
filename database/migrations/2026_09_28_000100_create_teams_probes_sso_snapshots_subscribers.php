<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHARED = ['monitors', 'servers', 'domains', 'status_pages', 'notification_channels', 'maintenance_windows'];

    public function up(): void
    {
        // ── Teams ────────────────────────────────────────────────────────
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // owner | admin | developer | viewer
            $table->string('role', 16)->default('developer');
            $table->timestamps();
            $table->primary(['team_id', 'user_id']);
        });

        foreach (self::SHARED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            });
        }

        // ── SSO ──────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_sub')->nullable()->unique()->after('password');
        });

        // ── Multi-location probes ────────────────────────────────────────
        Schema::create('probes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location', 32)->unique();
            $table->string('country_code', 2)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('ip', 45)->nullable();
            $table->string('version', 16)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_probe', function (Blueprint $table) {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('probe_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'probe_id']);
        });

        // ── Content & visual change detection ───────────────────────────
        Schema::create('content_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8)->default('text'); // text | visual
            $table->string('hash', 64);
            $table->longText('content')->nullable();
            $table->string('path')->nullable();
            $table->float('change_percent')->nullable();
            $table->json('diff')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['monitor_id', 'kind', 'id']);
        });

        // ── Status page e-mail subscribers ───────────────────────────────
        Schema::table('status_pages', function (Blueprint $table) {
            $table->boolean('allow_subscribers')->default(true);
        });

        Schema::create('status_page_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['status_page_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_subscribers');
        Schema::dropIfExists('content_snapshots');
        Schema::dropIfExists('monitor_probe');
        Schema::dropIfExists('probes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('oidc_sub'));
        foreach (self::SHARED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('team_id'));
        }
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
