<div>
    @if ($data === null)
        @include('livewire.student.partials.unlinked')
    @else
        {{--
            Hitung mundur ini **gambar**, bukan pagar. Sisa waktunya berasal dari
            jam server pada setiap render, dan setiap aksi memeriksa ulang
            `expires_at` di sisi server. Menghentikannya lewat konsol peramban
            tidak memperpanjang apa pun (butir 311).
        --}}
        <div
            class="portal-card"
            style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;"
            x-data="{ left: {{ $data['remaining_seconds'] }} }"
            x-init="setInterval(() => { if (left > 0) { left-- } }, 1000)"
        >
            <div style="min-width:0;">
                <div style="font-weight:700;overflow-wrap:anywhere;">{{ $data['exam']['title'] }}</div>
                <div class="portal-muted">
                    {{ $data['exam']['subject_name'] ?? __('Mata pelajaran') }}
                    · {{ __(':answered/:total terjawab', ['answered' => $data['answered'], 'total' => $data['total']]) }}
                </div>
            </div>

            <div style="text-align:right;">
                <div class="portal-label">{{ __('Sisa waktu') }}</div>
                <div
                    style="font-size:1.5rem;font-weight:700;font-variant-numeric:tabular-nums;"
                    x-text="String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')"
                >—</div>
                <div class="portal-sr-only" aria-live="polite">
                    {{ __('Sisa waktu :count menit', ['count' => intdiv($data['remaining_seconds'], 60)]) }}
                </div>
            </div>
        </div>

        @if ($notice)
            <div class="portal-card" style="margin-bottom:1rem;border-left:4px solid var(--color-danger);">
                <p style="margin:0;">{{ $notice }}</p>
            </div>
        @endif

        @if ($data['question'] === null)
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">{{ __('Ujian ini belum memiliki soal.') }}</p>
            </div>
        @else
            {{-- Peta nomor soal: yang sudah dijawab ditandai, sehingga siswa
                 tahu apa yang tersisa tanpa membuka satu per satu. --}}
            <div class="portal-card" style="margin-bottom:1rem;">
                <div class="portal-label" style="margin-bottom:.5rem;">{{ __('Nomor Soal') }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:.375rem;">
                    @foreach ($data['questions'] as $i => $item)
                        <button
                            type="button"
                            wire:click="goTo({{ $i }})"
                            class="portal-child-button"
                            aria-current="{{ $i === $index ? 'true' : 'false' }}"
                            @if ($i === $index) aria-pressed="true" @endif
                            style="min-width:2.75rem;padding:.5rem;
                                {{ array_key_exists($item['id'], $answers) ? 'border-color:var(--color-primary);font-weight:700;' : '' }}"
                        >{{ $item['number'] }}</button>
                    @endforeach
                </div>
            </div>

            <div class="portal-card">
                <div class="portal-label">
                    {{ __('Soal :number dari :total', ['number' => $data['question']['number'], 'total' => $data['total']]) }}
                    · {{ __('bobot :points', ['points' => rtrim(rtrim(number_format($data['question']['points'], 2, ',', '.'), '0'), ',')]) }}
                </div>

                <p style="margin:.5rem 0 1rem;white-space:pre-line;overflow-wrap:anywhere;">
                    {{ $data['question']['question_text'] }}
                </p>

                {{--
                    Yang dirender hanya id dan teks pilihan. Tidak ada satu pun
                    penanda mana yang benar — bukan disembunyikan CSS, melainkan
                    memang tidak pernah diambil dari database (butir 310).
                --}}
                <div role="group" aria-label="{{ __('Pilihan Jawaban') }}" style="display:grid;gap:.5rem;">
                    @foreach ($data['question']['options'] as $option)
                        @php
                            $chosen = ($answers[$data['question']['id']] ?? null) === $option['id'];
                        @endphp
                        <button
                            type="button"
                            wire:click="choose({{ $data['question']['id'] }}, {{ $option['id'] }})"
                            aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                            style="display:flex;gap:.625rem;align-items:flex-start;text-align:left;
                                   min-height:2.75rem;padding:.75rem;font:inherit;cursor:pointer;
                                   border-radius:.5rem;background:var(--color-surface);
                                   border:1px solid {{ $chosen ? 'var(--color-primary)' : 'var(--color-border)' }};
                                   {{ $chosen ? 'box-shadow:inset 0 0 0 1px var(--color-primary);' : '' }}"
                        >
                            <span aria-hidden="true" style="flex:0 0 auto;font-weight:700;">
                                {{ $chosen ? '●' : '○' }}
                            </span>
                            <span style="overflow-wrap:anywhere;">{{ $option['option_text'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem;">
                    <button
                        type="button"
                        wire:click="previous"
                        class="portal-child-button"
                        @disabled($index <= 0)
                    >{{ __('Sebelumnya') }}</button>

                    <button
                        type="button"
                        wire:click="next"
                        class="portal-child-button"
                        @disabled($index >= $data['total'] - 1)
                    >{{ __('Berikutnya') }}</button>
                </div>
            </div>

            <div class="portal-card" style="margin-top:1rem;">
                <p class="portal-muted" style="margin:0 0 .75rem;">
                    {{ __('Jawaban tersimpan otomatis setiap kali Anda memilih. Setelah dikumpulkan, jawaban tidak dapat diubah lagi.') }}
                </p>

                <button
                    type="button"
                    class="portal-button"
                    wire:click="submit"
                    wire:confirm="{{ __('Kumpulkan ujian ini? Jawaban tidak dapat diubah setelah dikumpulkan.') }}"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >{{ __('Kumpulkan Ujian') }}</button>
            </div>
        @endif
    @endif
</div>
