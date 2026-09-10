<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Kesadaran proxy: skema mana yang dilihat Laravel di belakang TLS terminator.
 *
 * Mekanismenya sudah ada sejak butir 356-358; yang belum pernah ada adalah test
 * yang benar-benar mengambil URL yang dihasilkannya. Ketiadaan itu yang membuat
 * sebuah pemasangan dapat berjalan dengan TRUSTED_PROXIES kosong tanpa ada yang
 * menyadarinya sampai halaman tampil tanpa gaya (butir 583).
 *
 * **Batas cakupan.** Yang dijamin di sini hanya hop terakhir: Laravel menilai
 * metadata yang sudah sampai kepadanya. Pada platform yang punya proxy sendiri
 * di depan aplikasi — Caddy/FrankenPHP di Railway, misalnya — ada hop lebih
 * dulu yang juga harus memercayai edge platformnya, dan hop itu tidak dapat
 * dibuktikan dari PHPUnit sama sekali. Seluruh test di berkas ini dapat hijau
 * sementara deployment tetap menghasilkan URL http. Yang memverifikasinya
 * adalah smoke test terhadap staging sungguhan; lihat
 * docs/deployment/staging-uat.md.
 *
 * Keadaan statis TrustProxies dikendalikan eksplisit di setiap test dan
 * dibersihkan di tearDown: ia bertahan lintas test dalam satu proses, dan test
 * yang bergantung pada isi .env mesin penjalannya menguji mesin itu, bukan
 * kodenya.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Satu rute sementara yang melaporkan apa yang dilihat Laravel.
        // Dipasang di sini, bukan di routes/, supaya tidak ada permukaan baru
        // yang ikut terbawa ke produksi.
        Route::middleware('web')->get('/_uji-kesadaran-proxy', fn () => response()->json([
            'secure' => request()->isSecure(),
            'scheme' => request()->getScheme(),
            'host' => request()->getHost(),
            'root' => url('/'),
            'named' => route('login'),
            // Jalur yang sama persis dipakai Filament: Css, Js, dan
            // AlpineComponent seluruhnya memanggil asset() Laravel.
            'aset' => asset('css/filament/filament/app.css'),
        ]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    /** Tidak memercayai siapa pun — keadaan bawaan dan keadaan lokal. */
    protected function trustNobody(): void
    {
        TrustProxies::flushState();
        TrustProxies::withHeaders((int) config('trustedproxy.headers'));
    }

    /** Memercayai proxy mana pun — bentuk yang dipakai platform berbasis awan. */
    protected function trustAnyProxy(): void
    {
        TrustProxies::flushState();
        TrustProxies::at('*');
        TrustProxies::withHeaders((int) config('trustedproxy.headers'));
    }

    protected function lihat(array $headers = []): array
    {
        return $this->withHeaders($headers)->get('/_uji-kesadaran-proxy')->assertOk()->json();
    }

    // ------------------------------------------------------- tanpa proxy

    public function test_tanpa_proxy_tepercaya_header_yang_diteruskan_diabaikan(): void
    {
        /*
         * Header X-Forwarded-* dapat dikirim siapa pun yang dapat menjangkau
         * origin. Selama tidak ada proxy yang dipercaya, ia harus tidak
         * berpengaruh sama sekali — kalau tidak, siapa pun dapat menentukan
         * skema yang dipakai membangun tautan atur ulang kata sandi.
         */
        $this->trustNobody();

        $hasil = $this->lihat(['X-Forwarded-Proto' => 'https']);

        $this->assertFalse($hasil['secure']);
        $this->assertSame('http', $hasil['scheme']);
        $this->assertStringStartsWith('http://', $hasil['root']);

        /*
         * Dan inilah gejalanya di peramban: aset ikut http walaupun halamannya
         * https, sehingga diblokir sebagai konten campuran.
         */
        $this->assertStringStartsWith('http://', $hasil['aset']);
    }

    public function test_pengembangan_lokal_tidak_dipaksa_https(): void
    {
        // Tanpa header yang diteruskan sama sekali: http://localhost harus
        // tetap http, tidak ada pemaksaan skema di mana pun.
        $this->trustNobody();

        $hasil = $this->lihat();

        $this->assertFalse($hasil['secure']);
        $this->assertStringStartsWith('http://', $hasil['root']);
        $this->assertStringStartsWith('http://', $hasil['named']);
    }

    // ------------------------------------------------------ dengan proxy

    public function test_proxy_tepercaya_membuat_permintaan_terbaca_aman(): void
    {
        $this->trustAnyProxy();

        $hasil = $this->lihat(['X-Forwarded-Proto' => 'https']);

        $this->assertTrue($hasil['secure']);
        $this->assertSame('https', $hasil['scheme']);
    }

    public function test_url_absolut_memakai_https_di_belakang_proxy(): void
    {
        /*
         * Inilah yang sebenarnya rusak di peramban: bukan CSS-nya, melainkan
         * alamat tempat CSS itu diminta. Halaman https yang memuat aset http
         * diblokir sebagai konten campuran, dan yang tersisa adalah HTML polos
         * dengan SVG seukuran layar.
         */
        $this->trustAnyProxy();

        $hasil = $this->lihat(['X-Forwarded-Proto' => 'https']);

        $this->assertStringStartsWith('https://', $hasil['root']);
        $this->assertStringStartsWith('https://', $hasil['named']);
        $this->assertStringStartsWith('https://', $hasil['aset']);
    }

    public function test_tanpa_header_proto_proxy_tepercaya_tidak_memaksa_https(): void
    {
        // Memercayai proxy bukan berarti mengasumsikan TLS. Tanpa header
        // proto, permintaannya tetap apa adanya.
        $this->trustAnyProxy();

        $hasil = $this->lihat();

        $this->assertFalse($hasil['secure']);
        $this->assertStringStartsWith('http://', $hasil['root']);
    }

    // ----------------------------------------------------- batas keamanan

    public function test_host_yang_diteruskan_tetap_tidak_dipercaya(): void
    {
        /*
         * butir 358. Host yang dipercaya berarti siapa pun yang dapat
         * menjangkau origin dapat menentukan domain pada tautan atur ulang
         * kata sandi yang dikirim lewat surel. Proxy sudah meneruskan Host
         * yang benar, jadi tidak ada yang hilang dengan menolaknya.
         */
        $this->trustAnyProxy();

        $hasil = $this->lihat([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'penyerang.example.com',
        ]);

        $this->assertNotSame('penyerang.example.com', $hasil['host']);
        $this->assertStringNotContainsString('penyerang.example.com', $hasil['root']);
        $this->assertStringNotContainsString('penyerang.example.com', $hasil['named']);
    }

    public function test_daftar_header_tetap_sempit(): void
    {
        $headers = (int) config('trustedproxy.headers');

        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
            $headers,
        );

        /*
         * Yang sengaja TIDAK ada, dan harus tetap tidak ada. Keduanya bit
         * tersendiri, sehingga masuk-tidaknya dapat diperiksa satu per satu.
         */
        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_HOST);
        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_PREFIX);

        /*
         * HEADER_X_FORWARDED_AWS_ELB sengaja tidak ikut diperiksa, dan itu
         * perlu dicatat supaya tidak ada yang menambahkannya kelak: ia bukan
         * bit tersendiri melainkan preset bernilai FOR|PROTO|PORT — persis
         * mask di atas. Memeriksa "AWS ELB tidak aktif" karena itu mustahil
         * terpenuhi, bukan karena maskny salah (butir 583).
         */
        $this->assertSame(Request::HEADER_X_FORWARDED_AWS_ELB, $headers);
    }
}
