@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Importar PDF</h1>
        <p class="mt-1 text-sm text-stone-500">
            Extrato, fatura ou apólice — a leitura é automática, mas nada é gravado sem sua revisão.
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
            {{ session('status') }}
        </div>
    @endif

    @unless ($iaConfigurada)
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            A chave da API não está configurada — os envios ficam na fila até que ela seja definida
            em <code class="rounded bg-amber-100 px-1">ANTHROPIC_API_KEY</code>.
        </div>
    @endunless

    {{-- Upload ---------------------------------------------------------- --}}
    <form wire:submit="upload" class="card p-5">
        <div class="grid gap-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
            <div>
                <label class="block text-xs font-medium text-stone-500">Arquivo PDF</label>
                <input
                    type="file"
                    wire:model="arquivo"
                    accept="application/pdf"
                    class="mt-1 block w-full text-sm text-stone-700 file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-1.5 file:text-sm file:text-stone-700 hover:file:bg-stone-200"
                >
                @error('arquivo')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-xs font-medium text-stone-500">Tipo</label>
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
                <span wire:loading.remove wire:target="upload">Enviar</span>
                <span wire:loading wire:target="upload">Enviando…</span>
            </button>
        </div>

        <p class="mt-3 text-xs text-stone-400">
            Até {{ config('cerne.ai.max_upload_mb') }} MB e {{ config('cerne.ai.max_pdf_pages') }} páginas por arquivo.
        </p>
    </form>

    {{-- Revisão --------------------------------------------------------- --}}
    @if ($revisando)
        <div class="rounded-xl border-2 border-brand-700 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-stone-900">
                        Revisar {{ $revisando->document_type->label() }}
                    </h2>
                    <p class="mt-0.5 text-xs text-stone-500">
                        {{ $revisando->original_filename }}
                        @if ($revisando->institution_name) · {{ $revisando->institution_name }} @endif
                        @if ($revisando->competenceLabel()) · {{ $revisando->competenceLabel() }} @endif
                        · vira {{ $revisando->document_type->destination() }}
                    </p>
                </div>
                <button wire:click="fecharRevisao" class="text-sm text-stone-400 hover:text-stone-700">Fechar</button>
            </div>

            @if ($revisando->extractionNotes())
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <span class="font-medium">Observações da leitura:</span> {{ $revisando->extractionNotes() }}
                </div>
            @endif

            @error('confirmar')
                <p class="mt-3 text-sm text-red-700">{{ $message }}</p>
            @enderror

            @php $itens = $revisando->extractedItems(); @endphp

            @if ($itens === [])
                <p class="mt-4 text-sm text-stone-500">Nada foi extraído deste documento.</p>
            @else
                <div class="mt-4 max-h-96 overflow-y-auto rounded-lg border border-stone-200">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-stone-50 text-xs text-stone-500">
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                @foreach (array_keys($itens[0]) as $coluna)
                                    <th class="px-3 py-2 text-left font-medium">{{ str_replace('_', ' ', $coluna) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($itens as $i => $item)
                                <tr class="{{ in_array($i, $aceitos) ? '' : 'opacity-40' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="aceitos" value="{{ $i }}" class="rounded border-stone-300 text-brand-700 focus:ring-brand-500">
                                    </td>
                                    @foreach ($item as $chave => $valor)
                                        <td class="px-3 py-2 text-stone-700 {{ in_array($chave, ['valor', 'valor_atual', 'valor_bruto', 'premio']) ? 'text-right tabular-nums' : '' }}">
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
                    <p class="text-sm text-stone-600">
                        {{ count($aceitos) }} de {{ count($itens) }} selecionados
                    </p>
                    <div class="flex gap-2">
                        <button wire:click="descartar('{{ $revisando->id }}')" class="btn-secondary px-3 py-1.5 hover:text-red-700">
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
        <h2 class="text-sm font-semibold text-stone-900">Documentos</h2>

        @if ($documents->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
                <p class="text-sm text-stone-600">Nenhum documento enviado ainda.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($documents as $doc)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-stone-800">{{ $doc->original_filename }}</p>
                            <p class="truncate text-xs text-stone-500">
                                {{ $doc->document_type->label() }}
                                · {{ $doc->created_at->format('d/m/Y H:i') }}
                                @if ($doc->institution_name) · {{ $doc->institution_name }} @endif
                                @if ($doc->records_extracted !== null) · {{ $doc->records_extracted }} itens @endif
                            </p>
                            @if ($doc->error_message)
                                <p class="mt-0.5 text-xs text-red-700">{{ $doc->error_message }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-stone-100 text-stone-600' => $doc->processing_status->color() === 'stone',
                                'bg-amber-100 text-amber-900' => $doc->processing_status->color() === 'amber',
                                'bg-red-100 text-red-900' => $doc->processing_status->color() === 'red',
                                'bg-brand-100 text-brand-900' => $doc->processing_status->color() === 'teal',
                            ])>{{ $doc->processing_status->label() }}</span>

                            @if ($doc->isAwaitingReview())
                                <button wire:click="revisar('{{ $doc->id }}')" class="btn-primary px-3 py-1">
                                    Revisar
                                </button>
                            @else
                                <button wire:click="descartar('{{ $doc->id }}')" class="text-sm text-stone-400 hover:text-red-700">
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
