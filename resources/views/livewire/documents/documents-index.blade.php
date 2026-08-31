@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Importar PDF</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Extrato, fatura ou apólice — a leitura é automática, mas nada é gravado sem sua revisão.
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 px-4 py-3 text-sm text-brand-900 dark:text-brand-100">
            {{ session('status') }}
        </div>
    @endif

    @unless ($iaConfigurada)
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
            A chave da API não está configurada — os envios ficam na fila até que ela seja definida
            em <code class="rounded bg-amber-100 dark:bg-amber-500/20 px-1">ANTHROPIC_API_KEY</code>.
        </div>
    @endunless

    {{-- Upload ---------------------------------------------------------- --}}
    <form wire:submit="enviar" class="card p-5">
        <div class="grid gap-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Arquivo PDF</label>
                <input
                    type="file"
                    wire:model="arquivo"
                    accept="application/pdf"
                    class="mt-1 block w-full text-sm text-slate-700 dark:text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:px-3 file:py-1.5 file:text-sm file:text-slate-700 dark:file:text-slate-300 hover:file:bg-slate-200 dark:hover:file:bg-slate-600"
                >
                @error('arquivo')
                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                <select wire:model="documentType" class="select mt-1.5">
                    @foreach ($tipos as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="btn-primary"
            >
                <span wire:loading.remove wire:target="enviar">Enviar</span>
                <span wire:loading wire:target="enviar">Enviando…</span>
            </button>
        </div>

        <p class="mt-3 text-xs text-slate-400">
            Até {{ config('cerne.ai.max_upload_mb') }} MB e {{ config('cerne.ai.max_pdf_pages') }} páginas por arquivo.
        </p>
    </form>

    {{-- Revisão --------------------------------------------------------- --}}
    @if ($revisando)
        <div class="rounded-xl border-2 border-brand-700 dark:border-brand-400 bg-white dark:bg-slate-800 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Revisar {{ $revisando->document_type->label() }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ $revisando->original_filename }}
                        @if ($revisando->institution_name) · {{ $revisando->institution_name }} @endif
                        @if ($revisando->competenceLabel()) · {{ $revisando->competenceLabel() }} @endif
                        · vira {{ $revisando->document_type->destination() }}
                    </p>
                </div>
                <button wire:click="fecharRevisao" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Fechar</button>
            </div>

            @if ($revisando->extractionNotes())
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                    <span class="font-medium">Observações da leitura:</span> {{ $revisando->extractionNotes() }}
                </div>
            @endif

            @error('confirmar')
                <p class="mt-3 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror

            @php $itens = $revisando->extractedItems(); @endphp

            @if ($itens === [])
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Nada foi extraído deste documento.</p>
            @else
                <div class="mt-4 max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800 text-xs text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                @foreach (array_keys($itens[0]) as $coluna)
                                    <th class="px-3 py-2 text-left font-medium">{{ str_replace('_', ' ', $coluna) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($itens as $i => $item)
                                <tr class="{{ in_array($i, $aceitos) ? '' : 'opacity-40' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="aceitos" value="{{ $i }}" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
                                    </td>
                                    @foreach ($item as $chave => $valor)
                                        <td class="px-3 py-2 text-slate-700 dark:text-slate-300 {{ in_array($chave, ['valor', 'valor_atual', 'valor_bruto', 'premio']) ? 'text-right tabular-nums' : '' }}">
                                            @if (is_array($valor))
                                                {{ collect($valor)->map(fn ($v) => is_array($v) ? implode(' ', $v) : $v)->implode(', ') }}
                                            @else
                                                {{ $valor ?? '—' }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ count($aceitos) }} de {{ count($itens) }} selecionados
                    </p>
                    <div class="flex gap-2">
                        <button wire:click="descartar('{{ $revisando->id }}')" class="btn-secondary px-3 py-1.5 hover:text-red-700 dark:hover:text-red-400">
                            Descartar documento
                        </button>
                        <button wire:click="confirmar" class="btn-primary py-1.5">
                            Importar selecionados
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Histórico ------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Documentos</h2>

        @if ($documents->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum documento enviado ainda.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($documents as $doc)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $doc->original_filename }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $doc->document_type->label() }}
                                · {{ $doc->created_at->format('d/m/Y H:i') }}
                                @if ($doc->institution_name) · {{ $doc->institution_name }} @endif
                                @if ($doc->records_extracted !== null) · {{ $doc->records_extracted }} itens @endif
                            </p>
                            @if ($doc->error_message)
                                <p class="mt-0.5 text-xs text-red-700 dark:text-red-400">{{ $doc->error_message }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $doc->processing_status->color() === 'stone',
                                'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $doc->processing_status->color() === 'amber',
                                'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300' => $doc->processing_status->color() === 'red',
                                'bg-brand-100 text-brand-900 dark:bg-brand-500/20 dark:text-brand-100' => $doc->processing_status->color() === 'teal',
                            ])>{{ $doc->processing_status->label() }}</span>

                            @if ($doc->isAwaitingReview())
                                <button wire:click="revisar('{{ $doc->id }}')" class="btn-primary px-3 py-1">
                                    Revisar
                                </button>
                            @else
                                <button wire:click="descartar('{{ $doc->id }}')" class="text-sm text-slate-400 hover:text-red-700 dark:hover:text-red-400">
                                    Excluir
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
