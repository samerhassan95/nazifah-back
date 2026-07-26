<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Map zone names to Saudi city codes
        $cityMappings = [
            'Riyadh' => 'SA01',
            'الرياض' => 'SA01',
            'Jeddah' => 'SA02',
            'جدة' => 'SA02',
            'Makkah' => 'SA02',
            'مكة' => 'SA02',
            'Medina' => 'SA03',
            'المدينة' => 'SA03',
            'Madinah' => 'SA03',
            'Dammam' => 'SA04',
            'الدمام' => 'SA04',
            'Sharqiyah' => 'SA04',
            'الشرقية' => 'SA04',
            'Qassim' => 'SA05',
            'القصيم' => 'SA05',
            'Hail' => 'SA06',
            'حائل' => 'SA06',
            'Tabuk' => 'SA07',
            'تبوك' => 'SA07',
            'Arar' => 'SA08',
            'عرعر' => 'SA08',
            'Shamaliyah' => 'SA08',
            'الشمالية' => 'SA08',
            'Jizan' => 'SA09',
            'جيزان' => 'SA09',
            'Jazan' => 'SA09',
            'جازان' => 'SA09',
            'Najran' => 'SA10',
            'نجران' => 'SA10',
            'Bahah' => 'SA11',
            'الباحة' => 'SA11',
            'Jawf' => 'SA12',
            'الجوف' => 'SA12',
            'Abha' => 'SA14',
            'أبها' => 'SA14',
            'Asir' => 'SA14',
            'عسير' => 'SA14',
        ];

        $zones = DB::table('zones')->get();

        foreach ($zones as $zone) {
            $zoneName = $zone->name;
            $code = null;

            foreach ($cityMappings as $cityName => $cityCode) {
                if (stripos($zoneName, $cityName) !== false) {
                    $code = $cityCode;
                    break;
                }
            }

            if ($code) {
                // Check if code already exists, append suffix if needed
                $existingWithCode = DB::table('zones')
                    ->where('code', $code)
                    ->where('id', '!=', $zone->id)
                    ->exists();

                if ($existingWithCode) {
                    // Find next available suffix
                    $suffix = 1;
                    while (DB::table('zones')->where('code', $code.'_'.$suffix)->exists()) {
                        $suffix++;
                    }
                    $code = $code.'_'.$suffix;
                }

                DB::table('zones')
                    ->where('id', $zone->id)
                    ->update(['code' => $code]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('zones')->update(['code' => null]);
    }
};
