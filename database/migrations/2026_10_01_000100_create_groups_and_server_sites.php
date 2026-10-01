<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ── Groups (nested, many-to-many with monitors) ─────────────────
        Schema::create('monitor_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('monitor_groups')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // general | website | mail | server | service
            $table->string('kind', 16)->default('general')->index();
            $table->string('domain')->nullable()->index();
            $table->string('color', 16)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('group_monitor', function (Blueprint $table) {
            $table->foreignId('monitor_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_group_id', 'monitor_id']);
        });

        Schema::create('monitor_group_status_page', function (Blueprint $table) {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('display_name')->nullable();
            $table->boolean('expanded')->default(true);
            $table->primary(['status_page_id', 'monitor_group_id']);
        });

        Schema::table('status_pages', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });

        // ── Sites discovered on servers by the agent ────────────────────
        Schema::create('server_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('account', 64)->nullable();
            $table->string('kind', 16)->default('main'); // main | addon | sub | alias
            $table->foreignId('monitor_group_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('ignored')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['server_id', 'domain']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });

        // ── Migrate the old free-text monitors.group into real groups ────
        $rows = DB::table('monitors')->whereNotNull('group')->where('group', '!=', '')
            ->select('id', 'user_id', 'team_id', 'group')->get();
        $created = [];
        foreach ($rows as $row) {
            $key = $row->user_id.'|'.Str::lower($row->group);
            if (! isset($created[$key])) {
                $created[$key] = DB::table('monitor_groups')->insertGetId([
                    'user_id' => $row->user_id, 'team_id' => $row->team_id, 'name' => $row->group,
                    'kind' => preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $row->group) ? 'website' : 'general',
                    'domain' => preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $row->group) ? Str::lower($row->group) : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('group_monitor')->insertOrIgnore(['monitor_group_id' => $created[$key], 'monitor_id' => $row->id]);
        }
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('settings'));
        Schema::dropIfExists('server_sites');
        Schema::table('status_pages', fn (Blueprint $table) => $table->dropColumn('settings'));
        Schema::dropIfExists('monitor_group_status_page');
        Schema::dropIfExists('group_monitor');
        Schema::dropIfExists('monitor_groups');
    }
};
