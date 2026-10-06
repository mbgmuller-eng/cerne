<div class="mx-auto max-w-2xl space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Minha conta</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Seus dados, sua assinatura e os clientes vinculados a você.</p>
    </div>

    {{-- Meus dados ------------------------------------------------------ --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Meus dados</p>
        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Nome</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $profissional->name }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">E-mail</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $profissional->email }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 dark:text-slate-400">Você é</dt>
                <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $profissional->role->label() }}</dd>
            </div>
            @if ($profissional->birthdate)
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Aniversário</dt>
                    <dd class="mt-0.5 text-sm text-slate-800 dark:text-slate-200">{{ $profissional->birthdate->format('d/m/Y') }}</dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- Assinatura ---------------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">Assinatura</p>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            Cobre, sem custo adicional, todos os clientes vinculados e ativos listados abaixo.
        </p>

        <div class="mt-3">
            <x-subscription-summary :subscription="$minhaAssinatura">
                Nenhuma assinatura ainda.
            </x-subscription-summary>
        </div>

        <a href="{{ route('subscription.index') }}" class="mt-3 inline-block text-xs text-brand-700 hover:underline dark:text-brand-300">Gerenciar assinatura</a>
    </section>

    {{-- Clientes vinculados ------------------------------------------- --}}
    <section class="card p-5">
        <p class="text-sm font-semibold text-slate-900 dark:text-white">
            Clientes vinculados
            @if ($minhaAssinatura?->client_cap !== null)
                <span class="font-normal text-slate-400">({{ $clientesVinculados->count() }} de {{ $minhaAssinatura->client_cap }})</span>
            @else
                <span class="font-normal text-slate-400">({{ $clientesVinculados->count() }})</span>
            @endif
        </p>
        @if ($vagasRestantes === 0)
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                Limite da faixa atingido. <a href="{{ route('subscription.index') }}" class="underline">Aumente o limite</a> para vincular mais clientes.
            </p>
        @endif

        @if ($clientesVinculados->isNotEmpty())
            <div class="mt-3 space-y-2">
                @foreach ($clientesVinculados as $vinculo)
                    <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-700/50">
                        <div>
                            <p class="text-sm text-slate-800 dark:text-slate-200">{{ $vinculo->client->name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $vinculo->client->email }} · vinculado desde {{ $vinculo->accepted_at?->format('d/m/Y') ?? '—' }}</p>
                        </div>
                        <button
                            type="button"
                            wire:click="removerCliente('{{ $vinculo->id }}')"
                            wire:confirm="Remover {{ $vinculo->client->name }} da carteira? O acesso que vem da sua assinatura é encerrado na hora."
                            class="shrink-0 text-xs text-red-700 hover:underline dark:text-red-400"
                        >Remover</button>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum cliente vinculado ainda.</p>
        @endif
    </section>

    @include('livewire.profile.partials.notifications')

    @include('livewire.profile.partials.logout-mobile')

</div>
