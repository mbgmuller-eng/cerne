@use('App\Enums\ProfileType')
@use('App\Enums\UserRole')

<div class="space-y-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Painel admin</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $users->count() }} {{ $users->count() === 1 ? 'conta' : 'contas' }} · {{ $profiles->count() }} {{ $profiles->count() === 1 ? 'perfil' : 'perfis' }} na plataforma
            </p>
        </div>
        <button type="button" wire:click="toggleInviteForm" class="btn-secondary">
            {{ $showInviteForm ? 'Cancelar' : '+ Criar conta sem consultor' }}
        </button>
    </div>

    @if ($showInviteForm)
        <div class="card space-y-4 p-5">
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Criar conta sem consultor</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Gera um link de convite comum — quem abrir define a própria senha. A diferença é que essa conta
                    não fica vinculada a nenhum consultor: ninguém além do próprio dono enxerga os dados dela.
                </p>
            </div>

            <form wire:submit="invite" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="inviteName" class="input mt-1.5" placeholder="Nome da pessoa">
                    @error('inviteName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">E-mail</label>
                    <input type="email" wire:model="inviteEmail" class="input mt-1.5" placeholder="email@exemplo.com">
                    @error('inviteEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">Gerar convite</button>
                </div>
            </form>

            @if ($lastInviteLink)
                <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-700">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Link do convite — copie e envie por onde preferir</p>
                    <p class="mt-1 font-mono text-xs break-all text-slate-700 dark:text-slate-300">{{ $lastInviteLink }}</p>
                </div>
            @endif

            @if ($this->pendingInvites->isNotEmpty())
                <div class="border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Convites sem consultor, aguardando cadastro</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($this->pendingInvites as $convite)
                            <li class="flex items-baseline justify-between py-1.5 text-sm">
                                <span class="text-slate-700 dark:text-slate-300">{{ $convite->client_name }}</span>
                                <span class="text-xs text-slate-400">{{ $convite->client_email }} · expira {{ $convite->expires_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <div class="card overflow-x-auto p-0">
        <p class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 dark:border-white/10 dark:text-white">Contas</p>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-slate-500 dark:text-slate-400">
                <tr>
                    <th class="px-5 py-2 font-medium">Nome</th>
                    <th class="px-5 py-2 font-medium">E-mail</th>
                    <th class="px-5 py-2 font-medium">Papel</th>
                    <th class="px-5 py-2 font-medium">Criada em</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($users as $u)
                    <tr>
                        <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">
                            {{ $u->name }}
                            @if ($u->isPlatformAdmin())
                                <span class="badge bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20 ml-1.5">admin</span>
                            @endif
                        </td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $u->email }}</td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $u->role->label() }}</td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $u->created_at->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card overflow-x-auto p-0">
        <p class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900 dark:border-white/10 dark:text-white">Perfis financeiros</p>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-slate-500 dark:text-slate-400">
                <tr>
                    <th class="px-5 py-2 font-medium">Perfil</th>
                    <th class="px-5 py-2 font-medium">Dono</th>
                    <th class="px-5 py-2 font-medium">Tipo</th>
                    <th class="px-5 py-2 font-medium">Consultor</th>
                    <th class="px-5 py-2 font-medium">Criado em</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($profiles as $p)
                    <tr>
                        <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">{{ $p->profile_name }}</td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->owner->email }}</td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->profile_type->label() }}</td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">
                            @php $vinculo = $p->owner->consultantLinks->first(); @endphp
                            @if ($vinculo)
                                {{ $vinculo->consultant->name }}
                            @else
                                <span class="text-slate-400 dark:text-slate-500">Sem consultor</span>
                            @endif
                        </td>
                        <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $p->created_at->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
