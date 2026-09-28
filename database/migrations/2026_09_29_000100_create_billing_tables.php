<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('plan_expires_at')->nullable()->after('plan');
            $table->json('billing_notices')->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 24);
            $table->string('period', 8); // monthly | yearly
            $table->unsignedBigInteger('subtotal'); // Rial
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('amount');
            $table->string('gateway', 24);
            $table->string('authority', 100)->nullable()->unique();
            $table->string('ref_id', 100)->nullable();
            $table->string('card_pan', 32)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('failure', 255)->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['plan_expires_at', 'billing_notices']));
    }
};
