<?php

namespace App\Console\Commands;

use App\Models\PpdbRegistration;
use App\Support\PpdbDocument;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Pemindahan satu kali berkas PPDB dari disk `public` ke disk privat.
 *
 * Hanya jalur yang benar-benar dirujuk `ppdb_registrations.documents` yang
 * disentuh. Perintah ini tidak pernah memindai direktori, tidak pernah
 * menghapus berkas yang tidak dirujuk, dan tidak mengubah satu baris pun di
 * basis data: jalur yang tersimpan tetap sama, yang berpindah hanya berkasnya
 * (butir 411).
 *
 * Tanpa `--apply` ia **hanya melaporkan** dan tidak menulis apa pun. Pagar yang
 * sama dengan `ops/restore-database.sh`: yang merusak harus diketik sadar,
 * bukan menjadi bawaan.
 *
 * Perintah ini berjalan tanpa sesi, sehingga SchoolScope tidak memasang pagar
 * tenant apa pun (butir 407). Itu memang yang dibutuhkan di sini — pemindahan
 * berlaku untuk seluruh cabang — jadi lingkupnya ditulis eksplisit dengan
 * `withoutGlobalScopes()`, bukan diserahkan kepada kebetulan.
 */
class PrivatizePpdbDocuments extends Command
{
    protected $signature = 'ppdb:privatize-documents
        {--apply : Benar-benar memindahkan berkas; tanpa ini tidak ada yang ditulis}
        {--dry-run : Hanya melaporkan (perilaku bawaan; tidak dapat digabung dengan --apply)}';

    protected $description = 'Memindahkan berkas pendukung PPDB dari disk publik ke penyimpanan privat';

    /** @var array<string, int> */
    protected array $counts = [
        'found' => 0,
        'migrated' => 0,
        'already-private' => 0,
        'missing' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('dry-run')) {
            $this->error('--apply dan --dry-run saling meniadakan. Pilih salah satu.');

            return self::FAILURE;
        }

        $this->line($apply
            ? '<fg=yellow>MODE TERAP</> — berkas akan disalin lalu salinan publiknya dihapus.'
            : '<fg=green>MODE SIMULASI</> — tidak ada berkas yang disalin, dipindah, atau dihapus. Tambahkan --apply untuk benar-benar memindahkan.');
        $this->newLine();

        PpdbRegistration::query()
            ->withoutGlobalScopes()
            ->whereNotNull('documents')
            ->orderBy('id')
            ->chunkById(100, function ($registrations) use ($apply): void {
                foreach ($registrations as $registration) {
                    $this->handleRegistration($registration, $apply);
                }
            });

        $this->newLine();
        $this->table(
            ['Hasil', 'Jumlah'],
            collect($this->counts)->map(fn (int $n, string $k): array => [$k, $n])->values()->all(),
        );

        if ($this->counts['failed'] > 0) {
            return self::FAILURE;
        }

        if (! $apply && $this->counts['found'] > 0) {
            $this->newLine();
            $this->comment('Tidak ada yang berubah. Jalankan ulang dengan --apply setelah laporan di atas diperiksa.');
        }

        return self::SUCCESS;
    }

    protected function handleRegistration(PpdbRegistration $registration, bool $apply): void
    {
        $documents = $registration->documents;

        if (! is_array($documents) || $documents === []) {
            return;
        }

        foreach ($documents as $key => $raw) {
            $this->counts['found']++;

            $label = "#{$registration->getKey()} [{$key}]";
            $path = PpdbDocument::sanitise($raw);

            // Jalur yang tidak lolos pagar tidak pernah disentuh — tidak
            // dibaca, tidak disalin, tidak dihapus.
            if ($path === null) {
                $this->counts['skipped']++;
                $this->warn("{$label} DILEWATI — jalur tidak valid dan tidak disentuh.");

                continue;
            }

            $this->migrateOne($label, $path, $apply);
        }
    }

    protected function migrateOne(string $label, string $path, bool $apply): void
    {
        $private = Storage::disk(PpdbDocument::DISK);
        $public = Storage::disk(PpdbDocument::LEGACY_DISK);

        $onPrivate = $private->exists($path);
        $onPublic = $public->exists($path);

        if (! $onPrivate && ! $onPublic) {
            // Berkas tercatat tetapi tidak ada di mana pun. Dilaporkan, bukan
            // dianggap selesai: "sudah dipindah" dan "hilang" bukan hal yang
            // sama, dan menyamakannya menyembunyikan kehilangan data.
            $this->counts['missing']++;
            $this->error("{$label} HILANG — tercatat tetapi tidak ada di kedua disk.");

            return;
        }

        if ($onPrivate && ! $onPublic) {
            $this->counts['already-private']++;
            $this->line("{$label} sudah privat.");

            return;
        }

        if ($onPrivate && $onPublic) {
            // Jejak jalan yang terputus di tengah, atau berkas berbeda dengan
            // jalur yang sama. Ukurannya yang membedakan: sama berarti salinan
            // privatnya sudah lengkap dan tinggal salinan publiknya yang perlu
            // hilang; berbeda berarti tidak ada yang boleh ditimpa.
            if ($private->size($path) !== $public->size($path)) {
                $this->counts['skipped']++;
                $this->warn("{$label} DILEWATI — sudah ada berkas privat berbeda di jalur yang sama; tidak ditimpa.");

                return;
            }

            if (! $apply) {
                $this->counts['migrated']++;
                $this->line("{$label} akan dirapikan (salinan publik dihapus).");

                return;
            }

            $public->delete($path);
            $this->counts['migrated']++;
            $this->info("{$label} dirapikan — salinan publik dihapus.");

            return;
        }

        if (! $apply) {
            $this->counts['migrated']++;
            $this->line("{$label} akan dipindah ke penyimpanan privat.");

            return;
        }

        $this->copyThenDelete($label, $path, $private, $public);
    }

    /**
     * Salin dulu, buktikan salinannya utuh, baru hapus yang lama.
     *
     * Urutannya yang penting: berkas yang gagal disalin tetap punya aslinya,
     * dan tidak ada penghapusan yang terjadi atas dasar asumsi bahwa salinannya
     * berhasil.
     */
    protected function copyThenDelete(
        string $label,
        string $path,
        Filesystem $private,
        Filesystem $public,
    ): void {
        $expected = $public->size($path);

        $stream = $public->readStream($path);

        if ($stream === null || $stream === false) {
            $this->counts['failed']++;
            $this->error("{$label} GAGAL — berkas publiknya tidak dapat dibaca.");

            return;
        }

        $private->writeStream($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $private->exists($path) || $private->size($path) !== $expected) {
            $this->counts['failed']++;
            $this->error("{$label} GAGAL — salinan privatnya tidak utuh; berkas publik TIDAK dihapus.");

            return;
        }

        $public->delete($path);

        $this->counts['migrated']++;
        $this->info("{$label} dipindah ({$expected} bita).");
    }
}
