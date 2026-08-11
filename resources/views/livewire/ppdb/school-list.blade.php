{{-- API 4.7 — GET /ppdb/schools: daftar cabang yang membuka PPDB. --}}
<div>
    <div class="card">
        <h2>{{ __('Pilih Cabang') }}</h2>
        <p class="hint">{{ __('Pilih cabang sekolah tujuan untuk mengisi formulir pendaftaran.') }}</p>

        @if ($schools->isEmpty())
            <p>{{ __('Belum ada cabang yang membuka pendaftaran.') }}</p>
        @else
            <ul class="school-list">
                @foreach ($schools as $school)
                    <li class="card" style="margin-bottom:0;padding:1rem;">
                        <a href="{{ route('ppdb.register', ['schoolCode' => strtolower($school->code)]) }}"
                           wire:navigate>
                            <strong>{{ $school->name }}</strong>
                            <span class="badge">{{ $school->code }}</span>
                            @if ($school->address)
                                <div class="hint">{{ $school->address }}</div>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="nav-links">
            <a href="{{ route('ppdb.check-status') }}" wire:navigate>{{ __('Sudah mendaftar? Cek status pendaftaran') }}</a>
        </p>
    </div>
</div>
