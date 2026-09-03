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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
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
                ->modalDescription(__('Unduh templatenya lebih dulu, isi tanpa mengubah nama kolom, lalu unggah kembali di sini.'))
                ->form([
                    // Umpan balik pemilik: admin sebelumnya harus menebak format
                    // berkasnya, dan tebakan yang salah baru ketahuan setelah
                    // unggahan menghasilkan nol siswa. Tautannya diletakkan di
                    // atas kolom unggah, pada urutan pemakaiannya (butir 496).
                    Forms\Components\Placeholder::make('template')
                        ->label(__('Langkah 1 — Unduh template'))
                        ->content(fn (): HtmlString => new HtmlString(Blade::render(
                            '<x-filament::button tag="a" href="{{ $url }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">'
                            .'{{ $label }}</x-filament::button>'
                            .'<p class="fi-fo-field-wrp-hint mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $hint }}</p>',
                            [
                                'url' => route('filament.admin.students.import-template'),
                                'label' => __('Download Template Excel'),
                                'hint' => __('Berisi lembar "Data Siswa" untuk diisi dan lembar "Petunjuk" berisi keterangan setiap kolom.'),
                            ],
                        ))),

                    Forms\Components\FileUpload::make('file')
                        ->label(__('Langkah 2 — Berkas Excel (.xlsx)'))
                        ->helperText(__('Kolom yang dibaca: :columns.', ['columns' => implode(', ', array_keys(StudentsImport::COLUMNS))]))
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
    /**
     * Hasil import yang membedakan sebabnya.
     *
     * "0 siswa berhasil diimport" adalah jawaban yang benar untuk tiga keadaan
     * yang sangat berbeda: berkasnya kosong, judul kolomnya tidak dikenali, atau
     * setiap barisnya ditolak. Tanpa dibedakan, admin tidak punya langkah
     * berikutnya — dan yang paling sering terjadi, judul kolom yang salah,
     * justru yang paling mudah diperbaiki (butir 500).
     *
     * Urutannya penting dan berasal dari cacat nyata: berkas contoh resmi
     * berlembar dua, dan penilaian per-berkas sempat membuat lembar "Petunjuk"
     * menimpa kesimpulan lembar "Data Siswa" yang sudah benar — berkas yang
     * barisnya masuk tetap dilaporkan sebagai judul kolom yang tidak dikenali
     * (butir 504).
     */
    protected function importNotification(StudentsImport $import): Notification
    {
        if ($import->headerMismatch()) {
            return Notification::make()
                ->title(__('Judul kolom tidak dikenali'))
                ->body(__('Kolom wajib yang tidak ditemukan: :columns. Unduh template Excel dan jangan mengubah nama kolom di baris pertama.', [
                    'columns' => implode(', ', $import->missingColumns()),
                ]))
                ->danger()
                ->persistent();
        }

        if (! $import->sawRows) {
            return Notification::make()
                ->title(__('Berkas tidak berisi baris data'))
                ->body(__('Lembar pertama hanya berisi judul kolom.'))
                ->warning();
        }

        $notification = Notification::make()
            ->title(__(':count siswa berhasil diimport', ['count' => $import->imported]));

        if ($import->errors === []) {
            return $notification->success();
        }

        return $notification
            ->body(__(':rejected baris ditolak.', ['rejected' => $import->rejected])."\n"
                .implode("\n", array_slice($import->errors, 0, 10))
                .(count($import->errors) > 10 ? "\n".__('… dan :count baris lain.', ['count' => count($import->errors) - 10]) : ''))
            ->warning()
            ->persistent();
    }

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

            $this->importNotification($import)->send();
        } finally {
            Storage::disk('local')->delete($path);
        }
    }
}
