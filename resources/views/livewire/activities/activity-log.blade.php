@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $pilula = fn (bool $on) => 'rounded-full border px-3 py-1.5 text-sm '.($on ? 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-slate-900' : 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-300');
@endphp

<div class="space-y-5">
    <x-activity-header title="Registrar atividade" subtitle="O que você fez: esporte, caminhada, corrida, natação ou o que mais quiser acompanhar." />

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="card min-w-0 space-y-4 p-5">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Como registrar">
                <button type="button" wire:click="setMode('one')" aria-pressed="{{ $mode === 'one' ? 'true' : 'false' }}" class="{{ $pilula($mode === 'one') }}">Uma atividade</button>
                <button type="button" wire:click="setMode('many')" aria-pressed="{{ $mode === 'many' ? 'true' : 'false' }}" class="{{ $pilula($mode === 'many') }}">Vários dias de uma vez</button>
            </div>

            <div>
                <p class="{{ $campo }}">O que foi</p>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @foreach ($tipos as $valor => $rotulo)
                        <button type="button" wire:click="$set('type', '{{ $valor }}')" aria-pressed="{{ $type === $valor ? 'true' : 'false' }}" class="{{ $pilula($type === $valor) }}">{{ $rotulo }}</button>
                    @endforeach
                </div>
            </div>

            @if ($type === 'other')
                <div wire:key="nome-da-atividade">
                    <label class="{{ $campo }}">Nome da atividade</label>
                    <input type="text" wire:model.live.blur="customName" class="input mt-1.5" maxlength="60" placeholder="Ex.: beach tennis">
                </div>
            @endif

            @if ($mode === 'one')
                <form wire:submit="save" class="space-y-4" wire:key="registro-unico">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="{{ $campo }}">Data</label><input type="date" wire:model.live="date" class="input mt-1.5"></div>
                        <div><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model.live.blur="minutes" class="input mt-1.5"></div>
                        @if ($comDistancia)
                            <div wire:key="distancia"><label class="{{ $campo }}">Distância em km (opcional)</label><input type="text" inputmode="decimal" wire:model="distance" class="input mt-1.5" placeholder="5,2"></div>
                        @endif
                        <div><label class="{{ $campo }}">Batimento médio (opcional)</label><input type="text" inputmode="numeric" wire:model="heartRate" class="input mt-1.5"></div>
                    </div>
                    <x-scale-input model="effort" :value="$effort" label="Esforço de 0 a 10" hint="0 é parado e 10 é o máximo que você aguenta." clear />
                    <x-scale-input model="pain" :value="$pain" label="Dor de 0 a 10 (opcional)" clear />
                    <div><label class="{{ $campo }}">Observação (opcional)</label><input type="text" wire:model="note" class="input mt-1.5" maxlength="500"></div>
                    <x-form-consequence :lines="$consequencia" />
                    <x-form-errors />
                    @if ($confirmDuplicate)
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-repetido>Já existe um registro de {{ mb_strtolower($nome) }} neste dia. Toque de novo em salvar para registrar mesmo assim.</p>
                    @endif
                    <button type="submit" class="btn-primary px-4 py-2 text-sm">{{ $confirmDuplicate ? 'Salvar mesmo assim' : 'Salvar atividade' }}</button>
                </form>
            @else
                <div class="space-y-4" wire:key="registro-em-lote">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label class="{{ $campo }}">De</label><input type="date" wire:model.live="from" class="input mt-1.5"></div>
                        <div><label class="{{ $campo }}">Até</label><input type="date" wire:model.live="until" class="input mt-1.5"></div>
                        <div><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model.live.blur="minutes" class="input mt-1.5"></div>
                    </div>
                    <fieldset>
                        <legend class="{{ $campo }}">Dias da semana</legend>
                        <div class="mt-1.5 flex flex-wrap gap-3">
                            @foreach ($dias as $d => $nomeDoDia)
                                <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="weekdays" value="{{ $d }}" class="h-4 w-4 rounded border-slate-300"> {{ $nomeDoDia }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <x-scale-input model="effort" :value="$effort" label="Esforço habitual de 0 a 10" clear />
                    <button type="button" wire:click="buildDays" class="btn-secondary px-4 py-2 text-sm">Ver os dias</button>

                    @if ($days !== null)
                        @if ($days === [])
                            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum dia nesse período.</p>
                        @else
                            <div data-dias-do-lote>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Desmarque os dias em que não aconteceu</p>
                                <p class="text-xs text-slate-400">Os que já têm registro vêm desmarcados. A lista vai até {{ \App\Services\Activities\ActivityService::MAX_BATCH }} dias.</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($days as $data => $entra)
                                        <button type="button" wire:key="lote-{{ $data }}" wire:click="toggleDay('{{ $data }}')" aria-pressed="{{ $entra ? 'true' : 'false' }}" class="{{ $pilula($entra) }} tabular-nums">{{ \Carbon\Carbon::parse($data)->format('d/m') }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <x-form-consequence :lines="$consequenciaLote" />
                            <button type="button" wire:click="saveDays" class="btn-primary px-4 py-2 text-sm">Salvar {{ count(array_filter($days)) }} registros</button>
                        @endif
                    @endif
                    <x-form-errors />
                </div>
            @endif
        </div>

        <div class="card min-w-0 space-y-2 self-start p-5">
            <p class="eyebrow">Registros recentes</p>
            @error('list') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            @forelse ($registros as $r)
                <div class="flex items-start justify-between gap-3 border-t border-slate-100 py-2 first:border-t-0 dark:border-white/10" wire:key="reg-{{ $r->id }}">
                    <div class="min-w-0">
                        <p class="break-words text-sm font-medium text-slate-800 dark:text-slate-200">{{ $r->name() }} · {{ $r->done_on->format('d/m/Y') }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $r->minutes }} min · {{ $r->effort !== null ? 'esforço '.$r->effort : 'sem esforço' }}{{ $r->pain !== null ? ' · dor '.$r->pain : '' }}{{ $r->distance_meters ? ' · '.number_format($r->distance_meters / 1000, 2, ',', '.').' km' : '' }}</p>
                        @if ($r->note) <p class="break-words text-xs text-slate-400">{{ $r->note }}</p> @endif
                    </div>
                    @if ($r->gym_session_id === null)
                        <button type="button" wire:click="delete('{{ $r->id }}')" wire:confirm="Excluir este registro?" class="shrink-0 text-xs text-slate-400 hover:text-red-700">excluir</button>
                    @else
                        <span class="shrink-0 text-xs text-slate-400">da Academia</span>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum registro ainda.</p>
            @endforelse
            @if ($total > $registros->count())
                <button type="button" wire:click="more" class="btn-ghost px-2 py-1 text-xs">Mostrar mais ({{ $total - $registros->count() }})</button>
            @endif
            <p class="text-xs text-slate-400">Para corrigir duração, esforço ou dor, use Minha semana.</p>
        </div>
    </div>
</div>
