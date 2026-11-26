@php
    use App\Support\Health\BodyMass;
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $kg = fn (float $v) => BodyMass::format($v).' kg';
@endphp

<div class="min-w-0 space-y-3" data-medidas>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="eyebrow">Peso e medidas</p>
        @if ($escreve)
            <button type="button" wire:click="startAdding" class="btn-secondary px-3 py-1.5 text-xs">+ Medida</button>
        @endif
    </div>

    @if (! $visivel)
        <p class="text-xs text-slate-400" data-medidas-fechadas>As medidas desta pessoa não são compartilhadas.</p>
    @else
        @if ($atual['weight'] !== null || $atual['height'] !== null)
            <div class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-slate-100 px-3 py-2 dark:border-white/10"><p class="text-xs text-slate-500 dark:text-slate-400">Peso{{ $atual['weight_on'] ? ' em '.$atual['weight_on']->format('d/m/y') : '' }}</p><p class="font-display text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ $atual['weight'] !== null ? $kg($atual['weight']) : '-' }}</p></div>
                <div class="rounded-xl border border-slate-100 px-3 py-2 dark:border-white/10"><p class="text-xs text-slate-500 dark:text-slate-400">Altura</p><p class="font-display text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ $atual['height'] !== null ? $atual['height'].' cm' : '-' }}</p></div>
                <div class="rounded-xl border border-slate-100 px-3 py-2 dark:border-white/10"><p class="text-xs text-slate-500 dark:text-slate-400">IMC</p><p class="font-display text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ $atual['bmi'] !== null ? number_format($atual['bmi'], 1, ',', '') : '-' }}</p><p class="text-xs text-slate-500 dark:text-slate-400">{{ $atual['category'] }}</p></div>
            </div>

            @if ($atual['range'])
                <div class="rounded-xl bg-slate-50 px-3 py-2 dark:bg-white/5" data-faixa-imc>
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Pela tabela do IMC, o peso na faixa considerada saudável para {{ $propria ? 'a sua altura' : 'esta altura' }} fica entre {{ $kg($atual['range'][0]) }} e {{ $kg($atual['range'][1]) }}.</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">O IMC é uma triagem: não separa músculo de gordura. {{ $idoso ? 'Esta é a faixa de referência a partir dos 60 anos.' : 'A partir dos 60 anos a faixa de referência é outra.' }} O cálculo não vale na gravidez. Quem avalia cada caso é o profissional.@if ($atual['age'] === null) Sem a data de nascimento no cadastro, usei a faixa de adultos.@endif</p>
                    <x-rule-status key="bmi" class="mt-1" />
                </div>
            @endif
        @else
            <p class="text-xs text-slate-400">Nenhuma medida ainda. Com o peso e a altura, o Cerne mostra o IMC e a faixa de referência.</p>
        @endif

        @if ($adding)
            <form wire:submit="save" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div><label class="{{ $campo }}">Data</label><input type="date" wire:model="date" class="input mt-1.5"></div>
                    <div><label class="{{ $campo }}">Peso em kg</label><input type="text" inputmode="decimal" wire:model="weight" class="input mt-1.5" placeholder="84,5"></div>
                    <div><label class="{{ $campo }}">Altura em cm</label><input type="text" inputmode="numeric" wire:model="height" class="input mt-1.5" placeholder="172"></div>
                    <div><label class="{{ $campo }}">Cintura em cm (opcional)</label><input type="text" inputmode="numeric" wire:model="waist" class="input mt-1.5"></div>
                </div>
                <x-form-consequence :lines="$consequencia" />
                <x-form-errors />
                <div class="flex gap-3"><button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar medida</button><button type="button" wire:click="$set('adding', false)" class="text-xs text-slate-400">Cancelar</button></div>
            </form>
        @endif

        @if ($grafico !== '')
            <div>
                <p class="text-xs text-slate-400">Os pontos são as pesagens. A linha é a tendência (média das 3 últimas), porque o peso oscila de um dia para outro.</p>
                <div class="mt-1 overflow-x-auto"><div class="min-w-[480px]">{!! $grafico !!}</div></div>
                <details>
                    <summary class="cursor-pointer text-xs font-medium text-saude-800 dark:text-saude-200">Ver em tabela</summary>
                    <div class="mt-1 overflow-x-auto">
                        <table class="w-full text-sm tabular-nums">
                            <thead><tr class="text-left text-xs text-slate-500 dark:text-slate-400"><th class="py-1 pr-3">Data</th><th class="py-1 pr-3">Peso</th><th class="py-1 pr-3">Altura</th><th class="py-1 pr-3">Cintura</th><th></th></tr></thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                @foreach ($historico as $m)
                                    <tr wire:key="medida-{{ $m->id }}"><td class="py-1.5 pr-3">{{ $m->measured_on->format('d/m/Y') }}</td><td class="py-1.5 pr-3">{{ $m->weight() !== null ? $kg($m->weight()) : '-' }}</td><td class="py-1.5 pr-3">{{ $m->height() ? $m->height().' cm' : '-' }}</td><td class="py-1.5 pr-3">{{ $m->waist_cm ? $m->waist_cm.' cm' : '-' }}</td><td class="py-1.5 text-right">@if ($escreve)<button type="button" wire:click="delete('{{ $m->id }}')" wire:confirm="Excluir esta medida?" class="text-xs text-slate-400 hover:text-red-700">excluir</button>@endif</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        @endif

        @if ($propria && ! $cuidado && $atual['weight'] !== null && $atual['range'] !== null)
            <div class="rounded-xl border border-slate-100 p-3 dark:border-white/10" data-meta-de-peso>
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Meta de peso (só você vê)</p>
                @if ($meta)
                    <p class="font-display text-lg font-semibold tabular-nums text-slate-900 dark:text-white">{{ $kg($meta['kg']) }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $atual['weight'] > $meta['kg'] ? 'Faltam '.$kg(round($atual['weight'] - $meta['kg'], 1)).'.' : 'Meta alcançada.' }}@if ($meta['start'] !== null && $meta['start'] > $atual['weight']) Desde que você definiu a meta: {{ $kg(round($meta['start'] - $atual['weight'], 1)) }} a menos.@endif</p>
                    @if ($meta['kg'] > $atual['range'][1]) <p class="text-xs text-slate-400">A sua meta está acima da faixa pelo IMC. Tudo bem como um passo intermediário.</p> @endif
                    <button type="button" wire:click="clearGoal" class="mt-1 text-xs text-slate-500 underline dark:text-slate-400">Tirar a meta</button>
                @elseif (! $settingGoal)
                    <button type="button" wire:click="startGoal" class="mt-1 btn-ghost px-2 py-1 text-xs">Definir uma meta (opcional)</button>
                @endif
                @if ($settingGoal)
                    <form wire:submit="saveGoal" class="mt-2 space-y-2">
                        <div class="max-w-xs"><label class="{{ $campo }}">Minha meta em kg</label><input type="text" inputmode="decimal" wire:model="goal" class="input mt-1.5" placeholder="79"></div>
                        <x-form-consequence :lines="$consequenciaMeta" />
                        <x-form-errors />
                        <div class="flex gap-3"><button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar meta</button><button type="button" wire:click="$set('settingGoal', false)" class="text-xs text-slate-400">Cancelar</button></div>
                    </form>
                @endif
            </div>
        @endif
    @endif

    @if ($escreve)
        <fieldset class="space-y-2 rounded-xl border border-slate-100 p-3 dark:border-white/10" data-o-que-mostrar>
            <legend class="px-1 text-xs font-medium text-slate-500 dark:text-slate-400">O que mostrar (começa tudo desligado)</legend>
            @if (! $cuidado)
                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="share" class="mt-0.5 h-4 w-4 rounded border-slate-300"> <span>Compartilhar peso e medidas com quem vê a Ficha de Saúde<span class="block text-xs text-slate-400">O cônjuge e quem acompanha a saúde passam a ver o histórico. A meta continua só sua.</span></span></label>
            @endif
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="qr" class="mt-0.5 h-4 w-4 rounded border-slate-300"> <span>Mostrar peso e altura no QR de emergência<span class="block text-xs text-slate-400">Pode importar em um atendimento. Vai a última medida, com a data.</span></span></label>
            <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="qrLimitations" class="mt-0.5 h-4 w-4 rounded border-slate-300"> <span>Mostrar as limitações físicas no QR de emergência</span></label>
        </fieldset>
    @endif
</div>
