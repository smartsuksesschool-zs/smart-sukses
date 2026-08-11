<?php

namespace App\Livewire\Ppdb;

use App\Models\School;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * API 4.7 — GET /ppdb/schools (Public):
 * "Daftar cabang yang membuka PPDB (untuk landing page publik)".
 */
class SchoolList extends Component
{
    public function render(): View
    {
        return view('livewire.ppdb.school-list', [
            // Cabang yang membuka PPDB = tenant aktif (ERD 2.2 schools.is_active).
            'schools' => School::query()->active()->orderBy('name')->get(),
        ])->layout('layouts.ppdb', ['school' => null, 'title' => 'Daftar Cabang']);
    }
}
