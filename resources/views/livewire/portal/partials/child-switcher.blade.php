{{--
    Pemilih anak yang dipakai bersama seluruh halaman portal — satu tampilan
    untuk satu mekanisme (butir 167). Dengan satu anak, pemilihnya tidak perlu
    muncul sama sekali.
--}}
@if ($children->count() > 1)
    <div class="portal-child-switcher" role="group" aria-label="Pilih anak">
        @foreach ($children as $child)
            <button
                type="button"
                class="portal-child-button"
                aria-pressed="{{ $selectedChildId === $child->id ? 'true' : 'false' }}"
                wire:click="selectChild({{ $child->id }})"
            >{{ $child->full_name }}</button>
        @endforeach
    </div>
@endif
