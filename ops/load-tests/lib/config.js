/*
 * Konfigurasi bersama seluruh skenario k6.
 *
 * Dijalankan k6 (runtime goja), bukan Node. Tidak ada `require`, tidak ada
 * `npm install`, dan tidak ada dependensi berbayar.
 *
 * Tidak ada satu pun kredensial di berkas ini. Semuanya datang dari environment
 * saat dijalankan; berkas ini hanya menyebut nama variabelnya.
 */

/** Host yang TIDAK PERNAH boleh menerima skenario tulis. */
const PRODUCTION_HOSTS = [
  'apps.smartsukses.sch.id',
  'smartsukses.sch.id',
];

/**
 * Tahapan beban. Naik bertahap — 200 VU tidak pernah dijalankan lebih dulu.
 *
 * smoke    : membuktikan skripnya benar, bukan membebani apa pun.
 * baseline : bentuk kurva pada beban wajar.
 * target   : angka roadmap, 200 pengguna bersamaan.
 */
export const STAGES = {
  smoke: { vus: 5, duration: '30s' },
  baseline: { vus: 50, duration: '3m' },
  target: { vus: 200, duration: '5m' },
};

export function baseUrl() {
  const url = __ENV.BASE_URL;

  if (!url) {
    throw new Error(
      'BASE_URL wajib diisi. Contoh: BASE_URL=http://127.0.0.1:8000 k6 run ops/load-tests/01-landing.js'
    );
  }

  return url.replace(/\/+$/, '');
}

export function hostOf(url) {
  const m = /^https?:\/\/([^/:]+)/i.exec(url);

  return m ? m[1].toLowerCase() : '';
}

export function isProductionHost(url) {
  const host = hostOf(url);

  return PRODUCTION_HOSTS.some((p) => host === p || host.endsWith('.' + p));
}

/**
 * Pagar untuk skenario yang MENULIS.
 *
 * Dua lapis, dan yang kedua tidak dapat dimatikan lewat environment:
 *
 * 1. `LOAD_TEST_ALLOW_WRITES=true` harus disetel sadar.
 * 2. Host produksi ditolak mutlak. Tidak ada variabel environment yang dapat
 *    membukanya — mengubahnya menuntut menyunting `PRODUCTION_HOSTS` di atas,
 *    yaitu perubahan kode yang terlihat di review, bukan satu baris env yang
 *    dapat tersetel tidak sengaja di terminal seseorang.
 *
 * Beban tulis CBT membuat percobaan ujian dan menyimpan jawaban. Menjalankannya
 * terhadap data sungguhan berarti merusak ujian siswa sungguhan.
 */
export function guardWrites(url) {
  if (__ENV.LOAD_TEST_ALLOW_WRITES !== 'true') {
    throw new Error(
      'Skenario tulis mati secara bawaan. Setel LOAD_TEST_ALLOW_WRITES=true ' +
        'hanya pada staging dengan akun dan ujian fiksur khusus uji.'
    );
  }

  if (isProductionHost(url)) {
    throw new Error(
      'DITOLAK: ' +
        hostOf(url) +
        ' adalah host produksi. Skenario tulis tidak boleh diarahkan ke sana ' +
        'dalam keadaan apa pun. Pakai staging dengan fiksur khusus uji.'
    );
  }
}

/**
 * Opsi k6 untuk sebuah skenario.
 *
 * Ambang batasnya dari roadmap, dan tidak dilonggarkan supaya keluarannya
 * hijau. Mesin pengembang yang gagal memenuhinya bukan berarti produksi gagal —
 * itulah sebabnya setiap eksekusi lokal ditandai LOCAL dan tidak pernah dihitung
 * sebagai pemenuhan syarat 200 pengguna.
 */
export function optionsFor(kind) {
  const stage = STAGES[__ENV.STAGE || 'smoke'];

  if (!stage) {
    throw new Error('STAGE tidak dikenal. Pilih: smoke | baseline | target.');
  }

  const thresholds = {
    // Tidak ada permintaan yang boleh gagal lebih dari 1%.
    http_req_failed: ['rate<0.01'],
  };

  if (kind === 'api') {
    // Roadmap: API p95 < 500 ms.
    thresholds.http_req_duration = ['p(95)<500'];
  } else {
    // Roadmap: halaman utama < 3 detik.
    thresholds.http_req_duration = ['p(95)<3000'];
  }

  return {
    vus: Number(__ENV.VUS || stage.vus),
    duration: __ENV.DURATION || stage.duration,
    thresholds,
    // Ringkasan lengkap ditulis ke berkas agar dapat dilampirkan ke laporan QA.
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  };
}

/**
 * Kredensial akun fiksur untuk login API.
 *
 * Polanya diatur environment supaya tidak ada nama akun maupun kata sandi yang
 * tertulis di repo. `{vu}` diganti nomor VU bila dipakai per-VU.
 */
export function fixtureUser(vu) {
  const pattern = __ENV.STUDENT_EMAIL_PATTERN;
  const password = __ENV.STUDENT_PASSWORD;

  if (!pattern || !password) {
    throw new Error(
      'STUDENT_EMAIL_PATTERN dan STUDENT_PASSWORD wajib diisi untuk skenario ' +
        'terautentikasi. Contoh pola: "loadtest+{vu}@example.test" — {vu} ' +
        'diganti nomor VU sehingga tiap VU memakai akun fiksur sendiri.'
    );
  }

  return {
    email: pattern.replace('{vu}', String(vu)),
    password: password,
  };
}

/**
 * Token Sanctum yang sudah jadi, bila operator lebih suka menyuplainya sendiri
 * daripada menukar kredensial saat `setup()`.
 */
export function apiToken() {
  const token = __ENV.API_TOKEN;

  if (!token) {
    throw new Error('API_TOKEN wajib diisi untuk skenario ini.');
  }

  return token;
}
