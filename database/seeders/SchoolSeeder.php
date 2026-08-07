<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

/**
 * Cabang awal (tenant) agar akun School Level punya induk school_id.
 * Manajemen cabang selengkapnya adalah modul tersendiri (API 4.3).
 */
class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::updateOrCreate(
            ['code' => 'PUSAT'],
            [
                'name' => 'Smart Sukses School Pusat',
                'slug' => 'pusat',
                'primary_color' => '#1B3A6B',
                'secondary_color' => '#E07020',
                'is_active' => true,
            ],
        );
    }
}
