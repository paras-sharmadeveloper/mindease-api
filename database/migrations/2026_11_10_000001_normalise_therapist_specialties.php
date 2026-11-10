<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Specialties become focus-area keys (config mindease.focus_areas). Free-text values that match a key or a label
 * ("Anxiety", "low mood", "Low mood") are converted; anything else is dropped (the panel now offers a fixed list).
 */
return new class extends Migration
{
    public function up(): void
    {
        $areas = config('mindease.focus_areas');
        $lookup = [];
        foreach ($areas as $key => $label) {
            $lookup[$key] = $key;
            $lookup[strtolower($label)] = $key;
            $lookup[str_replace('_', ' ', $key)] = $key;
        }

        foreach (DB::table('therapists')->select('id', 'specialties')->get() as $row) {
            $old = json_decode($row->specialties ?? '[]', true) ?: [];
            $new = array_values(array_unique(array_filter(array_map(
                fn ($s) => is_string($s) ? ($lookup[strtolower(trim($s))] ?? null) : null,
                $old,
            ))));
            if ($new !== $old) {
                DB::table('therapists')->where('id', $row->id)->update(['specialties' => json_encode($new)]);
            }
        }
    }

    public function down(): void {}
};
