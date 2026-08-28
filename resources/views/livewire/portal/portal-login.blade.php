<div class="portal-centered">
    <div class="portal-card">
        <h1 style="margin-top:0;font-size:1.25rem;">{{ __('Portal Orang Tua') }}</h1>
        <p class="portal-muted">{{ __('Masuk untuk melihat perkembangan anak Anda.') }}</p>

        <form wire:submit="authenticate">
            <div class="portal-field">
                <label for="portal-email">{{ __('Email') }}</label>
                <input
                    id="portal-email"
                    type="email"
                    inputmode="email"
                    autocomplete="email"
                    wire:model="email"
                    required
                >
                @error('email') <div class="portal-error">{{ $message }}</div> @enderror
            </div>

            <div class="portal-field">
                <label for="portal-password">{{ __('Kata Sandi') }}</label>
                <input
                    id="portal-password"
                    type="password"
                    autocomplete="current-password"
                    wire:model="password"
                    required
                >
                @error('password') <div class="portal-error">{{ $message }}</div> @enderror
            </div>

            <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;font-size:.875rem;">
                <input type="checkbox" wire:model="remember">
                {{ __('Ingat saya di perangkat ini') }}
            </label>

            <button type="submit" class="portal-button">{{ __('Masuk') }}</button>
        </form>
    </div>
</div>
