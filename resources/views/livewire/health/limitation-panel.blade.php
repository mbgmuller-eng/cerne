@php $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400'; @endphp

<section @class(['min-w-0 space-y-3', 'card p-5' => ! $naFicha]) data-limitacoes>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="eyebrow">Limitações físicas</p>
        <button type="button" data-write wire:click="create" class="btn-secondary px-3 py-1.5 text-xs">+ Limitação</button>
    </div>

    @if ($limitacoes->isEmpty() && ! $open)
        <p class="text-xs text-slate-400">{{ $naFicha ? 'Nenhuma registrada. Vale também o que vem do passado, sem consulta nem exame.' : 'Se este problema limita o que dá para fazer, registre aqui a região e o que evitar. Os programas de atividades passam a levar em conta.' }}</p>
    @endif

    @foreach ($limitacoes as $l)
        <div class="rounded-xl border p-3 {{ $l->isActive() ? 'border-amber-300 dark:border-amber-500/40' : 'border-slate-200 dark:border-white/10' }}" wire:key="limitacao-{{ $l->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $l->regionLabel() }} <x-registered-by :model="$l" /></p>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ $l->status->label() }}</span>
            </div>
            @if ($l->avoidLoads() !== []) <p class="mt-1 text-sm text-slate-700 dark:text-slate-300">Evitar: {{ implode(', ', array_map(fn ($c) => mb_strtolower($c->label()), $l->avoidLoads())) }}.</p> @endif
            @if ($l->guidance) <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $l->guidance }}</p> @endif
            @if ($l->professional || $l->informed_on) <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $l->professional }}@if ($l->professional && $l->informed_on) · @endif{{ $l->informed_on?->format('d/m/Y') }}</p> @endif
            @if ($naFicha) <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ \App\Support\Health\HealthOrigins::labelOf($l) }}@if ($l->since_year) · desde {{ $l->since_year }}@endif</p> @elseif ($l->since_year) <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Desde {{ $l->since_year }}</p> @endif
            @if ($l->runningAnswer()) <p class="text-xs text-slate-500 dark:text-slate-400">Corrida: {{ $l->runningAnswer() === 'yes' ? 'pode' : 'ainda não' }}.</p> @endif
            <button type="button" data-write wire:click="edit('{{ $l->id }}')" class="mt-1 text-xs text-slate-500 underline dark:text-slate-400">editar</button>
        </div>
    @endforeach

    @if ($open)
        <form wire:submit="save" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="{{ $campo }}">Região</label><select wire:model.live="region" class="select mt-1.5 w-full">@foreach ($regioes as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
                <div><label class="{{ $campo }}">Situação</label><select wire:model="status" class="select mt-1.5 w-full">@foreach ($situacoes as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
            </div>
            <fieldset>
                <legend class="{{ $campo }}">O que evitar</legend>
                <div class="mt-1.5 flex flex-wrap gap-3">
                    @foreach ($cargas as $v => $r)
                        <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model="avoid" value="{{ $v }}" class="h-4 w-4 rounded border-slate-300"> {{ $r }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-400">Vem marcado um ponto de partida por precaução. Ajuste: o que o profissional orientar é o que vale.</p>
            </fieldset>
            <div class="grid gap-3 sm:grid-cols-2">
                @if ($naFicha)
                    <div wire:key="origem-da-limitacao"><label class="{{ $campo }}">De onde vem</label><select wire:model="origin" class="select mt-1.5 w-full">@foreach ($origens as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select><p class="mt-1 text-xs text-slate-400">O que é do passado não precisa de consulta nem exame.</p></div>
                @endif
                <div><label class="{{ $campo }}">Desde que ano (opcional)</label><input type="text" inputmode="numeric" wire:model="sinceYear" class="input mt-1.5" maxlength="4"></div>
            </div>
            <div><label class="{{ $campo }}">Orientação recebida (opcional)</label><textarea wire:model="guidance" rows="2" class="input mt-1.5" maxlength="2000"></textarea></div>
            <div class="grid gap-3 sm:grid-cols-2">
                @if ($campoProfissional)
                    <x-health.professional-field model="professional" label="Profissional (opcional)" :field="$campoProfissional" maxlength="160" />
                @endif
                <div><label class="{{ $campo }}">Data da informação</label><input type="date" wire:model="informedOn" class="input mt-1.5"></div>
            </div>
            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary px-3 py-1.5 text-sm">{{ $editingId ? 'Salvar alterações' : 'Salvar limitação' }}</button>
                <button type="button" wire:click="$set('open', false)" class="text-xs text-slate-400">Cancelar</button>
                @if ($editingId)
                    <button type="button" wire:click="delete('{{ $editingId }}')" wire:confirm="Excluir esta limitação? O que a revisão tirou do treino passa a poder voltar." class="text-xs text-slate-400 hover:text-red-700">Excluir</button>
                @endif
            </div>
        </form>
    @endif

    @if ($propria && $limitacoes->isNotEmpty())
        <p><a href="{{ route('health.activities.review') }}" wire:navigate class="text-sm font-medium text-saude-800 underline dark:text-saude-200" data-revisar-treino>Ver o que isso muda no meu treino</a></p>
    @endif
</section>
