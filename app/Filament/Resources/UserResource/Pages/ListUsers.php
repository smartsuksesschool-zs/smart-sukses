<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Exports\AccountCredentialsExport;
use App\Filament\Resources\UserResource;
use App\Imports\UserAccountsImport;
use App\Models\School;
use App\Models\User;
use App\Services\Admin\AccountProvisioner;
use App\Services\Admin\AccountProvisioningConflict;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Tambah Pengguna')),
            $this->importAction(),
        ];
    }

    /**
     * PORTAL-04 AC-1 — import massal akun guru dan siswa.
     *
     * Satu aksi, dua fase. Berkasnya diperiksa seluruhnya lebih dulu; satu galat
     * saja membuat impor berhenti **tanpa membuat satu akun pun**. Tidak ada
     * layar pratinjau tersendiri: yang dijanjikan kepada admin bukan pratinjau
     * melainkan jaminan bahwa tidak ada yang separuh jadi, dan jaminan itu
     * dipenuhi oleh fase validasinya (butir 594).
     */
    protected function importAction(): Actions\Action
    {
        return Actions\Action::make('importAccounts')
            ->label(__('Import Akun'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn () => Auth::user()?->can('import', User::class))
            ->modalHeading(__('Import Akun Guru & Siswa'))
            ->modalDescription(__('Unduh templatenya lebih dulu, isi tanpa mengubah nama lembar dan kolom, lalu unggah kembali di sini. Satu kesalahan membatalkan seluruh impor.'))
            ->modalSubmitActionLabel(__('Periksa & Buat Akun'))
            ->form([
                Forms\Components\Placeholder::make('template')
                    ->label(__('Langkah 1 — Unduh template'))
                    ->content(fn (): HtmlString => new HtmlString(Blade::render(
                        '<x-filament::button tag="a" href="{{ $url }}" icon="heroicon-o-arrow-down-tray" color="gray" size="sm">'
                        .'{{ $label }}</x-filament::button>'
                        .'<p class="fi-fo-field-wrp-hint mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $hint }}</p>',
                        [
                            'url' => route('filament.admin.users.import-template'),
                            'label' => __('Download Template Excel'),
                            'hint' => __('Berisi lembar "Akun Guru", "Akun Siswa", dan "Petunjuk". Tidak ada kolom kata sandi.'),
                        ],
                    ))),

                /*
                 * Cabang tujuan, dan hanya Super Admin yang memilihnya.
                 *
                 * Tidak ada kolom cabang di dalam berkas. Dengan begitu tidak ada
                 * baris Excel yang dapat menyeberang tenant — bukan karena
                 * ditolak, melainkan karena tidak ada tempat untuk menuliskannya.
                 *
                 * Importer siswa yang sudah ada justru **menolak** Super Admin
                 * dengan alasan ia tidak terikat satu cabang. Di sini itu tidak
                 * diikuti: matriks izin `06-API.md:2016` memberi
                 * `POST /users/import` kepada Super Admin, sehingga menolaknya
                 * berarti melanggar matriks. Yang benar adalah menanyakan
                 * cabangnya.
                 */
                Forms\Components\Select::make('school_id')
                    ->label(__('Langkah 2 — Cabang tujuan'))
                    ->options(fn (): array => School::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->visible(fn () => Auth::user()?->isSuperAdmin())
                    ->helperText(__('Seluruh akun pada berkas ini dibuat di cabang tersebut.')),

                Forms\Components\FileUpload::make('file')
                    ->label(__('Langkah 3 — Berkas Excel (.xlsx)'))
                    ->required()
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('imports')
                    // Berkas ini memuat surel calon pengguna; lintasannya tidak
                    // boleh dapat diambil tanpa tanda tangan (butir 581).
                    ->visibility('private'),
            ])
            ->action(fn (array $data) => $this->runImport($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function runImport(array $data): ?BinaryFileResponse
    {
        $path = (string) $data['file'];
        $schoolId = $this->resolveSchoolId($data['school_id'] ?? null);

        if ($schoolId === null) {
            Storage::disk('local')->delete($path);

            Notification::make()
                ->title(__('Cabang tujuan belum ditentukan'))
                ->body(__('Akun hasil impor harus memiliki cabang. Pilih cabang tujuan lebih dahulu.'))
                ->danger()
                ->send();

            return null;
        }

        try {
            $import = new UserAccountsImport($schoolId);

            try {
                Excel::import($import, Storage::disk('local')->path($path));
            } catch (Throwable $e) {
                /*
                 * Berkas yang tidak dapat dibaca sama sekali — bukan .xlsx yang
                 * sah, atau rusak. Pesannya tidak memuat isi berkas: unggahan
                 * dapat memuat surel, dan pesan galat berakhir di log.
                 */
                Notification::make()
                    ->title(__('Berkas tidak dapat dibaca'))
                    ->body(__('Pastikan berkasnya .xlsx yang dibuat dari template, tidak rusak, dan tidak terproteksi.'))
                    ->danger()
                    ->send();

                return null;
            }

            if (! $import->matchedAnySheet()) {
                Notification::make()
                    ->title(__('Lembar yang dikenali tidak ditemukan'))
                    ->body(__('Berkas harus memuat lembar ":teacher" atau ":student". Gunakan template agar nama lembarnya tepat.', [
                        'teacher' => UserAccountsImport::TEACHER_SHEET,
                        'student' => UserAccountsImport::STUDENT_SHEET,
                    ]))
                    ->danger()
                    ->send();

                return null;
            }

            if ($import->hasErrors()) {
                $this->errorNotification($import->errors)->send();

                return null;
            }

            if ($import->planned === []) {
                Notification::make()
                    ->title(__('Tidak ada baris yang dapat diimpor'))
                    ->body(__('Berkasnya terbaca, tetapi kedua lembarnya kosong.'))
                    ->warning()
                    ->send();

                return null;
            }

            try {
                $credentials = app(AccountProvisioner::class)->provision($schoolId, $import->planned);
            } catch (AccountProvisioningConflict $e) {
                Notification::make()
                    ->title(__('Impor dibatalkan'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return null;
            }

            $this->successNotification($import, count($credentials))->send();

            return Excel::download(
                new AccountCredentialsExport($credentials),
                AccountCredentialsExport::filename(),
            );
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Cabang yang berlaku: pilihan Super Admin, atau cabang akun yang mengimpor.
     *
     * Apa pun yang dikirim klien diabaikan untuk peran School Level — field-nya
     * tidak tampil bagi mereka, dan cabangnya tidak boleh dapat dititipkan.
     */
    protected function resolveSchoolId(mixed $formValue): ?int
    {
        $user = Auth::user();

        if ($user?->isSuperAdmin()) {
            return filled($formValue) ? (int) $formValue : null;
        }

        return $user?->school_id;
    }

    /**
     * @param  array<int, string>  $errors
     */
    protected function errorNotification(array $errors): Notification
    {
        $shown = array_slice($errors, 0, 10);
        $rest = count($errors) - count($shown);

        $body = implode("\n", $shown);

        if ($rest > 0) {
            $body .= "\n".__('… dan :count kesalahan lain.', ['count' => $rest]);
        }

        return Notification::make()
            ->title(__('Tidak ada akun yang dibuat — :count kesalahan ditemukan', ['count' => count($errors)]))
            ->body($body)
            ->danger()
            ->persistent();
    }

    protected function successNotification(UserAccountsImport $import, int $created): Notification
    {
        $linked = count(array_filter(
            $import->planned,
            fn (array $row): bool => $row['student_id'] !== null,
        ));

        return Notification::make()
            ->title(__(':count akun dibuat', ['count' => $created]))
            ->body(__('Tertaut ke data siswa: :linked. Ditolak: 0. Berkas kata sandi sementara sedang diunduh — simpan sekali itu saja, sandinya tidak dapat ditampilkan kembali.', [
                'linked' => $linked,
            ]))
            ->success()
            ->persistent();
    }
}
