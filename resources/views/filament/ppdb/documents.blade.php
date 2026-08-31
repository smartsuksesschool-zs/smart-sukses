{{--
    Berkas pendukung PPDB.

    Sebelum batch ini entri ini hanya mencetak `basename()` sebagai teks: nama
    berkasnya terlihat, tetapi tidak ada satu pun cara membukanya dari panel.
    Satu-satunya jalan menuju isinya adalah URL publik `/storage/...` yang harus
    ditebak — persis keadaan yang membuat penyimpanan publiknya berbahaya.

    Sekarang setiap berkas menjadi tautan ke rute berwenang milik panel.
    Nama yang ditampilkan dibentuk dari nomor pendaftaran, bukan dari nama
    penyimpanannya, sehingga halaman ini tidak membocorkan jalur apa pun
    (butir 413).
--}}
@php
    $record = $getRecord();
    $documents = is_array($record->documents) ? $record->documents : [];
@endphp

@if ($documents === [])
    <div class="fi-in-placeholder text-sm text-gray-400 dark:text-gray-500">
        {{ __('Tidak ada berkas diunggah') }}
    </div>
@else
    <ul class="fi-in-text-item-label flex flex-col gap-1 text-sm">
        @foreach ($documents as $key => $path)
            <li>
                <a
                    href="{{ route('filament.admin.ppdb.document', [
                        'registration' => $record->getKey(),
                        'documentKey' => $key,
                    ]) }}"
                    class="fi-link text-primary-600 hover:underline dark:text-primary-400"
                >
                    {{ \App\Support\PpdbDocument::filenameFor($record, (int) $key, (string) $path) }}
                </a>
            </li>
        @endforeach
    </ul>
@endif
