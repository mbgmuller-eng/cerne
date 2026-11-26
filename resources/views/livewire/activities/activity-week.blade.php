@php
    $cores = ['sport' => 'bg-[var(--chart-1,#2a78d6)]', 'gym' => 'bg-[var(--chart-2,#eb6834)]', 'run' => 'bg-[var(--chart-3,#1baf7a)]', 'other' => 'bg-[var(--chart-4,#eda100)]'];
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
@endphp

<div class="space-y-5">
    <x-activity-header title="Minha semana" :subtitle="$dados['start']->format('d/m').' a '.$dados['start']->addDays(6)->format('d/m/Y')">
        <button type="button" wire:click="previousWeek" class="btn-secondary px-3 py-2" aria-label="Semana anterior">‹</button>
        @unless ($atual)
            <button type="button" wire:click="currentWeek" class="btn-ghost px-3 py-2 text-sm">Esta semana</button>
        @endunless
        <button type="button" wire:click="nextWeek" class="btn-secondary px-3 py-2" aria-label="Próxima semana">›</button>
        <a href="{{ route('health.activities.log') }}" wire:navigate class="btn-primary">+ Registrar</a>
    </x-activity-header>

    @if ($comecando)
        <div class="card space-y-2 border-l-4 border-l-saude-800 p-5" data-comecando>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Comece contando o que você já faz</p>
            <p class="text-sm text-slate-600 dark:text-slate-300">No seu perfil, informe os compromissos fixos, o que pratica sem dia certo e os dias preferidos da academia. A semana é montada a partir disso.</p>
            <a href="{{ route('health.activities.profile') }}" wire:navigate class="btn-primary inline-flex px-3 py-1.5 text-sm">Preencher meu perfil</a>
        </div>
    @endif

    @if ($pendentes > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-revisao-pendente>
            <p>Há {{ $pendentes }} {{ $pendentes === 1 ? 'sugestão' : 'sugestões' }} para o seu treino por causa das limitações. Nada muda sem a sua aprovação.</p>
            <a href="{{ route('health.activities.review') }}" wire:navigate class="btn-primary px-3 py-1.5 text-sm">Revisar treino</a>
        </div>
    @endif

    @if ($semanaDaCorrida)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:bg-white/5 dark:text-slate-300">
            <p>A semana de {{ $semanaDaCorrida['week_start']->format('d/m') }} do programa de corrida está pronta para fechar.</p>
            <a href="{{ route('health.activities.running') }}" wire:navigate class="btn-secondary px-3 py-1.5 text-sm">Fechar a semana</a>
        </div>
    @endif

    <div class="grid grid-cols-3 gap-2 sm:gap-3">
        @foreach ([['Carga feita', number_format($dados['load_done'], 0, ',', '.')], ['Com o previsto', number_format($dados['load_projected'], 0, ',', '.')], ['Média de 4 semanas', $dados['load_average'] !== null ? number_format($dados['load_average'], 0, ',', '.') : 'sem base']] as [$rotuloDaCarga, $valorDaCarga])
            <div class="card flex min-w-0 flex-col justify-between px-3 py-2.5 sm:px-4 sm:py-3"><p class="text-xs text-slate-500 dark:text-slate-400">{{ $rotuloDaCarga }}</p><p class="font-display text-lg font-semibold tabular-nums text-slate-900 sm:text-2xl dark:text-white">{{ $valorDaCarga }}</p></div>
        @endforeach
    </div>
    <p class="-mt-3 text-xs text-slate-400">Carga = minutos × esforço de 0 a 10. Assim esporte, academia e corrida entram na mesma conta.@if ($dados['missing_effort'] > 0) {{ $dados['missing_effort'] === 1 ? 'Falta o esforço de 1 registro' : 'Faltam os esforços de '.$dados['missing_effort'].' registros' }} desta semana.@endif</p>

    @if ($dados['load_above'] !== null)
        <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-carga-alta>A semana está {{ $dados['load_above'] }}% acima da sua média. É um sinal para observar como o corpo responde. Ele não prevê lesão.</p>
    @endif

    @if ($dados['warnings'] !== [])
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-avisos-de-encaixe>
            <p class="font-semibold">Atenção no encaixe</p>
            <ul class="mt-1 space-y-0.5">@foreach ($dados['warnings'] as $aviso)<li>{{ $aviso }}</li>@endforeach</ul>
        </div>
    @endif

    @error('form') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300" data-form-error>{{ $message }}</p> @enderror

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7" data-semana>
        @foreach ($dias as $d => $nomeDoDia)
            @php
                $data = $dados['start']->addDays($d);
                $doDia = $dados['items']->where('weekday', $d);
            @endphp
            <section @class(['card flex min-w-0 flex-col gap-2 p-2.5', 'ring-1 ring-saude-800 dark:ring-saude-200' => $data->isSameDay($hoje)]) wire:key="dia-{{ $data->toDateString() }}">
                <h2 class="text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $nomeDoDia }} {{ $data->format('d/m') }}{{ $data->isSameDay($hoje) ? ' · hoje' : '' }}</h2>
                @forelse ($doDia as $i)
                    <div wire:key="item-{{ $i['ref'] }}" @class(['min-w-0 rounded-lg border px-2.5 py-2 text-xs', 'border-dashed border-slate-300 dark:border-white/20' => $i['status'] === 'planned', 'border-slate-200 dark:border-white/10' => $i['status'] !== 'planned', 'opacity-60' => $i['status'] === 'skipped'])>
                        <p class="break-words font-semibold text-slate-800 dark:text-slate-100"><span class="mr-1 inline-block h-2 w-2 rounded-sm {{ $cores[$i['type']->chartGroup()] }}"></span>{{ $i['name'] }}</p>
                        @if ($i['detail']) <p class="text-slate-500 dark:text-slate-400">{{ $i['detail'] }}</p> @endif
                        @if ($i['status'] === 'done')
                            <p class="text-slate-600 dark:text-slate-300">{{ $i['minutes'] }} min · {{ $i['effort'] !== null ? 'esforço '.$i['effort'] : 'sem esforço' }}{{ $i['pain'] !== null ? ' · dor '.$i['pain'] : '' }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-medium text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300">Feito</span>
                                <button type="button" wire:click="openEdit('{{ $i['record_id'] }}')" class="text-slate-500 underline dark:text-slate-400">{{ $i['effort'] === null ? 'informar esforço' : 'corrigir' }}</button>
                            </div>
                        @elseif ($i['status'] === 'skipped')
                            <p class="text-slate-500 line-through">Não feito</p>
                            <button type="button" wire:click="undoSkip('{{ $i['record_id'] }}')" class="text-slate-500 underline dark:text-slate-400">desfazer</button>
                        @else
                            <p class="text-slate-500 dark:text-slate-400">{{ $i['minutes'] }} min · previsto{{ $i['fixed'] ? ' · fixo' : '' }}</p>
                            @if ($i['movable'])
                                <label class="sr-only" for="mover-{{ md5($i['ref']) }}">Mudar o dia de {{ $i['name'] }}</label>
                                <select id="mover-{{ md5($i['ref']) }}" wire:change="move('{{ $i['ref'] }}', $event.target.value)" class="select mt-1 w-full py-1 text-xs">
                                    @foreach ($dias as $k => $opcao) <option value="{{ $k }}" @selected($k === $i['weekday'])>{{ $opcao }}</option> @endforeach
                                </select>
                            @endif
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                @if ($data->lte($hoje))
                                    <button type="button" wire:click="openDone('{{ $i['ref'] }}')" class="btn-primary px-2.5 py-1 text-xs">Fiz</button>
                                @endif
                                <button type="button" wire:click="skip('{{ $i['ref'] }}')" class="text-slate-500 underline dark:text-slate-400">não {{ $data->lte($hoje) ? 'fiz' : 'vou' }}</button>
                                @if ($i['workout_id'])
                                    <a href="{{ route('health.gym.workout', $i['workout_id']) }}" wire:navigate class="text-saude-800 underline dark:text-saude-200">ver treino</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-400">Descanso</p>
                @endforelse
            </section>
        @endforeach
    </div>

    @if ($item)
        {{-- Abre abaixo da semana: no celular ficaria fora da tela, como se o botão não fizesse nada. --}}
        <form wire:submit="saveDone" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))" class="card space-y-4 border border-saude-800 p-5 dark:border-saude-200" wire:key="fiz-{{ md5($doneRef) }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Como foi: {{ $item['name'] }}</h2>
                <button type="button" wire:click="cancel" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="max-w-xs"><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model.live.blur="minutes" class="input mt-1.5"></div>
            <x-scale-input model="effort" :value="$effort" label="Esforço de 0 a 10" />
            <x-scale-input model="pain" :value="$pain" label="Dor de 0 a 10 (opcional)" clear />
            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
        </form>
    @endif

    @if ($editando)
        <form wire:submit="saveEdit" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))" class="card space-y-4 border border-saude-800 p-5 dark:border-saude-200" wire:key="corrigir-{{ $editando->id }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Corrigir: {{ $editando->name() }} em {{ $editando->done_on->format('d/m') }}</h2>
                <button type="button" wire:click="cancel" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="max-w-xs"><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model="minutes" class="input mt-1.5"></div>
            <x-scale-input model="effort" :value="$effort" label="Esforço de 0 a 10" clear />
            <x-scale-input model="pain" :value="$pain" label="Dor de 0 a 10 (opcional)" clear />
            <x-form-errors />
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar alterações</button>
                @if ($editando->gym_session_id === null)
                    <button type="button" wire:click="deleteRecord('{{ $editando->id }}')" wire:confirm="Excluir este registro?" class="text-xs text-slate-400 hover:text-red-700">Excluir registro</button>
                @else
                    <span class="text-xs text-slate-400">Vem do treino feito na Academia.</span>
                @endif
            </div>
        </form>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card min-w-0 space-y-3 p-5">
            <p class="eyebrow">Sem dia certo</p>
            @forelse ($dados['flexible'] as $f)
                <p class="text-sm text-slate-700 dark:text-slate-300">{{ $f['name'] }}: {{ $f['count'] }} de {{ $f['expected'] }} que você costuma fazer por semana.</p>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">Nada cadastrado sem dia certo.</p>
            @endforelse
            <button type="button" wire:click="openPlan" class="btn-secondary px-3 py-1.5 text-sm">Marcar uma atividade</button>
            @if ($planning)
                <form wire:submit="savePlan" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label class="{{ $campo }}">Atividade</label><select wire:model.live="planType" class="select mt-1.5 w-full">@foreach ($tipos as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
                        <div><label class="{{ $campo }}">Dia</label><input type="date" wire:model="planDay" class="input mt-1.5"></div>
                        @if ($planType === 'other')
                            <div class="sm:col-span-2" wire:key="nome-da-marcacao"><label class="{{ $campo }}">Nome da atividade</label><input type="text" wire:model="planName" class="input mt-1.5" maxlength="60"></div>
                        @endif
                        <div><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model="planMinutes" class="input mt-1.5"></div>
                    </div>
                    <x-scale-input model="planEffort" :value="$planEffort" label="Esforço esperado de 0 a 10" />
                    <x-form-consequence :lines="$consequenciaPlano" />
                    <x-form-errors />
                    <div class="flex gap-3"><button type="submit" class="btn-primary px-3 py-1.5 text-sm">Marcar</button><button type="button" wire:click="cancel" class="text-xs text-slate-400">Cancelar</button></div>
                </form>
            @endif
        </div>
        <div class="card min-w-0 space-y-2 p-5">
            <p class="eyebrow">Regras de encaixe</p>
            <ul class="list-disc space-y-1 pl-4 text-sm text-slate-700 dark:text-slate-300">
                <li>Evitar treino de pernas na véspera de um esporte.</li>
                <li>Manter pelo menos um dia de descanso.</li>
                <li>Evitar corrida no dia seguinte às pernas e em dias seguidos.</li>
            </ul>
            <p class="text-xs text-slate-400">O Cerne avisa e sugere. Ele não move nada sozinho.</p>
        </div>
    </div>
</div>
