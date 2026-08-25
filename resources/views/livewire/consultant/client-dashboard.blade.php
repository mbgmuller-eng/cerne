<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Meus clientes</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $clients->count() }} {{ $clients->count() === 1 ? 'cliente vinculado' : 'clientes vinculados' }}
        </p>
    </div>

    {{-- Convite ------------------------------------------------------ --}}
    <section class="card p-6">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Convidar um cliente</h2>

        <form wire:submit="invite" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <input
                    wire:model="inviteName"
                    type="text"
                    placeholder="Nome"
                    class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none dark:bg-slate-800 dark:text-slate-100"
                >
                @error('inviteName')
                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex-1">
                <input
                    wire:model="inviteEmail"
                    type="email"
                    placeholder="E-mail"
                    class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none dark:bg-slate-800 dark:text-slate-100"
                >
                @error('inviteEmail')
                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="rounded-lg bg-brand-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-900 disabled:opacity-60"
                wire:loading.attr="disabled"
            >
                Convidar
            </button>
        </form>

        @if ($lastInviteLink)
            {{-- O link é mostrado para o caso de o e-mail não chegar; o
                 consultor consegue repassá-lo por outro canal. --}}
            <div class="mt-4 rounded-lg bg-slate-50 dark:bg-slate-700 p-3">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link do convite</p>
                <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
            </div>
        @endif
    </section>

    {{-- Convites pendentes -------------------------------------------- --}}
    @if ($pendingInvites->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Aguardando aceite</h2>
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($pendingInvites as $invite)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $invite->client_name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $invite->client_email }}</p>
                        </div>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            expira {{ $invite->expires_at->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Clientes ------------------------------------------------------ --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Clientes</h2>

        @if ($clients->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum cliente vinculado ainda.</p>
                <p class="mt-1 text-xs text-slate-400">Use o formulário acima para enviar o primeiro convite.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($clients as $link)
                    @php $profile = $link->client->ownedProfiles->first(); @endphp
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $link->client->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $link->client->email }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-brand-100 text-brand-900 dark:bg-brand-500/20 dark:text-brand-100' => $link->status === \App\Enums\ConsultantClientStatus::Active,
                                'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $link->status === \App\Enums\ConsultantClientStatus::Pending,
                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $link->status === \App\Enums\ConsultantClientStatus::Inactive,
                            ])>
                                {{ $link->status->label() }}
                            </span>

                            @if ($profile && $link->status === \App\Enums\ConsultantClientStatus::Active)
                                {{-- Form puro: abrir o perfil do cliente não pode
                                     depender de JavaScript. --}}
                                <form method="POST" action="{{ route('profile.switch', $profile) }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="btn-secondary px-3 py-1.5"
                                    >
                                        Abrir perfil
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
