{{-- KAS-03 — ringkasan keuangan seluruh cabang untuk Super Admin. --}}
<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($this->hasRows())
        @php($totals = $this->totals())

        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Per Cabang') }}</x-slot>
            <x-slot name="description">
                {{ __('Total tagihan yang diterbitkan, jumlah yang sudah terkumpul, dan proporsi tagihan yang lunas.') }}
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 font-medium">{{ __('Cabang') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Total Tagihan') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Total Terkumpul') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Persentase Lunas') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-2">
                                    <span class="font-medium">{{ $row['school_name'] }}</span>
                                    <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['school_code'] }}</span>
                                    @unless ($row['is_active'])
                                        <x-filament::badge color="gray" class="ml-2 inline-flex">
                                            {{ __('Nonaktif') }}
                                        </x-filament::badge>
                                    @endunless
                                </td>
                                <td class="py-2 text-right">
                                    Rp {{ number_format((float) $row['total_billed'], 0, ',', '.') }}
                                </td>
                                <td class="py-2 text-right text-success-600 dark:text-success-400">
                                    Rp {{ number_format((float) $row['total_collected'], 0, ',', '.') }}
                                </td>
                                <td class="py-2 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="hidden h-1.5 w-24 overflow-hidden rounded-full bg-gray-200 sm:block dark:bg-gray-700">
                                            <div
                                                class="h-full rounded-full bg-primary-500"
                                                style="width: {{ min(100, (float) $row['paid_percentage']) }}%"
                                            ></div>
                                        </div>
                                        <span class="font-medium tabular-nums">{{ $row['paid_percentage'] }}%</span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $row['paid_count'] }} / {{ $row['billable_count'] }} {{ __('tagihan lunas') }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 font-semibold dark:border-gray-700">
                            <td class="py-2">{{ __('Seluruh Cabang') }}</td>
                            <td class="py-2 text-right">
                                Rp {{ number_format((float) $totals['total_billed'], 0, ',', '.') }}
                            </td>
                            <td class="py-2 text-right">
                                Rp {{ number_format((float) $totals['total_collected'], 0, ',', '.') }}
                            </td>
                            <td class="py-2 text-right text-gray-400 dark:text-gray-500">—</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Persentase lunas dihitung dari jumlah tagihan berstatus Lunas dibagi tagihan yang masih perlu dibayar. Tagihan yang dibebaskan tidak ikut dihitung.') }}
            </p>
        </x-filament::section>
    @else
        <x-filament::section class="mt-6">
            <x-slot name="heading">{{ __('Belum ada data untuk ditampilkan') }}</x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Belum ada cabang dengan tagihan pada filter ini, atau bulan yang diisi belum berformat YYYY-MM.') }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
