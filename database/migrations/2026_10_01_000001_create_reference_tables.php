<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name', 64);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('minor_units')->default(2);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->char('iso2', 2)->primary();
            $table->char('iso3', 3)->unique();
            $table->string('name', 100);
            $table->string('phone_code', 8)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('default_timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreign('currency_code')->references('code')->on('currencies');
        });

        Schema::create('states', function (Blueprint $table) {
            $table->increments('id');
            $table->char('country_iso2', 2);
            $table->string('code', 10);
            $table->string('name', 100);
            $table->string('tax_region_code', 10)->nullable();
            $table->unique(['country_iso2', 'code']);
            $table->foreign('country_iso2')->references('iso2')->on('countries');
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 50);
            $table->string('native_name', 50);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
        });

        Schema::create('property_types', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 30)->unique();
            $table->string('label_key', 80);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_types');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('currencies');
    }
};
