{{-- KAS-02 — ringkasan keuangan bulanan satu cabang. --}}
<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($this->hasSummary())
        {{-- KAS-02 poin 1: saldo kas, penerimaan SPP bulan ini, pengeluaran bulan ini. --}}
        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Saldo Kas') }}</dt>
                <dd class="mt-1 text-2xl font-semibold">
                    Rp {{ number_format((float) $summary['cash_balance'], 0, ',', '.') }}
                </dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Posisi sampai') }} {{ \Illuminate\Support\Carbon::parse($summary['period_end'])->translatedFormat('d M Y') }}
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Penerimaan SPP Bulan Ini') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">
                    Rp {{ number_format((float) $summary['spp_received'], 0, ',', '.') }}
                </dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Pembayaran yang tercatat pada periode ini') }}
                </p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Pengeluaran Bulan Ini') }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-danger-600 dark:text-danger-400">
                    Rp {{ number_format((float) $summary['expenses'], 0, ',', '.') }}
                </dd>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Transaksi kas bertipe pengeluaran') }}
                </p>
            </div>
        </dl>

        {{-- KAS-02 poin 2: grafik tren 6 bulan terakhir, lama → baru. --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Tren 6 Bulan Terakhir') }}</x-slot>
            <x-slot name="description">
                {{ __('Pemasukan dan pengeluaran buku kas per bulan. Penerimaan SPP tidak termasuk di sini karena dicatat pada jalur terpisah.') }}
            </x-slot>

            <div class="flex items-end justify-between gap-3 sm:gap-6" style="height: 12rem;">
                @foreach ($summary['trend'] as $month)
                    <div class="flex h-full flex-1 flex-col justify-end gap-2">
                        <div class="flex h-full items-end justify-center gap-1">
                            <div
                                class="w-1/3 rounded-t bg-success-500"
                                style="height: {{ $this->trendBarHeight($month['income']) }}%"
                                title="{{ __('Pemasukan') }}: Rp {{ number_format((float) $month['income'], 0, ',', '.') }}"
                            ></div>
                            <div
                                class="w-1/3 rounded-t bg-danger-500"
                                style="height: {{ $this->trendBarHeight($month['expense']) }}%"
                                title="{{ __('Pengeluaran') }}: Rp {{ number_format((float) $month['expense'], 0, ',', '.') }}"
                            ></div>
                        </div>
                        <span class="text-center text-xs text-gray-500 dark:text-gray-400">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1">
                    <span class="inline-block h-2 w-2 rounded-sm bg-success-500"></span>{{ __('Pemasukan') }}
                </span>
                <span class="flex items-center gap-1">
                    <span class="inline-block h-2 w-2 rounded-sm bg-danger-500"></span>{{ __('Pengeluaran') }}
                </span>
            </div>

            {{-- Tiga kolom rupiah pada 360px. Dibungkus penggulung sendiri
                 supaya tabelnya yang menggulung, bukan halamannya — pola yang
                 sama dengan laporan-keuangan-cabang (butir 395). --}}
            <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 font-medium">{{ __('Bulan') }}</th>
                        <th class="py-1 text-right font-medium">{{ __('Pemasukan') }}</th>
                        <th class="py-1 text-right font-medium">{{ __('Pengeluaran') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['trend'] as $month)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1">{{ $month['label'] }}</td>
                            <td class="py-1 text-right">Rp {{ number_format((float) $month['income'], 0, ',', '.') }}</td>
                            <td class="py-1 text-right">Rp {{ number_format((float) $month['expense'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Ringkasan belum dapat ditampilkan') }}</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Pilih cabang sekolah dan periode yang valid untuk melihat ringkasannya.') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
