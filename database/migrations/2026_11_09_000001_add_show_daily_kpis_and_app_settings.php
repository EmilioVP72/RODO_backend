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
        if (Schema::hasTable('restaurants') && !Schema::hasColumn('restaurants', 'show_daily_kpis')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('show_daily_kpis')->default(false)->after('fixed_delivery_fee');
            });
        }

        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('restaurants') && Schema::hasColumn('restaurants', 'show_daily_kpis')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('show_daily_kpis');
            });
        }

        Schema::dropIfExists('app_settings');
    }
};

