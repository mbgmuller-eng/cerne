@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $pilula = fn (bool $on) => 'rounded-full border px-4 py-1.5 text-sm '.($on ? 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-slate-900' : 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-300');
    $textos = [
        'missing' => ['Você ainda não respondeu. Sem as respostas, o Cerne não monta o plano da semana nem começa o programa de corrida.', 'amber'],
        'expired' => ['As respostas venceram, ou um problema de saúde que pede cuidado no exercício mudou depois delas. Responda de novo.', 'amber'],
        'pending' => ['Falta informar se você tem liberação médica.', 'amber'],
        'light' => ['Sem liberação médica: o plano fica leve e sem aumento, e o programa de corrida não começa nem avança. Vale conversar com um médico.', 'amber'],
        'cleared' => ['Liberação informada por você: o plano e a corrida podem progredir. Com dor no peito, falta de ar fora do comum ou tontura, pare e procure atendimento.', 'emerald'],
        'ok' => ['Nenhum "sim": o plano e a corrida podem progredir. Se algo mudar na sua saúde, responda de novo.', 'emerald'],
    ];
    [$texto, $tom] = $textos[$situacao];
@endphp

<div class="space-y-5">
    <x-activity-header title="Prontidão" subtitle="Sete perguntas antes de montar um plano ou começar a correr." />

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div @class(['max-w-2xl rounded-xl px-4 py-3 text-sm', 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300' => $tom === 'amber', 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300' => $tom === 'emerald']) data-situacao="{{ $situacao }}">
        <p>{{ $texto }}</p>
        @if ($registro && $situacao !== 'expired')
            <p class="mt-1 text-xs">Respondido em {{ $registro->answered_on->format('d/m/Y') }}@if ($registro->cleared_on) · liberação de {{ $registro->cleared_by }} em {{ $registro->cleared_on->format('d/m/Y') }}@endif.</p>
        @endif
        @if (in_array($situacao, ['ok', 'cleared', 'light'], true))
            <a href="{{ route('health.activities.plan') }}" wire:navigate class="btn-secondary mt-2 inline-flex px-3 py-1.5 text-sm">Ir para o plano da semana</a>
        @endif
    </div>

    <div class="max-w-2xl space-y-1">
        <p class="text-xs text-slate-400">É uma triagem simples, não é uma avaliação médica e não substitui uma.</p>
        <x-rule-status key="readiness" />
    </div>

    <form wire:submit="save" class="card max-w-2xl space-y-4 p-5">
        <ol class="space-y-4">
            @foreach ($perguntas as $chave => $pergunta)
                <li wire:key="pergunta-{{ $chave }}">
                    <p id="p-{{ $chave }}" class="text-sm text-slate-800 dark:text-slate-100">{{ $loop->iteration }}. {{ $pergunta }}</p>
                    <div class="mt-1.5 flex gap-2" role="group" aria-labelledby="p-{{ $chave }}">
                        @foreach (['no' => 'Não', 'yes' => 'Sim'] as $v => $rotulo)
                            <button type="button" wire:click="$set('answers.{{ $chave }}', '{{ $v }}')" aria-pressed="{{ ($answers[$chave] ?? '') === $v ? 'true' : 'false' }}" class="{{ $pilula(($answers[$chave] ?? '') === $v) }}">{{ $rotulo }}</button>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($algumSim)
            <div class="space-y-3 rounded-xl bg-amber-50 p-4 dark:bg-amber-500/10" wire:key="liberacao">
                <p class="text-sm text-amber-900 dark:text-amber-300">Com um "sim", o recomendado é ter a liberação de um médico antes de aumentar o exercício.</p>
                <div class="flex flex-wrap gap-2" role="group" aria-label="Liberação médica">
                    <button type="button" wire:click="$set('clearance', 'yes')" aria-pressed="{{ $clearance === 'yes' ? 'true' : 'false' }}" class="{{ $pilula($clearance === 'yes') }}">Tenho liberação</button>
                    <button type="button" wire:click="$set('clearance', 'no')" aria-pressed="{{ $clearance === 'no' ? 'true' : 'false' }}" class="{{ $pilula($clearance === 'no') }}">Ainda não tenho</button>
                </div>
                @if ($clearance === 'yes')
                    <div class="grid gap-3 sm:grid-cols-2" wire:key="quem-liberou">
                        <label class="{{ $campo }}">Quem liberou
                            <input type="text" wire:model="clearedBy" class="input mt-1" maxlength="160">
                        </label>
                        <label class="{{ $campo }}">Data da liberação
                            <input type="date" wire:model="clearedOn" class="input mt-1" max="{{ now()->toDateString() }}">
                        </label>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">O Cerne guarda o que você informar e não confere a liberação.</p>
                @endif
            </div>
        @endif

        <x-form-consequence :lines="$consequencia" />
        @error('form') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300" data-form-error>{{ $message }}</p> @enderror
        <x-form-errors />
        <button type="submit" class="btn-primary px-4 py-2 text-sm">Guardar respostas</button>
    </form>
</div>
