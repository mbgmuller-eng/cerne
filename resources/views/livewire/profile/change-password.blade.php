<section class="card p-5" id="senha" data-change-password>
    <p class="text-sm font-semibold text-slate-900 dark:text-white">Senha</p>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Ao trocar a senha, todos os outros aparelhos e navegadores conectados a esta conta saem na hora.</p>

    @unless (session()->has('impersonator_id'))
        <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-3" autocomplete="off">
            <div>
                <label for="currentPassword" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha atual</label>
                <input id="currentPassword" type="password" wire:model="currentPassword" autocomplete="current-password" class="input mt-1.5">
                @error('currentPassword') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="newPassword" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Nova senha</label>
                <input id="newPassword" type="password" wire:model="newPassword" autocomplete="new-password" class="input mt-1.5">
                <p class="mt-1 text-xs text-slate-400">Pelo menos 8 caracteres, com letras e números.</p>
                @error('newPassword') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="newPasswordConfirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Repita a nova senha</label>
                <input id="newPasswordConfirmation" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="input mt-1.5">
                @error('newPasswordConfirmation') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Trocar senha</button>
            </div>
        </form>
    @else
        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Indisponível enquanto você vê a conta como outra pessoa.</p>
    @endunless
</section>
