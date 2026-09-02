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
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">
                        @if ($partner->user)
                            {{ $partner->user->email }}
                        @else
                            <span class="text-slate-400">Cadastrado sem login</span>
                        @endif
                    </dd>
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
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Você ainda não tem cônjuge vinculado a este perfil.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="toggleInviteForm" class="btn-secondary px-3 py-1.5">+ Convidar por e-mail</button>
                <button type="button" wire:click="togglePartnerOnlyForm" class="btn-ghost px-3 py-1.5">Cadastrar sem login</button>
            </div>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum cônjuge vinculado a este perfil.</p>
        @endif

        <x-modal wire-model="showInviteForm">
            <form wire:submit="invitePartner" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Convidar cônjuge por e-mail</h2>
                    <button type="button" wire:click="toggleInviteForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

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

                @if ($lastInviteLink)
                    {{-- O link é mostrado para o caso de o e-mail não chegar; dá
                         pra repassar por outro canal. --}}
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-700">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link do convite</p>
                        <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
                    </div>
                @endif
            </form>
        </x-modal>

        <x-modal wire-model="showPartnerOnlyForm">
            <form wire:submit="addPartnerWithoutLogin" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Cadastrar cônjuge sem login</h2>
                    <button type="button" wire:click="togglePartnerOnlyForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Ele(a) não vai poder acessar a plataforma. Conta bancária, gastos e investimentos em nome
                    dele(a) funcionam normal — só não vai dar pra marcar nada como privado, porque sem login
                    ninguém veria esse dado, nem ele(a) mesmo(a).
                </p>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="partnerOnlyName" class="input mt-1.5" placeholder="Nome do cônjuge">
                    @error('partnerOnlyName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Cadastrar</button>
            </form>
        </x-modal>
    </section>

    {{-- Notificações ---------------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Notificações</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Avisos no sino do app estão sempre ativos. Escolha os outros canais para vencimento de conta, fatura de cartão e status de importação de PDF:
        </p>

        <div class="mt-3 space-y-3">
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input type="checkbox" wire:model.live="notifyEmail" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
                E-mail
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                <input
                    type="checkbox"
                    wire:model.live="notifyPush"
                    class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500"
                    @change="if ($event.target.checked) { window.cerneSubscribeToPush?.() }"
                >
                Notificações push (neste navegador)
            </label>
        </div>
    </section>

</div>
