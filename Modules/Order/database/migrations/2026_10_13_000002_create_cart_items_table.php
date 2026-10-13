<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cart line. Prices are never stored here — they're always resolved live
 * from service_piece / service_addition_piece at read time (CartService),
 * same as the rest of the catalog, so a price change never goes stale.
 *
 * service_ids holds every main service selected for this piece (a cart line
 * can carry more than one main service on the same piece, same as an order
 * line — see OrderItemsNormalizer::mainServiceIds()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->onDelete('cascade');
            $table->foreignId('piece_id')->constrained('pieces')->onDelete('cascade');
            $table->json('service_ids');
            $table->json('additional_service_ids')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('note', 500)->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
