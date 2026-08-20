<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\School;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'type' => TransactionType::Income->value,
            'category' => 'Dana BOS',
            'amount' => 2_500_000,
            // Keduanya wajib menurut aturan validasi KAS-01, walaupun kolomnya
            // tetap NULL di ERD — lihat butir 81. Baris bawaan factory karena
            // itu menggambarkan transaksi yang sah, bukan yang minimum.
            'description' => 'Pencairan dana BOS triwulan III',
            'reference_number' => 'BOS-2026-III',
            'proof_url' => null,
            'transaction_date' => '2026-08-05',
            'created_by' => User::factory(),
        ];
    }

    public function income(): static
    {
        return $this->state(fn () => ['type' => TransactionType::Income->value]);
    }

    public function expense(): static
    {
        return $this->state(fn () => [
            'type' => TransactionType::Expense->value,
            'category' => 'Pembelian Alat',
        ]);
    }
}
