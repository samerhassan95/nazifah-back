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
        DB::table('zones')->orderBy('id')->chunkById(100, function ($zones): void {
            foreach ($zones as $zone) {
                $points = is_string($zone->points) ? json_decode($zone->points, true) : $zone->points;

                if (! is_array($points)) {
                    continue;
                }

                $normalized = [];
                foreach ($points as $point) {
                    if (! is_array($point) && ! is_object($point)) {
                        continue;
                    }

                    $pointArr = (array) $point;
                    $latitude = $pointArr['latitude'] ?? $pointArr['lat'] ?? $pointArr[1] ?? null;
                    $longitude = $pointArr['longitude'] ?? $pointArr['lng'] ?? $pointArr['long'] ?? $pointArr[0] ?? null;

                    if ($latitude === null || $longitude === null) {
                        continue;
                    }

                    $normalized[] = [
                        'latitude' => (float) $latitude,
                        'longitude' => (float) $longitude,
                    ];
                }

                DB::table('zones')
                    ->where('id', $zone->id)
                    ->update(['points' => json_encode($normalized)]);
            }
        });

        Schema::table('zones', function (Blueprint $table) {
            if (Schema::hasColumn('zones', 'type')) {
                $table->dropColumn('type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            if (! Schema::hasColumn('zones', 'type')) {
                $table->string('type')->default('polygon')->after('description');
            }
        });
    }
};
