<?php

/*
 * Pesan validasi Bahasa Indonesia.
 *
 * Sebelum Batch S9.3 direktori `lang/` tidak ada sama sekali, sehingga pesan
 * validasi selalu tampil dalam bahasa Inggris — termasuk bagi pengguna
 * Indonesia, yang merupakan bawaan aplikasi ini. Itu cacat yang sudah ada sejak
 * lama dan baru terlihat ketika bilingual dikerjakan (butir 380).
 *
 * Yang diterjemahkan aturan yang benar-benar dipakai project ini. Aturan yang
 * tidak pernah dipakai sengaja tidak disalin: berkas panjang yang separuhnya
 * mati justru menyembunyikan mana yang berlaku.
 */

return [
    'accepted' => 'Kolom :attribute harus disetujui.',
    'after' => 'Kolom :attribute harus berisi tanggal setelah :date.',
    'after_or_equal' => 'Kolom :attribute harus berisi tanggal setelah atau sama dengan :date.',
    'alpha' => 'Kolom :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Kolom :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Kolom :attribute hanya boleh berisi huruf dan angka.',
    'array' => 'Kolom :attribute harus berupa larik.',
    'before' => 'Kolom :attribute harus berisi tanggal sebelum :date.',
    'before_or_equal' => 'Kolom :attribute harus berisi tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => 'Kolom :attribute harus memiliki antara :min sampai :max item.',
        'file' => 'Kolom :attribute harus berukuran antara :min sampai :max kilobita.',
        'numeric' => 'Kolom :attribute harus bernilai antara :min sampai :max.',
        'string' => 'Kolom :attribute harus berisi antara :min sampai :max karakter.',
    ],
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'confirmed' => 'Konfirmasi kolom :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'date' => 'Kolom :attribute bukan tanggal yang sah.',
    'date_format' => 'Kolom :attribute tidak cocok dengan format :format.',
    'different' => 'Kolom :attribute dan :other harus berbeda.',
    'digits' => 'Kolom :attribute harus terdiri dari :digits angka.',
    'digits_between' => 'Kolom :attribute harus terdiri dari :min sampai :max angka.',
    'email' => 'Kolom :attribute harus berupa alamat surel yang sah.',
    'ends_with' => 'Kolom :attribute harus diakhiri salah satu dari: :values.',
    'exists' => 'Pilihan :attribute tidak sah.',
    'file' => 'Kolom :attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute wajib diisi.',
    'gt' => [
        'numeric' => 'Kolom :attribute harus lebih besar dari :value.',
        'string' => 'Kolom :attribute harus lebih panjang dari :value karakter.',
    ],
    'gte' => [
        'numeric' => 'Kolom :attribute harus lebih besar atau sama dengan :value.',
    ],
    'image' => 'Kolom :attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak sah.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'lt' => [
        'numeric' => 'Kolom :attribute harus lebih kecil dari :value.',
    ],
    'lte' => [
        'numeric' => 'Kolom :attribute harus lebih kecil atau sama dengan :value.',
    ],
    'max' => [
        'array' => 'Kolom :attribute tidak boleh lebih dari :max item.',
        'file' => 'Kolom :attribute tidak boleh lebih dari :max kilobita.',
        'numeric' => 'Kolom :attribute tidak boleh lebih dari :max.',
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => 'Kolom :attribute harus berupa berkas bertipe: :values.',
    'mimetypes' => 'Kolom :attribute harus berupa berkas bertipe: :values.',
    'min' => [
        'array' => 'Kolom :attribute harus memiliki minimal :min item.',
        'file' => 'Kolom :attribute harus berukuran minimal :min kilobita.',
        'numeric' => 'Kolom :attribute harus bernilai minimal :min.',
        'string' => 'Kolom :attribute harus berisi minimal :min karakter.',
    ],
    'not_in' => 'Pilihan :attribute tidak sah.',
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'password' => [
        'letters' => 'Kolom :attribute harus memuat sedikitnya satu huruf.',
        'mixed' => 'Kolom :attribute harus memuat huruf besar dan huruf kecil.',
        'numbers' => 'Kolom :attribute harus memuat sedikitnya satu angka.',
        'symbols' => 'Kolom :attribute harus memuat sedikitnya satu simbol.',
        'uncompromised' => 'Kolom :attribute pernah muncul pada kebocoran data. Pilih yang lain.',
    ],
    'present' => 'Kolom :attribute harus ada.',
    'prohibited' => 'Kolom :attribute dilarang diisi.',
    'regex' => 'Format kolom :attribute tidak sah.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_if' => 'Kolom :attribute wajib diisi bila :other bernilai :value.',
    'required_with' => 'Kolom :attribute wajib diisi bila terdapat :values.',
    'required_without' => 'Kolom :attribute wajib diisi bila tidak terdapat :values.',
    'same' => 'Kolom :attribute dan :other harus sama.',
    'size' => [
        'array' => 'Kolom :attribute harus berisi :size item.',
        'file' => 'Kolom :attribute harus berukuran :size kilobita.',
        'numeric' => 'Kolom :attribute harus bernilai :size.',
        'string' => 'Kolom :attribute harus berisi :size karakter.',
    ],
    'starts_with' => 'Kolom :attribute harus diawali salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'unique' => 'Kolom :attribute sudah digunakan.',
    'uploaded' => 'Kolom :attribute gagal diunggah.',
    'url' => 'Format kolom :attribute tidak sah.',

    'custom' => [],

    /*
     * Nama kolom yang dipakai di dalam pesan. Yang disebut hanya kolom yang
     * benar-benar tampil di form pengguna; sisanya dijadikan kata biasa oleh
     * Laravel dan itu sudah memadai.
     */
    'attributes' => [
        'email' => 'surel',
        'password' => 'kata sandi',
        'name' => 'nama',
        'full_name' => 'nama lengkap',
        'phone' => 'nomor telepon',
        'nis' => 'NIS',
        'nisn' => 'NISN',
        'score' => 'nilai',
        'title' => 'judul',
        'message' => 'pesan',
        'amount' => 'nominal',
        'period' => 'periode',
        'due_date' => 'jatuh tempo',
        'question_text' => 'pertanyaan',
        'option_text' => 'pilihan jawaban',
        'duration_minutes' => 'durasi',
        'available_from' => 'waktu buka',
        'available_until' => 'waktu tutup',
        'locale' => 'bahasa',
    ],
];
