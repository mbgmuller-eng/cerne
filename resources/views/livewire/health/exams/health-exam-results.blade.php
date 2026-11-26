<div class="space-y-6">
    <div>
        <a href="{{ route('health.exams.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Exames</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Comparar resultados</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O destaque é em relação à referência impressa em cada laudo. O Cerne não interpreta: quem avalia é o médico.</p>
    </div>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-100 px-4 py-2 text-sm text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @if ($pessoas->count() > 1)
            <label class="sr-only" for="pessoa-dos-resultados">Pessoa</label>
            <select id="pessoa-dos-resultados" wire:model.live="memberId" class="select">
                @foreach ($pessoas as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        @endif
        @foreach (['valores' => 'Valores', 'laudos' => 'Laudos'] as $chave => $rotulo)
            <button type="button" wire:click="$set('view', '{{ $chave }}')" aria-pressed="{{ $view === $chave ? 'true' : 'false' }}"
                @class(['rounded-full border px-3 py-1.5 text-xs font-medium', 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-saude-800' => $view === $chave, 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-200' => $view !== $chave])>{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($view === 'laudos')
        @if ($laudos === [])
            <section class="card p-6 text-center"><p class="text-sm text-slate-600 dark:text-slate-300">Nenhum laudo com conclusão guardado.</p></section>
        @else
            <section class="card min-w-0 space-y-4 p-5">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Exame">
                    @foreach ($laudos as $chave => $l)
                        <button type="button" wire:click="$set('exam', {{ \Illuminate\Support\Js::from($chave) }})" aria-pressed="{{ $exameEscolhido === $chave ? 'true' : 'false' }}"
                            @class(['rounded-full border px-3 py-1.5 text-xs font-medium', 'border-saude-800 ring-1 ring-saude-800 dark:border-saude-200 dark:ring-saude-200' => $exameEscolhido === $chave, 'border-slate-200 dark:border-white/10' => $exameEscolhido !== $chave])>{{ $l['name'] }}</button>
                    @endforeach
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-historico-laudos>
                    @foreach ($laudos[$exameEscolhido]['reports'] as $r)
                        <article class="min-w-0 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="hist-{{ $r->id }}">
                            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $r->performed_on->format('d/m/Y') }}</h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ implode(' · ', array_filter([$r->lab, $r->doctor])) }}</p>
                            <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $r->conclusion }}</p>
                            @if ($r->highlight)
                                <p class="mt-2 text-sm font-semibold text-slate-900 dark:text-white">{{ $r->highlight }}</p>
                            @endif
                            @if ($r->document)
                                <a href="{{ route('documents.vault.file', $r->document->id) }}" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-brand-800 hover:underline dark:text-brand-300">Abrir o PDF</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    @elseif ($comparacao['columns'] === [])
        <section class="card p-6 text-center" data-sem-valores>
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum resultado com valores guardado.</p>
            <p class="mt-1 text-xs text-slate-400">Envie o PDF de um laudo ou digite os valores em Exames.</p>
        </section>
    @else
        <div class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] xl:items-start">
            <section class="card min-w-0 p-5">
                {{-- No celular nem todas as datas cabem: a tabela abre nas mais recentes, com os nomes parados na esquerda. --}}
                <div class="overflow-x-auto" x-data x-init="$nextTick(() => $el.scrollLeft = $el.scrollWidth)">
                    <table class="w-full text-sm tabular-nums" data-comparacao>
                        <thead>
                            <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                                <th class="sticky left-0 z-10 border-r border-slate-100 bg-white py-2 pr-3 font-medium dark:border-white/10 dark:bg-slate-800">Resultado de</th>
                                @foreach ($comparacao['columns'] as $data)
                                    <th class="whitespace-nowrap py-2 pr-3 font-medium">{{ \Carbon\CarbonImmutable::parse($data)->format('d/m/Y') }}</th>
                                @endforeach
                                <th class="hidden py-2 pl-3 font-medium sm:table-cell">Referência atual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($comparacao['groups'] as $grupo => $linhas)
                                <tr><th colspan="{{ count($comparacao['columns']) + 2 }}" class="pb-1 pt-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400"><span class="sticky left-1">{{ $grupo }}</span></th></tr>
                                @foreach ($linhas as $linha)
                                    <tr wire:key="linha-{{ md5($linha['key']) }}" @class(['bg-saude-200/20 dark:bg-saude-800/30' => $escolhida && $escolhida['key'] === $linha['key']]) @if ($escolhida && $escolhida['key'] === $linha['key']) aria-current="true" @endif>
                                        <td class="sticky left-0 z-10 border-r border-slate-100 bg-white py-2 pl-1 pr-3 align-top dark:border-white/10 dark:bg-slate-800">
                                            <button type="button" wire:click="$set('marker', {{ \Illuminate\Support\Js::from($linha['key']) }})" class="text-left font-medium text-slate-800 underline decoration-slate-300 underline-offset-4 dark:text-slate-100">{{ $linha['name'] }}</button>
                                            <span class="block text-xs text-slate-400">{{ $linha['unit'] }}</span>
                                        </td>
                                        @foreach ($comparacao['columns'] as $data)
                                            @php $c = $linha['cells'][$data] ?? null; @endphp
                                            <td class="py-2 pl-3 pr-1 align-top text-slate-800 dark:text-slate-200">
                                                @if ($c === null)
                                                    <span class="text-slate-300 dark:text-slate-600">-</span>
                                                @else
                                                    {{ $c['value'] }}@if (! $c['comparable']) <span class="text-xs text-slate-400">{{ $c['unit'] }}</span>@endif
                                                    @if ($c['flag'] !== '')
                                                        <span class="block whitespace-nowrap text-xs font-semibold text-orange-700 dark:text-orange-300">{{ $c['flag'] === 'above' ? '▲ acima' : '▼ abaixo' }}</span>
                                                    @endif
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="hidden py-2 pl-3 align-top text-xs text-slate-500 dark:text-slate-400 sm:table-cell">{{ $linha['reference'] !== '' ? $linha['reference'] : '-' }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-400">Toque no nome para ver o gráfico. “Acima” e “abaixo” comparam cada valor com a referência do laudo daquela data.</p>
            </section>

            @if ($escolhida && $evolucao)
                <section class="card min-w-0 space-y-2 p-5" data-evolucao-exame>
                    <h2 class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ $escolhida['name'] }}</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $escolhida['unit'] }}{{ ($evolucao['min'] !== null || $evolucao['max'] !== null) ? ' · faixa sombreada: referência do laudo mais recente ('.$escolhida['reference'].')' : '' }}</p>
                    @if ($grafico !== '')
                        <div class="text-slate-800 dark:text-slate-100">{!! $grafico !!}</div>
                        <p class="text-xs text-slate-400">Ponto vazado: fora da referência do laudo daquela data.</p>
                    @else
                        <p class="text-xs text-slate-400">Não há número para desenhar neste resultado.</p>
                    @endif
                    @if ($evolucao['excluded'] !== [])
                        <p class="text-xs text-amber-800 dark:text-amber-300" data-fora-do-grafico>
                            Fora do gráfico:
                            @foreach ($evolucao['excluded'] as $x)
                                {{ $x['date'] }}: {{ $x['text'] }} ({{ $x['reason'] }}){{ $loop->last ? '.' : ';' }}
                            @endforeach
                        </p>
                    @endif
                </section>
            @endif
        </div>
    @endif
</div>
