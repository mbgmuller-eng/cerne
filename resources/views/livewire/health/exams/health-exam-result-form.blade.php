<div class="space-y-6">
    <div>
        <a href="{{ $pedido ? route('health.exams.show', $pedido->id) : route('health.exams.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← {{ $pedido?->title ?? 'Exames' }}</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $leitura ? 'Confira antes de salvar' : 'Digitar resultado' }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $nomeDaPessoa }}@if ($leitura && ! $encerrada) · {{ $leitura->original_filename }}@endif
        </p>
    </div>

    @if ($esperando)
        <section class="card p-8 text-center" wire:poll.5s data-lendo>
            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Lendo o laudo...</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Pode levar alguns minutos. Você pode sair desta tela: avisamos quando terminar.</p>
            <button type="button" data-write wire:click="discard" wire:confirm="Descartar este laudo?" class="mt-4 text-xs text-slate-400 hover:text-red-700">Descartar</button>
        </section>
    @elseif ($encerrada)
        <section class="card p-6 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Este laudo já foi salvo ou descartado.</p>
            <a href="{{ route('health.exams.index') }}" wire:navigate class="btn-secondary mt-3 inline-block px-3 py-2 text-sm">Ver os exames</a>
        </section>
    @elseif (! $podeEscrever)
        <section class="card p-6"><p class="text-sm text-slate-600 dark:text-slate-300">O seu acesso é de leitura: quem registrou ou quem pode editar faz a revisão.</p></section>
    @else
        <form wire:submit="save" class="space-y-4">
            @if ($leitura && $leitura->status === \App\Enums\ProcessingStatus::Failed)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-falha>{{ $leitura->error }} Se salvar aqui, o PDF vai junto.</p>
            @elseif ($leitura)
                <p class="rounded-xl bg-saude-200/30 p-3 text-sm text-slate-700 dark:bg-saude-800/30 dark:text-slate-200">A leitura pode errar. Compare com o PDF, corrija o que for preciso e desmarque o que não quer guardar.{{ $readingNotes !== '' ? ' Observação da leitura: '.$readingNotes : '' }}</p>
            @endif

            <section class="card space-y-3 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    @if ($pessoas->count() > 1)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é o resultado</label>
                            <select wire:model.live="memberId" class="select mt-1.5 w-full">
                                @foreach ($pessoas as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo de resultado</label>
                        <select wire:model.live="kind" class="select mt-1.5 w-full">
                            <option value="values">Com valores (sangue, urina)</option>
                            <option value="report">Com conclusão (imagem, coração)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data da coleta ou do exame</label>
                        <input type="date" wire:model="performedOn" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Laboratório ou clínica (opcional)</label>
                        <input type="text" wire:model="lab" class="input mt-1.5">
                    </div>
                    <x-health.professional-field model="doctor" label="Médico do laudo (opcional)" :field="$campoProfissional" maxlength="160" />
                </div>
            </section>

            @if ($kind === 'report')
                <section class="card space-y-3 p-5" wire:key="campos-do-laudo">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do exame</label>
                            <input type="text" wire:model="examName" class="input mt-1.5">
                            <p class="mt-1 text-xs text-slate-400">Use o mesmo nome dos anteriores para comparar.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Medida em destaque (opcional)</label>
                            <input type="text" wire:model="highlight" class="input mt-1.5" placeholder="Ex.: Fração de ejeção: 62%">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conclusão do laudo</label>
                        <textarea wire:model="conclusion" rows="5" class="input mt-1.5"></textarea>
                        <p class="mt-1 text-xs text-slate-400">Como está escrita no laudo, sem resumir.</p>
                    </div>
                </section>
            @else
                <section class="card space-y-3 p-5" wire:key="campos-dos-valores">
                    <p class="eyebrow">Resultados</p>
                    @foreach ($values as $i => $v)
                        @php $fora = \App\Support\Health\ReferenceRange::flag($v['value'], $v['reference']); @endphp
                        <div @class(['space-y-2 rounded-xl border p-3', 'border-amber-400 dark:border-amber-500/50' => $v['doubt'] !== '', 'border-slate-100 dark:border-white/10' => $v['doubt'] === '', 'opacity-50' => ! $v['on']]) wire:key="valor-{{ $i }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                    <input type="checkbox" wire:model.live="values.{{ $i }}.on" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"> guardar
                                </label>
                                <span class="flex items-center gap-3">
                                    @if ($fora !== '')
                                        <span class="text-xs font-semibold text-orange-700 dark:text-orange-300" data-fora>{{ \App\Support\Health\ReferenceRange::flagLabel($fora) }}</span>
                                    @endif
                                    <button type="button" wire:click="removeRow({{ $i }})" class="text-xs text-slate-400 hover:text-red-700">remover</button>
                                </span>
                            </div>
                            @if ($v['doubt'] !== '')
                                <p class="text-xs text-amber-800 dark:text-amber-300"><strong>Confira:</strong> {{ $v['doubt'] }}</p>
                            @endif
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)]">
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Resultado de</label>
                                    <input type="text" wire:model="values.{{ $i }}.name" class="input mt-1" placeholder="Ex.: LDL">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor</label>
                                    <input type="text" wire:model.blur="values.{{ $i }}.value" class="input mt-1">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Unidade</label>
                                    <input type="text" wire:model="values.{{ $i }}.unit" class="input mt-1" placeholder="mg/dL">
                                </div>
                                <div class="col-span-2 sm:col-span-1">
                                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Referência do laudo</label>
                                    <input type="text" wire:model.blur="values.{{ $i }}.reference" class="input mt-1" placeholder="70 a 99">
                                </div>
                            </div>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addRow" class="btn-ghost px-2 py-1 text-xs">+ Resultado</button>
                    <p class="text-xs text-slate-400">O destaque aparece quando a referência é uma faixa simples, como “70 a 99” ou “menor que 130”. Linha sem valor não é guardada.</p>
                </section>
            @endif

            @if ($itensAbertos->isNotEmpty())
                <section class="card space-y-2 p-5" data-itens-do-pedido>
                    <p class="eyebrow">Exames do pedido que este resultado resolve</p>
                    @foreach ($itensAbertos as $item)
                        <label class="flex items-start gap-3 text-sm text-slate-800 dark:text-slate-200" wire:key="resolve-{{ $item->id }}">
                            <input type="checkbox" wire:model.live="itemIds" value="{{ $item->id }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            <span class="min-w-0">{{ $item->name }}</span>
                        </label>
                    @endforeach
                    <p class="text-xs text-slate-400">Os que ficarem desmarcados continuam aguardando resultado.</p>
                </section>
            @endif

            <x-form-consequence :lines="$consequencia" />
            @error('form') <p class="text-sm text-red-700 dark:text-red-400" data-form-error>{{ $message }}</p> @enderror
            <x-form-errors />
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar resultado</button>
                @if ($leitura)
                    <button type="button" wire:click="discard" wire:confirm="Descartar este laudo? O arquivo será apagado." class="btn-ghost px-3 py-2 text-sm">Descartar</button>
                @endif
            </div>
        </form>
    @endif
</div>
