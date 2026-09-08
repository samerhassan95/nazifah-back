<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order numbers switched from ORD-YYYYMMDD-##### to plain sequential integers
 * (see 40da41c "Switch order numbering to plain sequential integers"). That
 * implementation computed "next number" by scanning MAX(order_number) across
 * orders/payments/pending_orders under a lockForUpdate() that only held for
 * the duration of a short-lived sub-transaction — released long before the
 * number was actually reserved, so concurrent requests could compute the same
 * "next" value. Under real contention this exhausted its 10-retry budget and
 * fell back to a millisecond timestamp (e.g. order #1788875674742 reaching a
 * customer via SMS).
 *
 * A single counter row incremented under lockForUpdate() inside one
 * transaction is genuinely atomic: a second transaction's lockForUpdate() on
 * the same row blocks until the first commits, so two requests can never be
 * handed the same value — no retries, no collision, no fallback needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });

        // Seed the counter to continue from the highest *real* sequential
        // number already issued. Excludes the millisecond-timestamp fallback
        // values (13 digits, > 1 billion) — those aren't sequence members,
        // and seeding from one would make every future order number equally
        // huge, the exact problem this migration exists to fix.
        $ceiling = 999999999;

        $maxOrder = (int) (DB::table('orders')
            ->whereRaw("order_number REGEXP '^[0-9]+$'")
            ->whereRaw('CAST(order_number AS UNSIGNED) <= ?', [$ceiling])
            ->max(DB::raw('CAST(order_number AS UNSIGNED)')) ?? 0);

        $maxPayment = (int) (DB::table('payment_transactions')
            ->whereRaw("transaction_id REGEXP '^[0-9]+$'")
            ->whereRaw('CAST(transaction_id AS UNSIGNED) <= ?', [$ceiling])
            ->max(DB::raw('CAST(transaction_id AS UNSIGNED)')) ?? 0);

        $maxPending = 0;
        DB::table('pending_orders')
            ->where('order_data->order_number', 'REGEXP', '^[0-9]+$')
            ->select(['order_data'])
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$maxPending, $ceiling) {
                foreach ($rows as $row) {
                    $orderData = json_decode($row->order_data, true);
                    $number = $orderData['order_number'] ?? null;
                    if (is_string($number) && preg_match('/^\d+$/', $number)) {
                        $value = (int) $number;
                        if ($value <= $ceiling) {
                            $maxPending = max($maxPending, $value);
                        }
                    }
                }
            });

        $seed = max($maxOrder, $maxPayment, $maxPending);

        DB::table('order_number_sequences')->insert([
            'id' => 1,
            'next_value' => $seed + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
