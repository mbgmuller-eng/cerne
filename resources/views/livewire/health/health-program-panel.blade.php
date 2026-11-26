<section class="card min-w-0 space-y-4 p-5" data-programa>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="eyebrow">Programa de atividades</p>
        <div data-write class="flex flex-wrap gap-2">
            <button type="button" wire:click="open('program')" class="btn-ghost px-2 py-1.5 text-xs">Orientação</button>
            <button type="button" wire:click="open('activity')" class="btn-secondary px-3 py-1.5 text-xs">+ Atividade</button>
        </div>
    </div>

    <div class="rounded-xl bg-saude-200/20 p-3 text-sm text-slate-700 dark:bg-saude-800/30 dark:text-slate-200">
        @if ($acompanhamento->guided_by || $acompanhamento->guided_on)
            <p>Orientado por {{ $acompanhamento->guided_by ?: 'profissional não informado' }}@if ($acompanhamento->guided_on) em {{ $acompanhamento->guided_on->format('d/m/Y') }}@endif.</p>
        @endif
        @if ($acompanhamento->avoid)
            <p class="whitespace-pre-line"><strong>Evitar:</strong> {{ $acompanhamento->avoid }}</p>
        @endif
        <p class="text-xs text-slate-500 dark:text-slate-400">Registre o que o profissional orientou. O Cerne organiza, lembra e mede: ele não escolhe exercícios para um problema de saúde.</p>
    </div>

    @if ($panel === 'program')
        <form wire:submit="saveProgram" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                <x-health.professional-field model="guidedBy" label="Quem orientou" :field="$campoProfissional" placeholder="Ex.: Fisioterapeuta Marina" maxlength="160" />
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quando</label>
                    <input type="date" wire:model="guidedOn" class="input mt-1.5">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que evitar</label>
                <textarea wire:model="avoid" rows="2" class="input mt-1.5"></textarea>
            </div>
            <x-form-consequence :lines="['A orientação aparece no alto do programa e no PDF para o médico.']" />
            <x-form-errors />
            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar orientação</button>
        </form>
    @endif

    @if ($panel === 'activity')
        <form wire:submit="saveActivity" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model.live="kind" class="select mt-1.5 w-full">
                        @foreach ($tipos as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome (opcional)</label>
                    <input type="text" wire:model="activityName" class="input mt-1.5" placeholder="Fica o nome do tipo">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vezes por semana</label>
                    <input type="number" min="1" max="14" wire:model="perWeek" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Total de sessões (opcional)</label>
                    <input type="number" min="1" max="500" wire:model="totalSessions" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Quando há um número fechado, como 10 sessões de fisioterapia.</p>
                </div>
            </div>
            @if ($kind === 'gym' && $fases->isNotEmpty())
                <div wire:key="fase-da-academia">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Fase da sua Academia (opcional)</label>
                    <select wire:model="gymPlanId" class="select mt-1.5 w-full">
                        <option value="">Não ligar</option>
                        @foreach ($fases as $f)
                            <option value="{{ $f->id }}">{{ $f->name }}{{ $f->is_active ? ' (em uso)' : '' }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Ao terminar um treino dessa fase, a presença entra aqui e o Cerne pede a nota de dor. Cargas e séries continuam só suas.</p>
                </div>
            @endif
            <x-form-consequence :lines="['A atividade passa a receber exercícios e presenças. Quem vê a Agenda de Saúde desta pessoa vê o programa.']" />
            <x-form-errors />
            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar atividade</button>
        </form>
    @endif

    @if ($atividades->isEmpty())
        <p class="text-xs text-slate-400" data-sem-atividades>Nenhuma atividade. Acrescente depois que um profissional orientar o que fazer.</p>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach ($atividades as $a)
                <button type="button" wire:click="selectActivity('{{ $a->id }}')" wire:key="atv-{{ $a->id }}" aria-pressed="{{ $escolhida?->id === $a->id ? 'true' : 'false' }}"
                    @class(['rounded-xl border px-3 py-2 text-left', 'border-saude-800 ring-1 ring-saude-800 dark:border-saude-200 dark:ring-saude-200' => $escolhida?->id === $a->id, 'border-slate-200 dark:border-white/10' => $escolhida?->id !== $a->id, 'opacity-60' => ! $a->is_active])>
                    <span class="block text-sm font-medium text-slate-800 dark:text-slate-200">{{ $a->name }}</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $a->per_week }}x por semana · {{ $a->total_sessions ? $a->presencas.' de '.$a->total_sessions.' sessões' : $a->presencas.($a->presencas === 1 ? ' presença' : ' presenças') }}{{ $a->is_active ? '' : ' · encerrada' }}</span>
                </button>
            @endforeach
        </div>

        @if ($escolhida)
            <div class="space-y-4 rounded-xl border border-slate-100 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $escolhida->name }} <x-registered-by :model="$escolhida" /></h3>
                        @if ($escolhida->gymPlan)
                            <p class="text-xs text-slate-500 dark:text-slate-400">Ligada à sua Academia: {{ $escolhida->gymPlan->name }}.</p>
                        @endif
                    </div>
                    <button type="button" data-write wire:click="open('session')" class="btn-primary px-3 py-1.5 text-sm">Registrar presença</button>
                </div>

                @if ($panel === 'session')
                    <form wire:submit="saveSession" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                                <input type="date" wire:model="sessionDate" class="input mt-1.5">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Presença</label>
                                <select wire:model.live="sessionPresence" class="select mt-1.5 w-full"><option value="yes">Fui / fez</option><option value="no">Faltou</option></select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observação (opcional)</label>
                                <input type="text" wire:model="sessionNote" class="input mt-1.5">
                            </div>
                        </div>
                        @if ($sessionPresence === 'yes')
                            <div wire:key="dor-da-presenca">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Dor de 0 a 10</p>
                                @include('livewire.health.partials.pain-scale', ['modelo' => 'sessionPain', 'valor' => $sessionPain])
                            </div>
                        @endif
                        <x-form-consequence :lines="$consequencia" />
                        <x-form-errors />
                        <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar presença</button>
                    </form>
                @endif

                @if ($painFor)
                    <div class="space-y-2 rounded-xl border border-amber-300 p-3 dark:border-amber-500/40" data-pedir-dor>
                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Como ficou a dor neste treino?</p>
                        @include('livewire.health.partials.pain-scale', ['modelo' => 'painValue', 'valor' => $painValue])
                        @error('painValue') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-3">
                            <button type="button" wire:click="savePain" class="btn-primary px-3 py-1.5 text-sm">Salvar nota</button>
                            <button type="button" wire:click="$set('painFor', null)" class="text-xs text-slate-400">Agora não</button>
                        </div>
                    </div>
                @endif

                <div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="eyebrow">Exercícios</p>
                        <button type="button" data-write wire:click="open('exercise')" class="btn-ghost px-2 py-1 text-xs">+ Exercício</button>
                    </div>
                    @if ($escolhida->exercises->isEmpty())
                        <p class="mt-1 text-xs text-slate-400">Nenhum exercício. A presença e a nota de dor funcionam mesmo sem lista.</p>
                    @else
                        <ul class="mt-1 divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($escolhida->exercises as $e)
                                <li class="flex items-start justify-between gap-3 py-2" wire:key="ex-{{ $e->id }}">
                                    <div class="min-w-0">
                                        <p class="text-sm text-slate-800 dark:text-slate-200">{{ $e->name }}@if ($e->dose) <span class="text-slate-500 dark:text-slate-400">· {{ $e->dose }}</span>@endif</p>
                                        @if ($e->note) <p class="text-xs text-slate-500 dark:text-slate-400">{{ $e->note }}</p> @endif
                                        <x-gym.instructions :exercise="$e" />
                                    </div>
                                    <button type="button" data-write wire:click="removeExercise('{{ $e->id }}')" class="shrink-0 text-xs text-slate-400 hover:text-red-700">remover</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($panel === 'exercise')
                        <form wire:submit="saveExercise" class="mt-2 space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Exercício</label>
                                    <input type="text" wire:model="exerciseName" class="input mt-1.5" placeholder="Digite o nome">
                                    <p class="mt-1 text-xs text-slate-400">Se o nome existir na biblioteca da Academia, o passo a passo aparece junto.</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observação (opcional)</label>
                                    <input type="text" wire:model="exerciseNote" class="input mt-1.5" placeholder="Carga, cuidado">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Séries</label>
                                    <input type="text" inputmode="numeric" wire:model="exerciseSets" class="input mt-1.5" placeholder="3">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Repetições ou tempo</label>
                                    <input type="text" wire:model="exerciseReps" class="input mt-1.5" placeholder="12 ou 30 s">
                                </div>
                            </div>
                            <x-form-errors />
                            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Adicionar exercício</button>
                        </form>
                    @endif
                </div>

                <div>
                    <p class="eyebrow">Presenças</p>
                    @if ($presencas->isEmpty())
                        <p class="mt-1 text-xs text-slate-400">Nenhuma presença registrada.</p>
                    @else
                        <div class="mt-1 overflow-x-auto">
                            <table class="w-full text-sm" data-presencas>
                                <thead><tr class="text-left text-xs text-slate-500 dark:text-slate-400"><th class="py-1 pr-3 font-medium">Data</th><th class="py-1 pr-3 font-medium">Presença</th><th class="py-1 pr-3 font-medium">Dor</th><th class="py-1 pr-3 font-medium">Observação</th><th></th></tr></thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                    @foreach ($presencas as $s)
                                        <tr wire:key="ses-{{ $s->id }}">
                                            <td class="whitespace-nowrap py-1.5 pr-3">{{ $s->done_on->format('d/m/Y') }}</td>
                                            <td class="py-1.5 pr-3"><span @class(['whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300' => $s->attended, 'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300' => ! $s->attended])>{{ $s->attended ? 'Presente' : 'Faltou' }}</span></td>
                                            <td class="py-1.5 pr-3 tabular-nums">
                                                @if ($s->attended && $s->pain === null)
                                                    <button type="button" data-write wire:click="askPain('{{ $s->id }}')" class="whitespace-nowrap text-xs font-medium text-amber-700 hover:underline dark:text-amber-400">informar</button>
                                                @else
                                                    {{ $s->pain ?? '-' }}
                                                @endif
                                            </td>
                                            <td class="py-1.5 pr-3 text-xs text-slate-500 dark:text-slate-400">{{ $s->gym_session_id ? 'Treino da Academia. ' : '' }}{{ $s->note }} <x-registered-by :model="$s" /></td>
                                            <td class="py-1.5 text-right"><button type="button" data-write wire:click="deleteSession('{{ $s->id }}')" wire:confirm="Excluir este registro?" class="text-xs text-slate-400 hover:text-red-700">excluir</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div data-write class="flex flex-wrap items-center gap-3 text-xs">
                    <button type="button" wire:click="toggleActivity('{{ $escolhida->id }}')" class="text-slate-500 hover:underline dark:text-slate-400">{{ $escolhida->is_active ? 'Encerrar atividade' : 'Retomar atividade' }}</button>
                    <button type="button" wire:click="deleteActivity('{{ $escolhida->id }}')" wire:confirm="Excluir a atividade, os exercícios e as presenças dela?" class="text-slate-400 hover:text-red-700">Excluir atividade</button>
                </div>
            </div>
        @endif

        @if ($semanas !== [])
            <div data-evolucao>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0"><p class="eyebrow">Sessões por semana</p>{!! $graficoSessoes !!}</div>
                    <div class="min-w-0"><p class="eyebrow">Dor média da semana (0 a 10)</p>{!! $graficoDor !!}</div>
                </div>
                <p class="mt-1 text-xs text-slate-400">Todas as atividades do programa juntas, nas últimas 8 semanas.</p>
                <details class="mt-1">
                    <summary class="cursor-pointer text-xs font-medium text-saude-800 dark:text-saude-200">Ver em tabela</summary>
                    <table class="mt-1 w-full text-xs">
                        <thead><tr class="text-left text-slate-500 dark:text-slate-400"><th class="py-1 font-medium">Semana de</th><th class="font-medium">Sessões</th><th class="font-medium">Dor média</th></tr></thead>
                        <tbody>
                            @foreach ($semanas as $s)
                                <tr><td class="py-1">{{ $s['week']->format('d/m/Y') }}</td><td>{{ $s['sessions'] }}</td><td>{{ $s['pain'] === null ? '-' : str_replace('.', ',', (string) $s['pain']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </div>
        @endif
    @endif
</section>
