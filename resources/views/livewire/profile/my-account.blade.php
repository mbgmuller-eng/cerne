<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Minha conta</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Seus dados, seu consultor e quem mais tem acesso a esse perfil.</p>
    </div>

    {{-- Meus dados ------------------------------------------------------ --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Meus dados</p>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Nome</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $user->name }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">E-mail</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $user->email }}</dd>
            </div>
        </dl>
    </section>

    {{-- Meu consultor ----------------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Meu consultor</p>

        @if ($consultant)
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Nome</dt>
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $consultant->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">E-mail</dt>
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $consultant->email }}</dd>
                </div>
            </dl>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum consultor vinculado no momento.</p>
        @endif
    </section>

    {{-- Cônjuge ------------------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Cônjuge</p>

        @if ($partner)
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Nome</dt>
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $partner->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">E-mail</dt>
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $partner->user->email }}</dd>
                </div>
            </dl>
        @elseif ($pendingInvite)
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                Convite enviado pra <strong>{{ $pendingInvite->partner_name }}</strong>
                ({{ $pendingInvite->partner_email }}), aguardando aceite.
            </p>

            @if ($canInvitePartner)
                <button type="button" wire:click="toggleInviteForm" class="btn-ghost mt-3 px-2 py-1 text-xs">
                    {{ $showInviteForm ? 'Cancelar' : 'Convidar de novo' }}
                </button>
            @endif
        @elseif ($canInvitePartner)
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Você ainda não convidou seu cônjuge.</p>
            <button type="button" wire:click="toggleInviteForm" class="btn-secondary mt-3 px-3 py-1.5">
                {{ $showInviteForm ? 'Cancelar' : '+ Convidar cônjuge' }}
            </button>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum cônjuge vinculado a este perfil.</p>
        @endif

        @if ($showInviteForm && $canInvitePartner)
            <form wire:submit="invitePartner" class="mt-4 space-y-4 border-t border-slate-100 pt-4 dark:border-white/10">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                        <input type="text" wire:model="partnerName" class="input mt-1.5" placeholder="Nome do cônjuge">
                        @error('partnerName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">E-mail</label>
                        <input type="email" wire:model="partnerEmail" class="input mt-1.5" placeholder="email@exemplo.com">
                        @error('partnerEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Convidar</button>
            </form>

            @if ($lastInviteLink)
                {{-- O link é mostrado para o caso de o e-mail não chegar; dá
                     pra repassar por outro canal. --}}
                <div class="mt-4 rounded-lg bg-slate-50 p-3 dark:bg-slate-700">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link do convite</p>
                    <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
                </div>
            @endif
        @endif
    </section>

</div>
