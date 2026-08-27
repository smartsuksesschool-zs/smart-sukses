<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `RefreshDatabase` menyala sejak Batch L1.
     *
     * Sampai batch itu `/` mengembalikan halaman bawaan Laravel — sebuah view
     * statis yang tidak menyentuh database sama sekali, sehingga test ini lulus
     * tanpa tabel apa pun. Halaman muka yang menggantikannya membaca daftar
     * cabang aktif, dan tanpa database test ini gagal dengan "no such table:
     * schools" (butir 354).
     *
     * Test-nya sendiri dipertahankan apa adanya: ia memeriksa hal yang paling
     * mendasar — bahwa halaman muka terbuka — dan sekarang memeriksanya terhadap
     * database yang benar-benar ada.
     */
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
