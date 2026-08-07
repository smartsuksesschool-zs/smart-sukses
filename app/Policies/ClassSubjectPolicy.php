<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksClassSchedulePermission;

/**
 * Modul "Kelas & Jadwal" (PRD 1.1.2) — lihat trait untuk detail matriks.
 */
class ClassSubjectPolicy
{
    use ChecksClassSchedulePermission;
}
