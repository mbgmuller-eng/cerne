<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Médicos e profissionais</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Quem atende ou já atendeu, para usar de novo em consultas e pedidos.</p>
        </div>
        <button type="button" data-write wire:click="newProfessional" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Novo profissional</button>
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-100 px-4 py-2 text-sm text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)] lg:items-start">
        <div class="min-w-0 space-y-3">
            @if ($total > 0)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar por nome ou especialidade</label>
                    <input type="text" wire:model.live.debounce.250ms="search" class="input mt-1.5" placeholder="Ex.: cardio">
                </div>
            @endif
            @forelse ($lista as $p)
                <button type="button" wire:click="select('{{ $p->id }}')" wire:key="pro-{{ $p->id }}" aria-pressed="{{ $escolhido?->id === $p->id ? 'true' : 'false' }}"
                    @class(['card block w-full p-3 text-left', 'ring-2 ring-saude-800 dark:ring-saude-200' => $escolhido?->id === $p->id])>
                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">@if ($p->is_favorite)<span class="text-amber-600 dark:text-amber-400" title="Médico fixo">★</span> @endif{{ $p->name }}</span>
                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $p->specialty ?: 'Especialidade não informada' }} · {{ $p->links->map(fn ($l) => $l->member?->name)->filter()->implode(' e ') }}</span>
                </button>
            @empty
                <section class="card p-5 text-center" data-sem-profissionais>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $total === 0 ? 'Nenhum profissional guardado.' : 'Nada encontrado.' }}</p>
                    @if ($total === 0)
                        <p class="mt-1 text-xs text-slate-400">Ao digitar um nome em uma consulta ou pedido de exame, marque “Guardar este profissional”.</p>
                    @endif
                </section>
            @endforelse

            @if ($sugestoes !== [])
                <section class="card space-y-2 p-4" data-nomes-digitados>
                    <p class="eyebrow">Nomes que você já digitou</p>
                    <ul class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($sugestoes as $s)
                            <li class="flex items-center justify-between gap-2 py-2" wire:key="sug-{{ $s['key'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $s['name'] }}</span>
                                    <span class="block text-xs text-slate-400">{{ $s['where'] }} · {{ $s['person'] }}</span>
                                </span>
                                <button type="button" data-write wire:click="keepTyped('{{ $s['key'] }}')" class="btn-secondary shrink-0 px-3 py-1 text-xs">Guardar</button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="text-xs text-slate-400">Nada é guardado sem você tocar em Guardar.</p>
                </section>
            @endif
        </div>

        @if ($escolhido)
            <section class="card min-w-0 space-y-4 p-5" data-ficha-profissional>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-display text-xl font-semibold text-slate-900 dark:text-white">@if ($escolhido->is_favorite)<span class="text-amber-600 dark:text-amber-400" title="Médico fixo">★</span> @endif{{ $escolhido->name }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([$escolhido->specialty, $escolhido->registry])) ?: 'Especialidade não informada' }} <x-registered-by :model="$escolhido" /></p>
                    </div>
                    @if ($podeEditar)
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="toggleFavorite('{{ $escolhido->id }}')" class="btn-secondary px-3 py-1 text-xs">{{ $escolhido->is_favorite ? 'Tirar dos fixos' : 'Marcar como fixo' }}</button>
                            <button type="button" wire:click="edit('{{ $escolhido->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                        </div>
                    @endif
                </div>

                <div class="space-y-0.5 text-sm text-slate-700 dark:text-slate-200">
                    @forelse (array_filter([$escolhido->place, $escolhido->address, $escolhido->phone]) as $linha)
                        <p class="break-words">{{ $linha }}</p>
                    @empty
                        <p class="text-xs text-slate-400">Sem local nem contato.</p>
                    @endforelse
                    @if ($escolhido->notes)
                        <p class="whitespace-pre-line pt-1">{{ $escolhido->notes }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($escolhido->links as $l)
                        <span class="rounded-full bg-saude-200/40 px-2 py-0.5 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">Atende {{ $l->member?->name }}</span>
                    @endforeach
                </div>

                <div data-write class="flex flex-wrap gap-2">
                    <a href="{{ route('health.appointments.index', ['profissional' => $escolhido->id]) }}" class="btn-primary px-3 py-1.5 text-sm">Marcar consulta</a>
                    <a href="{{ route('health.exams.create', ['profissional' => $escolhido->id]) }}" wire:navigate class="btn-secondary px-3 py-1.5 text-sm">Novo pedido de exames</a>
                </div>

                <div>
                    <p class="eyebrow">Histórico</p>
                    @if ($historico === [])
                        <p class="mt-2 text-xs text-slate-400">Nada registrado com este profissional.</p>
                    @else
                        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10" data-historico-profissional>
                            @foreach ($historico as $h)
                                <li class="py-2">
                                    <a href="{{ $h['url'] }}" class="text-sm font-medium text-slate-800 hover:underline dark:text-slate-200">{{ $h['title'] }}</a>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $h['date']?->format('d/m/Y') ?? 'Sem data' }} · {{ $h['label'] }} · {{ $h['person'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @error('ficha') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

                @if ($podeEditar)
                    <div class="flex flex-wrap items-center gap-3 text-xs">
                        @if ($confirmingDeleteId === $escolhido->id)
                            <span class="text-slate-500 dark:text-slate-400">Consultas e pedidos continuam, com o nome escrito.</span>
                            <button type="button" wire:click="remove('{{ $escolhido->id }}')" class="font-medium text-red-700 hover:underline dark:text-red-400">Excluir mesmo</button>
                            <button type="button" wire:click="$set('confirmingDeleteId', null)" class="text-slate-400">Não</button>
                        @else
                            <button type="button" wire:click="$set('confirmingDeleteId', '{{ $escolhido->id }}')" class="text-slate-400 hover:text-red-700">Excluir da lista</button>
                        @endif
                    </div>
                @endif
            </section>
        @endif
    </div>

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Editar profissional' : 'Novo profissional' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-3 @sm:grid-cols-2 *:min-w-0">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="name" class="input mt-1.5" maxlength="120">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Especialidade</label>
                    <input type="text" wire:model="specialty" class="input mt-1.5" placeholder="Ex.: Cardiologia" maxlength="80">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Registro (opcional)</label>
                    <input type="text" wire:model="registry" class="input mt-1.5" placeholder="CRM, CREFITO..." maxlength="40">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Clínica ou local</label>
                    <input type="text" wire:model="place" class="input mt-1.5" maxlength="160">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Telefone ou contato</label>
                    <input type="text" wire:model="phone" class="input mt-1.5" maxlength="60">
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Endereço</label>
                    <input type="text" wire:model="address" class="input mt-1.5" maxlength="200">
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações</label>
                    <textarea wire:model="professionalNotes" rows="2" class="input mt-1.5" placeholder="Ex.: atende às terças"></textarea>
                </div>
            </div>
            <fieldset class="space-y-2">
                <legend class="text-xs font-medium text-slate-500 dark:text-slate-400">Quem esse profissional atende</legend>
                @foreach ($pessoas as $m)
                    <label class="flex items-center gap-2 text-sm text-slate-800 dark:text-slate-200" wire:key="atende-{{ $m->id }}">
                        <input type="checkbox" wire:model="personIds" value="{{ $m->id }}" class="h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">{{ $m->name }}
                    </label>
                @endforeach
            </fieldset>
            <label class="flex items-start gap-2 text-sm text-slate-800 dark:text-slate-200">
                <input type="checkbox" wire:model="favorite" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>Médico fixo<span class="block text-xs text-slate-400">Fica no topo das listas.</span></span>
            </label>
            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <button type="submit" class="btn-primary w-full px-3 py-2 text-sm">{{ $editingId ? 'Salvar alterações' : 'Salvar profissional' }}</button>
        </form>
    </x-modal>
</div>
