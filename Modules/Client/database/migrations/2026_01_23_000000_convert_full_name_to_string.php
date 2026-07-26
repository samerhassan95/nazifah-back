<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, add a temporary column
        Schema::table('clients', function (Blueprint $table) {
            $table->string('full_name_temp', 100)->nullable()->after('full_name');
        });

        // Convert existing JSON data to string
        $clients = DB::table('clients')->get();

        foreach ($clients as $client) {
            $fullName = json_decode($client->full_name, true);

            // Extract string from JSON or use existing value
            $newName = 'Customer';
            if (is_array($fullName)) {
                $newName = $fullName['en'] ?? $fullName['ar'] ?? 'Customer';
            } elseif (! empty($client->full_name)) {
                $newName = $client->full_name;
            }

            DB::table('clients')
                ->where('id', $client->id)
                ->update(['full_name_temp' => $newName]);
        }

        // Drop old column
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });

        // Rename temp column to full_name
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('full_name_temp', 'full_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert back to JSON (this is a lossy operation)
        Schema::table('clients', function (Blueprint $table) {
            $table->json('full_name_temp')->nullable()->after('full_name');
        });

        $clients = DB::table('clients')->get();

        foreach ($clients as $client) {
            $jsonName = json_encode([
                'en' => $client->full_name,
                'ar' => $client->full_name,
            ]);

            DB::table('clients')
                ->where('id', $client->id)
                ->update(['full_name_temp' => $jsonName]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('full_name_temp', 'full_name');
        });
    }
};
