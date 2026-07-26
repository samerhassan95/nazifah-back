<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->foreignId('icon_id')->nullable()->constrained('icons')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'service_id']);
        });

        if (Schema::hasTable('branch_service') && Schema::hasTable('branches')) {
            $rows = DB::table('branch_service')
                ->join('branches', 'branches.id', '=', 'branch_service.branch_id')
                ->select('branches.vendor_id', 'branch_service.service_id')
                ->distinct()
                ->get();

            $now = now();
            foreach ($rows as $row) {
                DB::table('vendor_service')->insertOrIgnore([
                    'vendor_id' => $row->vendor_id,
                    'service_id' => $row->service_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_service');
    }
};
