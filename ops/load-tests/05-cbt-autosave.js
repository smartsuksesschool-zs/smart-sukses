/*
 * Skenario G — autosave jawaban CBT. **SKENARIO TULIS.**
 *
 * Ini satu-satunya skrip di direktori ini yang mengubah data, dan bentuk beban
 * paling berat yang dimiliki sistem: setiap siswa yang memilih jawaban memicu
 * satu penulisan, dan satu kelas yang mengerjakan bersamaan berarti puluhan
 * penulisan per detik ke tabel yang sama.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * PAGAR
 * ────────────────────────────────────────────────────────────────────────────
 *
 * 1. Mati secara bawaan. `LOAD_TEST_ALLOW_WRITES=true` harus disetel sadar.
 * 2. Host produksi ditolak mutlak, tanpa jalan keluar lewat environment.
 * 3. Menuntut akun **dan** ujian fiksur khusus uji.
 *
 * Menjalankan ini terhadap ujian sungguhan akan menulis jawaban atas nama siswa
 * sungguhan pada ujian yang sedang berlangsung. Tidak ada cara membatalkannya.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * SATU VU = SATU SISWA
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Modelnya satu percobaan per siswa. Kalau seluruh VU memakai sesi siswa yang
 * sama, ratusan permintaan akan berebut satu baris `exam_attempts` — yang
 * terukur menjadi pertengkaran kunci baris di MySQL, bukan kapasitas aplikasi,
 * dan angkanya akan terlihat jauh lebih buruk daripada kenyataannya. Karena itu
 * `STUDENT_SESSION_COOKIE_PATTERN` memuat `{vu}`.
 *
 *   BASE_URL=https://staging.example EXAM_ID=12 \
 *   STUDENT_SESSION_COOKIE_PATTERN='...{vu}...' \
 *   LOAD_TEST_ALLOW_WRITES=true STAGE=smoke k6 run ops/load-tests/05-cbt-autosave.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { baseUrl, guardWrites, optionsFor } from './lib/config.js';
import { sessionHeaders } from './lib/auth.js';
import { callMethod, componentFrom, csrfFrom } from './lib/livewire.js';

export const options = optionsFor('web');

const BASE = baseUrl();
const EXAM_ID = __ENV.EXAM_ID;

// Dievaluasi saat init, sehingga skrip berhenti sebelum satu permintaan pun
// terkirim bila pagarnya tidak dipenuhi.
guardWrites(BASE);

if (!EXAM_ID) {
  throw new Error('EXAM_ID wajib diisi: id ujian fiksur khusus uji di staging.');
}

export default function () {
  const headers = sessionHeaders(__VU).headers;

  // 1. Muat halaman pengerjaan. Snapshot Livewire ditandatangani server dan
  //    tidak dapat dikarang, jadi ia harus diambil dari respons sungguhan.
  const page = http.get(BASE + '/siswa/ujian/' + EXAM_ID, {
    headers: headers,
    tags: { name: 'GET /siswa/ujian/{id}' },
  });

  const opened = check(page, {
    'halaman pengerjaan 200': (r) => r.status === 200,
    'sesi masih hidup': (r) => r.body.indexOf('name="password"') === -1,
  });

  if (!opened) {
    return;
  }

  const component = componentFrom(page.body);
  const csrf = csrfFrom(page.body);

  if (!component || !csrf) {
    check(null, { 'snapshot Livewire ditemukan': () => false });

    return;
  }

  // 2. Ambil pasangan (soal, pilihan) pertama dari markup yang baru dirender.
  //    Tidak ada id yang ditebak: semuanya berasal dari halaman ini.
  const choose = /wire:click="choose\((\d+), (\d+)\)"/.exec(page.body);

  if (!choose) {
    check(null, { 'ada pilihan jawaban untuk ditekan': () => false });

    return;
  }

  sleep(2);

  // 3. Simpan jawaban — inilah penulisan yang sedang diukur.
  const res = callMethod(
    BASE,
    component,
    csrf,
    'choose',
    [Number(choose[1]), Number(choose[2])],
    headers
  );

  check(res, {
    'autosave diterima': (r) => r.status === 200,
    // Livewire menjawab 200 dengan badan galat ketika komponennya menolak.
    'autosave tidak mengembalikan galat': (r) => r.body.indexOf('"errors"') === -1,
  });

  sleep(3);
}
