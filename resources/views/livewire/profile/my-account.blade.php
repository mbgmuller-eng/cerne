<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Minha conta</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Seus dados, sua assinatura e quem mais tem acesso a esse perfil.</p>
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

            @if ($ownMember)
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Aniversário</dt>
                    @if ($editingOwnBirthdate)
                        <dd class="mt-1 flex items-center gap-2">
                            <input type="date" wire:model="ownBirthdateInput" class="input py-1 text-xs">
                            <button type="button" wire:click="saveOwnBirthdate" class="btn-primary px-2 py-1 text-xs">Salvar</button>
                            <button type="button" wire:click="toggleOwnBirthdate" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                        </dd>
                        @error('ownBirthdateInput') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    @else
                        <dd class="mt-0.5 flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200">
                            {{ $ownMember->birthdate?->format('d/m/Y') ?? 'Não cadastrado' }}
                            <button type="button" wire:click="toggleOwnBirthdate" class="text-xs text-brand-700 hover:underline dark:text-brand-300">Editar</button>
                        </dd>
                    @endif
                </div>
            @endif
        </dl>
    </section>

    {{-- Assinatura ---------------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Assinatura</p>

        @if ($minhaAssinatura)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Individual: paga diretamente, sem depender de profissional vinculado.</p>
            <div class="mt-3">
                <x-subscription-summary :subscription="$minhaAssinatura" />
            </div>
        @elseif ($profissionaisVinculados->isNotEmpty())
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                Vinculada: o acesso vem da assinatura do profissional abaixo, sem custo para você.
            </p>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhuma assinatura ativa ainda.</p>
        @endif

        {{-- Vinculado a profissional e sem assinatura própria: nada pra
             gerenciar aqui, o acesso já vem de graça pelo profissional. --}}
        @if ($ownMember && ($minhaAssinatura || $profissionaisVinculados->isEmpty()))
            <a href="{{ route('subscription.index') }}" class="mt-3 inline-block text-xs text-brand-700 hover:underline dark:text-brand-300">Gerenciar assinatura</a>
        @endif
    </section>

    {{-- Profissionais vinculados ---------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Profissionais vinculados</p>

        @if ($profissionaisVinculados->isNotEmpty())
            <div class="mt-3 space-y-3">
                @foreach ($profissionaisVinculados as $vinculo)
                    <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-700/50">
                        <div>
                            <p class="text-sm text-slate-800 dark:text-slate-200">{{ $vinculo->consultant->name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $vinculo->consultant->role->label() }} · {{ $vinculo->consultant->email }}</p>
                        </div>
                        @php
                            $assinaturaDele = $assinaturasProfissionais->get($vinculo->consultant_id);
                        @endphp
                        <span @class([
                            'badge shrink-0',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-400' => $assinaturaDele?->isCurrent(),
                            'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-400' => ! $assinaturaDele?->isCurrent(),
                        ])>{{ $assinaturaDele?->isCurrent() ? 'Cobrindo' : 'Sem cobertura ativa' }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum profissional vinculado no momento.</p>
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

                @if ($canManageMembers)
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Aniversário</dt>
                        @if ($editingPartnerBirthdate)
                            <dd class="mt-1 flex items-center gap-2">
                                <input type="date" wire:model="partnerBirthdateInput" class="input py-1 text-xs">
                                <button type="button" wire:click="savePartnerBirthdate" class="btn-primary px-2 py-1 text-xs">Salvar</button>
                                <button type="button" wire:click="togglePartnerBirthdate" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                            </dd>
                            @error('partnerBirthdateInput') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        @else
                            <dd class="mt-0.5 flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200">
                                {{ $partner->birthdate?->format('d/m/Y') ?? 'Não cadastrado' }}
                                <button type="button" wire:click="togglePartnerBirthdate" class="text-xs text-brand-700 hover:underline dark:text-brand-300">Editar</button>
                            </dd>
                        @endif
                    </div>
                @endif
            </dl>

            @if ($partner->user === null && $canInvitePartner)
                <button type="button" wire:click="toggleInviteForm" class="btn-ghost mt-3 px-2 py-1 text-xs">
                    {{ $showInviteForm ? 'Cancelar' : 'Convidar por e-mail' }}
                </button>
            @endif
        @elseif ($pendingInvite)
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                Convite enviado para <strong>{{ $pendingInvite->partner_name }}</strong>
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
                    Ele(a) não poderá acessar a plataforma. Conta bancária, gastos e investimentos em nome
                    dele(a) funcionam normalmente, mas não será possível marcar nada como privado, porque sem login
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

    @include('livewire.profile.partials.notifications')

    @include('livewire.profile.partials.logout-mobile')

</div>
