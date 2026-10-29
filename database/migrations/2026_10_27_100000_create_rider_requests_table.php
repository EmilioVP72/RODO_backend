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
        Schema::create('rider_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->restrictOnDelete();

            $table->string('folio', 40)->unique();
            $table->uuid('public_token')->unique();

            $table->unsignedSmallInteger('riders_count')->default(1);
            $table->text('note')->nullable();

            // Estado de atención por el líder de repartidores
            $table->string('status', 30)->default('pending'); // pending, assigned, completed, cancelled

            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rider_requests');
    }
};
