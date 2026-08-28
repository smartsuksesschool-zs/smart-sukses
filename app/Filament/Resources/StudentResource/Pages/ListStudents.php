<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Exports\StudentsExport;
use App\Filament\Resources\StudentResource;
use App\Imports\StudentsImport;
use App\Models\Student;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Tambah Siswa')),

            // SIS-05 / API 4.5 — GET /students/export.
            Actions\Action::make('export')
                ->label(__('Export Excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('export', Student::class))
                ->action(function () {
                    $export = new StudentsExport($this->getFilteredTableQuery());

                    return Excel::download($export, $this->exportFileName());
                }),

            // API 4.5 — POST /students/import.
            Actions\Action::make('import')
                ->label(__('Import Excel'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('import', Student::class))
                ->modalDescription(__('Kolom yang dibaca: nis, nisn, nama_lengkap, jenis_kelamin, tempat_lahir, tanggal_lahir, agama, alamat, nama_orang_tua, hp_orang_tua, email_orang_tua, tahun_masuk, status.'))
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label(__('Berkas Excel (.xlsx)'))
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(5120)
                        ->disk('local')
                        ->directory('imports'),
                ])
                ->action(fn (array $data) => $this->runImport($data['file'])),
        ];
    }

    /**
     * SIS-05 poin 2: siswa_[kode_sekolah]_[tanggal].xlsx.
     */
    protected function exportFileName(): string
    {
        $code = Auth::user()?->school?->code ?? 'SEMUA';

        return 'siswa_'.$code.'_'.now()->format('Y-m-d').'.xlsx';
    }

    /**
     * Import massal; mengembalikan jumlah sukses beserta daftar error per baris.
     *
     * Berkas unggahan hanya perantara, jadi penghapusannya diletakkan di
     * `finally`: berkas yang rusak membuat Excel::import melempar exception,
     * dan Super Admin yang tidak terikat cabang keluar lebih awal — keduanya
     * dulu meninggalkan berkas di storage/app/imports selamanya. Exception-nya
     * sendiri sengaja tidak ditangkap; penanganan galat di luar per-baris tetap
     * milik Filament, seperti sebelumnya.
     */
    protected function runImport(string $path): void
    {
        try {
            $schoolId = Auth::user()?->school_id;

            if ($schoolId === null) {
                Notification::make()
                    ->title(__('Pilih cabang terlebih dahulu'))
                    ->body(__('Super Admin tidak terikat pada satu cabang, sehingga import massal harus dijalankan oleh Admin Sekolah.'))
                    ->danger()
                    ->send();

                return;
            }

            $import = new StudentsImport($schoolId);

            Excel::import($import, Storage::disk('local')->path($path));

            $notification = Notification::make()
                ->title("{$import->imported} siswa berhasil diimport");

            if ($import->errors !== []) {
                $notification
                    ->body(implode("\n", array_slice($import->errors, 0, 10))
                        .(count($import->errors) > 10 ? "\n… dan ".(count($import->errors) - 10).' baris lain.' : ''))
                    ->warning()
                    ->persistent();
            } else {
                $notification->success();
            }

            $notification->send();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }
}
