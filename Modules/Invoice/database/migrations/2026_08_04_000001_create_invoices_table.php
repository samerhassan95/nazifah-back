<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('invoice_type')->default('simplified_tax_invoice');
            $table->string('currency', 3)->default('SAR');
            $table->string('status')->default('draft');
            $table->string('zatca_status')->nullable();
            $table->string('whatsapp_status')->nullable();
            $table->string('zatca_uuid')->nullable()->index();
            $table->string('zatca_reference')->nullable();
            $table->string('zatca_invoice_hash')->nullable();
            $table->longText('zatca_qr_code')->nullable();
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_vat_number')->nullable();
            $table->string('seller_registration_number')->nullable();
            $table->text('seller_address')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->json('invoice_payload')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
