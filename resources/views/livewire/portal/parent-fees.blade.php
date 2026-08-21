<div>
    @include('livewire.portal.partials.child-switcher')

    @if ($data === null)
        <div class="portal-card">
            <h1 style="margin-top:0;font-size:1.25rem;">Belum ada data anak</h1>
            <p class="portal-muted" style="margin-bottom:0;">
                Akun Anda belum terhubung dengan data siswa mana pun.
            </p>
        </div>
    @else
        @php($child = $data['child'])

        <div class="portal-card" style="margin-bottom:1rem;">
            <div class="portal-label">Tagihan</div>
            <div style="font-size:1.25rem;font-weight:700;">{{ $child->full_name }}</div>
            <div class="portal-muted">NIS {{ $child->nis }}</div>
        </div>

        @if ($data['fees']->isEmpty())
            <div class="portal-card">
                <p class="portal-muted" style="margin:0;">Belum ada tagihan untuk anak ini.</p>
            </div>
        @else
            {{-- SPP-04 poin 1 — daftar per periode, terbaru lebih dulu. --}}
            <div class="portal-grid">
                @foreach ($data['fees'] as $fee)
                    @php($remaining = (float) $fee->remaining())
                    <div class="portal-card">
                        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div>
                                <div style="font-weight:700;">{{ $fee->feeType?->name }}</div>
                                <div class="portal-muted">
                                    Periode {{ $fee->period }}
                                    @if ($fee->due_date)
                                        — jatuh tempo {{ $fee->due_date->translatedFormat('d M Y') }}
                                    @endif
                                </div>
                            </div>

                            {{--
                                SPP-04 poin 2 — tagihan belum lunas ditandai.
                                WAIVED tampil berbeda dari LUNAS: domain memang
                                membedakan keduanya, dan menyamakannya akan
                                menghapus jejak keringanan yang diberikan
                                sekolah (butir 166).
                            --}}
                            <div>
                                @php($status = $fee->status?->value)
                                <span class="portal-badge portal-badge--{{
                                    $status === 'UNPAID' ? 'danger'
                                        : ($status === 'PARTIAL' ? 'warning'
                                        : ($status === 'PAID' ? 'success' : 'muted'))
                                }}">{{ $fee->status?->label() }}</span>
                            </div>
                        </div>

                        <div class="portal-grid portal-grid--3" style="margin-top:.75rem;gap:.5rem;">
                            <div>
                                <div class="portal-label">Tagihan</div>
                                <div>Rp {{ number_format((float) $fee->amount, 0, ',', '.') }}</div>
                            </div>
                            <div>
                                <div class="portal-label">Dibayar</div>
                                <div>Rp {{ number_format((float) $fee->amount_paid, 0, ',', '.') }}</div>
                            </div>
                            <div>
                                <div class="portal-label">Sisa</div>
                                <div style="font-weight:700;">
                                    Rp {{ number_format($remaining, 0, ',', '.') }}
                                </div>
                            </div>
                        </div>

                        @if ($fee->isWaived() && $fee->waive_reason)
                            <p class="portal-muted" style="margin:.75rem 0 0;">
                                Dibebaskan: {{ $fee->waive_reason }}
                            </p>
                        @endif

                        {{-- SPP-04 poin 3 — riwayat pembayaran: tanggal & metode. --}}
                        @if ($fee->payments->isNotEmpty())
                            <details style="margin-top:.75rem;">
                                <summary style="cursor:pointer;min-height:2.75rem;display:flex;align-items:center;">
                                    Riwayat pembayaran ({{ $fee->payments->count() }})
                                </summary>
                                <ul class="portal-list" style="margin-top:.5rem;">
                                    @foreach ($fee->payments->sortByDesc('payment_date') as $payment)
                                        <li>
                                            <span>
                                                {{ $payment->payment_date?->translatedFormat('d M Y') }}
                                                <span class="portal-muted">
                                                    — {{ $payment->payment_method?->label() }}
                                                </span>
                                            </span>
                                            <strong>
                                                Rp {{ number_format((float) $payment->amount_paid, 0, ',', '.') }}
                                            </strong>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
