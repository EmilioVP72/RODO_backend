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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('restaurant_id')
                ->constrained('restaurants')
                ->restrictOnDelete();

            $table->string('folio', 40)->unique();

            // Identificador privado para el ticket compartido
            $table->uuid('public_token')->unique();

            // Datos del cliente
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 25);

            // Dirección
            $table->string('street', 255);
            $table->string('neighborhood', 150);
            $table->text('address_reference')->nullable();
            $table->text('map_url')->nullable();

            // Distancia y tarifa
            $table->decimal('distance_km', 6, 2);
            $table->decimal('delivery_fee', 10, 2);

            // Cobro
            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount_to_collect', 10, 2)->nullable();
            $table->boolean('needs_change')->default(false);
            $table->string('change_from', 50)->nullable();

            // Información del pedido
            $table->text('description')->nullable();
            $table->text('comments')->nullable();
            $table->boolean('is_ready')->default(false);

            // Seguimiento
            $table->string('status', 30)->default('pending');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

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
        Schema::dropIfExists('orders');
    }
};
