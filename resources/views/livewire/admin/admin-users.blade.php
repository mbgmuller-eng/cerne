@use('App\Enums\ProfileType')
@use('App\Enums\UserRole')

<div class="space-y-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Painel admin</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalUsuarios }} {{ $totalUsuarios === 1 ? 'conta' : 'contas' }} · {{ $totalPerfis }} {{ $totalPerfis === 1 ? 'perfil' : 'perfis' }} na plataforma
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

    @if ($this->exclusaoInfo)
        <div class="card space-y-4 border border-red-200 p-5 dark:border-red-500/30">
            <div>
                <p class="text-sm font-semibold text-red-700 dark:text-red-400">Excluir conta de {{ $this->exclusaoInfo['nome'] }}</p>

                @if ($this->exclusaoInfo['tipo'] === 'dono_de_perfil')
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                        Isso apaga <strong>permanentemente</strong> o perfil "{{ $this->exclusaoInfo['perfil']->profile_name }}"
                        e tudo que está nele: {{ $this->exclusaoInfo['despesas'] }} despesas, {{ $this->exclusaoInfo['receitas'] }} receitas,
                        {{ $this->exclusaoInfo['contas'] }} contas bancárias e {{ $this->exclusaoInfo['investimentos'] }} investimentos.
                        @if ($this->exclusaoInfo['membros'] > 1)
                            O perfil tem {{ $this->exclusaoInfo['membros'] }} membros — o login de quem não é o titular continua existindo, só sem perfil nenhum.
                        @endif
                        Não tem como desfazer.
                    </p>
                @elseif ($this->exclusaoInfo['tipo'] === 'consultor')
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                        Remove o acesso de consultor e os {{ $this->exclusaoInfo['clientes_vinculados'] }} vínculos com clientes —
                        os dados dos clientes não são afetados. Não tem como desfazer.
                    </p>
                @else
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                        Essa conta não tem perfil próprio — a exclusão só remove o login. Não tem como desfazer.
                    </p>
                @endif
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                    Pra confirmar, digite o e-mail exatamente: <span class="font-mono">{{ $this->exclusaoInfo['email'] }}</span>
                </label>
                <input type="text" wire:model="confirmacaoExclusao" class="input mt-1.5" autocomplete="off">
                @error('confirmacaoExclusao') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2">
                <button type="button" wire:click="excluirConta" class="inline-flex items-center justify-center rounded-xl bg-red-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50" wire:loading.attr="disabled">Excluir permanentemente</button>
                <button type="button" wire:click="cancelarExclusao" class="btn-secondary">Cancelar</button>
            </div>
        </div>
    @endif

    <div class="space-y-4">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Consultores e clientes</h2>

        @forelse ($grupos as $grupo)
            <div class="card overflow-hidden p-0">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3 dark:border-white/10 dark:bg-white/5">
                    <div>
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">
                            {{ $grupo['consultor']->name }}
                            @if ($grupo['consultor']->isPlatformAdmin())
                                <span class="badge bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20 ml-1.5">admin</span>
                            @endif
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $grupo['consultor']->email }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="badge bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-300 dark:ring-brand-500/20">
                            {{ $grupo['perfis']->count() }} {{ $grupo['perfis']->count() === 1 ? 'cliente' : 'clientes' }}
                        </span>
                        @unless ($grupo['consultor']->id === auth()->id())
                            <button type="button" wire:click="pedirExclusao('{{ $grupo['consultor']->id }}')" class="text-sm text-red-700 hover:underline dark:text-red-400">Excluir</button>
                        @endunless
                    </div>
                </div>

                @include('livewire.admin._perfis-table', ['perfis' => $grupo['perfis'], 'vazio' => 'Nenhum cliente vinculado ainda.'])
            </div>
        @empty
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum consultor cadastrado ainda.</p>
        @endforelse
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Clientes sem consultor</h2>

        <div class="card overflow-hidden p-0">
            @include('livewire.admin._perfis-table', ['perfis' => $perfisSemConsultor, 'vazio' => 'Nenhum cliente sem consultor no momento.'])
        </div>
    </div>

    @if ($outrasContas->isNotEmpty())
        <div class="space-y-2">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Outras contas</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">Sem perfil próprio — cônjuge com login vinculado a um perfil de outra pessoa, ou conta sem papel de consultor.</p>

            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Nome</th>
                            <th class="px-5 py-2 font-medium">E-mail</th>
                            <th class="px-5 py-2 font-medium">Papel</th>
                            <th class="px-5 py-2 font-medium">Criada em</th>
                            <th class="px-5 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($outrasContas as $u)
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
                                <td class="px-5 py-2.5 text-right">
                                    @unless ($u->id === auth()->id())
                                        <button type="button" wire:click="pedirExclusao('{{ $u->id }}')" class="text-sm text-red-700 hover:underline dark:text-red-400">Excluir</button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
