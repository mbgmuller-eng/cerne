<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Meus clientes</h1>
        <p class="mt-1 text-sm text-stone-500">
            {{ $clients->count() }} {{ $clients->count() === 1 ? 'cliente vinculado' : 'clientes vinculados' }}
        </p>
    </div>

    {{-- Convite ------------------------------------------------------ --}}
    <section class="card p-6">
        <h2 class="text-sm font-semibold text-stone-900">Convidar um cliente</h2>

        <form wire:submit="invite" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <input
                    wire:model="inviteName"
                    type="text"
                    placeholder="Nome"
                    class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none"
                >
                @error('inviteName')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex-1">
                <input
                    wire:model="inviteEmail"
                    type="email"
                    placeholder="E-mail"
                    class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none"
                >
                @error('inviteEmail')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
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
            <div class="mt-4 rounded-lg bg-stone-50 p-3">
                <p class="text-xs font-medium text-stone-600">Link do convite</p>
                <p class="mt-1 font-mono text-xs break-all text-stone-700">{{ $lastInviteLink }}</p>
            </div>
        @endif
    </section>

    {{-- Convites pendentes -------------------------------------------- --}}
    @if ($pendingInvites->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-stone-900">Aguardando aceite</h2>
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($pendingInvites as $invite)
                    <li class="flex items-center justify-between px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-stone-800">{{ $invite->client_name }}</p>
                            <p class="text-xs text-stone-500">{{ $invite->client_email }}</p>
                        </div>
                        <span class="text-xs text-stone-500">
                            expira {{ $invite->expires_at->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Clientes ------------------------------------------------------ --}}
    <section>
        <h2 class="text-sm font-semibold text-stone-900">Clientes</h2>

        @if ($clients->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-10 text-center">
                <p class="text-sm text-stone-500">Nenhum cliente vinculado ainda.</p>
                <p class="mt-1 text-xs text-stone-400">Use o formulário acima para enviar o primeiro convite.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($clients as $link)
                    @php $profile = $link->client->ownedProfiles->first(); @endphp
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-stone-800">{{ $link->client->name }}</p>
                            <p class="truncate text-xs text-stone-500">{{ $link->client->email }}</p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-brand-100 text-brand-900' => $link->status === \App\Enums\ConsultantClientStatus::Active,
                                'bg-amber-100 text-amber-900' => $link->status === \App\Enums\ConsultantClientStatus::Pending,
                                'bg-stone-100 text-stone-600' => $link->status === \App\Enums\ConsultantClientStatus::Inactive,
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
