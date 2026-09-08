<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('product', 32)->index();
            $table->string('tier', 32);
            $table->string('description')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->string('currency', 3)->default('JPY');
            $table->string('interval', 16)->default('month');
            $table->string('stripe_price_id')->nullable()->unique();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product', 'tier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
