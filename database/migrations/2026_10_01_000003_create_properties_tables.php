<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->nullable()->unique();
            $table->string('slug', 80)->unique();
            $table->string('name', 150);
            $table->string('tagline', 150)->nullable();
            $table->string('legal_name', 190)->nullable();
            $table->unsignedSmallInteger('property_type_id');
            $table->unsignedTinyInteger('star_rating')->nullable();
            $table->enum('status', ['onboarding', 'active', 'suspended', 'inactive'])->default('onboarding')->index();
            $table->string('onboarding_step', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->char('country_iso2', 2);
            $table->unsignedInteger('state_id')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('default_language', 10)->default('en');
            $table->string('date_format', 20)->default('DD MMM YYYY');
            $table->string('number_format', 20)->default('en-IN');
            $table->unsignedTinyInteger('week_start')->default(1);
            $table->time('check_in_time')->default('14:00:00');
            $table->time('check_out_time')->default('11:00:00');
            $table->string('tax_registration_no', 30)->nullable();
            $table->date('business_date')->nullable();
            $table->unsignedInteger('ari_version')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();

            $table->index('property_type_id');
            $table->index(['country_iso2', 'status']);
            $table->foreign('property_type_id')->references('id')->on('property_types');
            $table->foreign('country_iso2')->references('iso2')->on('countries');
            $table->foreign('state_id')->references('id')->on('states');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('default_language')->references('code')->on('languages');
        });

        Schema::create('property_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id');
            $table->string('key', 80);
            $table->json('value');
            $table->dateTime('updated_at');
            $table->primary(['property_id', 'key']);
            $table->foreign('property_id')->references('id')->on('properties');
        });

        Schema::create('property_languages', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id');
            $table->string('language_code', 10);
            $table->primary(['property_id', 'language_code']);
            $table->foreign('property_id')->references('id')->on('properties');
            $table->foreign('language_code')->references('code')->on('languages');
        });

        Schema::create('property_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id');
            $table->string('counter_key', 30);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->primary(['property_id', 'counter_key']);
            $table->foreign('property_id')->references('id')->on('properties');
        });

        Schema::create('property_age_bands', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('property_id');
            $table->enum('code', ['infant', 'child', 'teen']);
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age');
            $table->unique(['property_id', 'code']);
            $table->foreign('property_id')->references('id')->on('properties');
        });

        DB::statement('ALTER TABLE property_age_bands ADD CONSTRAINT ck_age_band CHECK (min_age <= max_age)');
    }

    public function down(): void
    {
        Schema::dropIfExists('property_age_bands');
        Schema::dropIfExists('property_counters');
        Schema::dropIfExists('property_languages');
        Schema::dropIfExists('property_settings');
        Schema::dropIfExists('properties');
    }
};
