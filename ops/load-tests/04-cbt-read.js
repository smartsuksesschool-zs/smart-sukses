/*
 * Skenario E dan F — daftar ujian CBT dan halaman pengerjaan, baca saja.
 *
 * Ini bentuk beban CBT yang sesungguhnya paling menentukan. Ujian dibuka pada
 * jam yang sama untuk satu angkatan, jadi puluhan hingga ratusan siswa memuat
 * `/siswa/ujian` lalu `/siswa/ujian/{id}` dalam rentang beberapa detik.
 *
 * **Membaca saja.** Membuka halaman pengerjaan memang membuat/melanjutkan
 * `exam_attempts` di sisi aplikasi, sehingga skrip ini tetap menuntut fiksur
 * khusus uji — lihat docs/load-testing.md. Ia tidak pernah menyimpan jawaban;
 * itu tugas 05-cbt-autosave.js.
 *
 *   BASE_URL=... EXAM_ID=12 STUDENT_SESSION_COOKIE_PATTERN='...' \
 *   STAGE=baseline k6 run ops/load-tests/04-cbt-read.js
 */

import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { baseUrl, optionsFor } from './lib/config.js';
import { sessionHeaders } from './lib/auth.js';

export const options = optionsFor('web');

const BASE = baseUrl();
const EXAM_ID = __ENV.EXAM_ID || '';

export default function () {
  const headers = sessionHeaders(__VU).headers;

  group('daftar ujian', function () {
    const res = http.get(BASE + '/siswa/ujian', {
      headers: headers,
      tags: { name: 'GET /siswa/ujian' },
    });

    check(res, {
      'daftar ujian 200': (r) => r.status === 200,
      // Sesi yang kedaluwarsa mengalihkan ke halaman masuk dan tetap 200.
      // Tanpa pemeriksaan ini, sesi mati akan terbaca sebagai hasil yang cepat.
      'sesi masih hidup': (r) => r.body.indexOf('name="password"') === -1,
    });
  });

  sleep(1);

  if (EXAM_ID) {
    group('halaman pengerjaan', function () {
      const res = http.get(BASE + '/siswa/ujian/' + EXAM_ID, {
        headers: headers,
        tags: { name: 'GET /siswa/ujian/{id}' },
      });

      check(res, {
        'halaman pengerjaan 200': (r) => r.status === 200,
        'komponen Livewire terender': (r) => r.body.indexOf('wire:snapshot') !== -1,
      });
    });

    sleep(2);
  }
}
