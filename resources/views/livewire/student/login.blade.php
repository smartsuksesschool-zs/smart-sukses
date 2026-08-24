<div class="portal-centered">
    <div class="portal-card">
        <h1 style="margin-top:0;font-size:1.25rem;">Portal Siswa</h1>
        <p class="portal-muted">Masuk untuk melihat jadwal dan nilai Anda.</p>

        <form wire:submit="authenticate">
            <div class="portal-field">
                <label for="student-email">Email</label>
                <input id="student-email" type="email" inputmode="email"
                       autocomplete="email" wire:model="email" required>
                @error('email') <div class="portal-error">{{ $message }}</div> @enderror
            </div>

            <div class="portal-field">
                <label for="student-password">Kata Sandi</label>
                <input id="student-password" type="password"
                       autocomplete="current-password" wire:model="password" required>
                @error('password') <div class="portal-error">{{ $message }}</div> @enderror
            </div>

            <label style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;font-size:.875rem;">
                <input type="checkbox" wire:model="remember">
                Ingat saya di perangkat ini
            </label>

            <button type="submit" class="portal-button">Masuk</button>
        </form>
    </div>
</div>
