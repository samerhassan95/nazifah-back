<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends vendor_bank_accounts to support:
 *  - bank_id   : the chosen bank from the admin-managed `banks` list.
 *  - branch_id : null = a general (vendor-wide) account for all branches,
 *                set  = a branch-private account.
 *  - vendor approval level (vendor_status / vendor_rejection_reason) used for
 *    branch-private accounts which must be approved by the vendor before the
 *    admin can approve them. The existing `status`/`rejection_reason` stay as
 *    the admin approval level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bank_accounts', function (Blueprint $table) {
            $table->foreignId('bank_id')->nullable()->after('vendor_id')
                ->constrained('banks')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('bank_id')
                ->constrained('branches')->cascadeOnDelete();
            $table->enum('vendor_status', ['pending', 'approved', 'rejected'])
                ->default('approved')->after('status');
            $table->text('vendor_rejection_reason')->nullable()->after('vendor_status');

            $table->index('branch_id');
            $table->index('vendor_status');
        });

        // bank_name becomes optional now that bank_id is the source of truth.
        Schema::table('vendor_bank_accounts', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['vendor_status', 'vendor_rejection_reason']);
        });
    }
};
