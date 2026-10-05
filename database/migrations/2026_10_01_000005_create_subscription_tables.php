<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency_code', 3);
            $table->enum('billing_cycle', ['monthly', 'quarterly', 'yearly']);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->unsignedSmallInteger('max_room_types')->nullable();
            $table->unsignedSmallInteger('max_units')->nullable();
            $table->unsignedSmallInteger('max_users')->nullable();
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->unsignedSmallInteger('plan_id');
            $table->enum('status', ['trial', 'active', 'grace', 'expired', 'suspended', 'cancelled']);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('grace_ends_on')->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency_code', 3);
            $table->boolean('auto_renew')->default(true);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['property_id', 'status', 'ends_on']);
            $table->index(['status', 'ends_on']);
            $table->foreign('property_id')->references('id')->on('properties');
            $table->foreign('plan_id')->references('id')->on('subscription_plans');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
