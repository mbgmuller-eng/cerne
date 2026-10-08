@use('App\Support\Money')
@use('Illuminate\Support\Arr')
@use('App\Enums\DocumentType')
@use('App\Enums\Necessity')
@use('App\Enums\ProcessingStatus')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Importar PDF</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Extrato, fatura ou relatório de outro aplicativo financeiro — a leitura é automática, mas nada é gravado sem sua revisão.
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
                <select wire:model.live="documentType" class="select mt-1.5">
                    @foreach ($tipos as $valor => $rotulo)
                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>

            @if (in_array($documentType, ['bank_statement', 'external_report'], true))
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $documentType === 'external_report' ? 'Conta dos lançamentos' : 'Conta do extrato' }}</label>
                    <select wire:model="uploadBankAccountId" class="select mt-1.5">
                        <option value="">Selecione a conta</option>
                        @foreach ($bankAccounts as $conta)
                            <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('uploadBankAccountId')
                        <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @elseif ($documentType === 'credit_card_invoice')
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cartão da fatura</label>
                    <select wire:model="uploadCreditCardId" class="select mt-1.5">
                        <option value="">Selecione o cartão</option>
                        @foreach ($creditCards as $cartao)
                            <option value="{{ $cartao->id }}">{{ $cartao->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('uploadCreditCardId')
                        <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <button
                type="submit"
                wire:loading.attr="disabled"
                class="btn-primary"
            >
                <span wire:loading.remove wire:target="enviar">Enviar</span>
                <span wire:loading wire:target="enviar">Enviando…</span>
            </button>
        </div>

        @if ($documentType === 'external_report')
            <div class="mt-4 space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" wire:model="updateBalance" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        Atualizar o saldo da conta com estes lançamentos
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            Deixe desmarcado se o relatório é de um período que o saldo atual da conta já reflete (o caso mais comum).
                            Marcado, cada despesa baixa o saldo e cada receita soma.
                        </span>
                    </span>
                </label>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    O arquivo é enviado a um serviço de inteligência artificial (Anthropic) apenas para a leitura, e você revisa tudo antes de gravar.
                    Leituras de relatório restantes hoje neste perfil: <strong>{{ $restantesHoje }}</strong> de {{ config('cerne.ai.external_imports_per_day') }}.
                </p>
            </div>
        @endif

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

            @if ($externo && $reconciliacao)
                @if ($reconciliacao['confere'])
                    <div class="mt-3 rounded-lg border border-accent-200 bg-accent-50 px-3 py-2 text-xs text-accent-800 dark:border-accent-500/30 dark:bg-accent-500/10 dark:text-accent-200">
                        A soma dos lançamentos lidos ({{ Money::format($reconciliacao['soma']) }}) confere com o total que o relatório declara.
                    </div>
                @else
                    <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
                        <span class="font-medium">A soma não confere com o total do relatório.</span>
                        Lidos: {{ Money::format($reconciliacao['soma']) }} · declarado: {{ Money::format($reconciliacao['declarado']) }}
                        (diferença de {{ Money::format(ltrim($reconciliacao['diferenca'], '-')) }}).
                        Pode haver linha que não foi lida, ou lida a mais: confira com o relatório antes de importar.
                    </div>
                @endif
            @endif

            @if ($externo && $gruposExterno->isNotEmpty())
                <div class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Resumo por categoria do outro aplicativo</p>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Escolha a categoria e a necessidade do Cerne para cada grupo e aplique de uma vez; depois ajuste só as linhas que fugirem à regra.
                        Linhas já categorizadas por uma regra sua não são alteradas.
                    </p>
                    <div class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($gruposExterno as $gi => $grupo)
                            <div class="grid items-center gap-2 py-2 sm:grid-cols-[1.4fr_1fr_1fr_auto]" wire:key="grupo-{{ $gi }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $grupo['rotulo'] }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $grupo['quantidade'] }} {{ $grupo['quantidade'] === 1 ? 'lançamento' : 'lançamentos' }} · {{ Money::format($grupo['total']) }}</p>
                                </div>
                                <select wire:model="grupoCategoria.{{ $gi }}" class="select w-full text-xs">
                                    <option value="">Categoria do Cerne</option>
                                    @foreach ($expenseCategories->filter(fn ($c) => $c->necessity === null) as $categoria)
                                        <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                                    @endforeach
                                </select>
                                <select wire:model="grupoNecessidade.{{ $gi }}" class="select w-full text-xs">
                                    <option value="">Necessidade</option>
                                    @foreach (Necessity::options() as $valorNecessidade => $rotulo)
                                        @unless ($valorNecessidade === Necessity::Investment->value)
                                            <option value="{{ $valorNecessidade }}">{{ $rotulo }}</option>
                                        @endunless
                                    @endforeach
                                </select>
                                <button type="button" wire:click="aplicarGrupo({{ $gi }})" class="btn-secondary px-3 py-1 text-xs">Aplicar ao grupo</button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @error('confirmar')
                <p class="mt-3 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror

            @php
                $itens = $revisando->extractedItems();
                $temCategorizacao = in_array($revisando->document_type, [DocumentType::BankStatement, DocumentType::CreditCardInvoice, DocumentType::ExternalReport], true);
                // Relatório de outro app traz muitos campos de origem; a tabela mostra o essencial e o resto vira pista na linha.
                $colunas = $externo ? ['data', 'descricao', 'valor'] : ($itens === [] ? [] : array_keys($itens[0]));
            @endphp

            @if ($itens === [])
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Nada foi extraído deste documento.</p>
            @else
                <div class="mt-4 max-h-96 overflow-y-auto rounded-lg border border-slate-200 dark:border-slate-700">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-slate-50 dark:bg-slate-800 text-xs text-slate-500 dark:text-slate-400">
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                @foreach ($colunas as $coluna)
                                    <th class="px-3 py-2 {{ $coluna === 'valor' ? 'text-right' : 'text-left' }} font-medium">{{ $coluna === 'descricao' ? 'descrição' : str_replace('_', ' ', $coluna) }}</th>
                                @endforeach
                                @if ($temCategorizacao)
                                    <th class="px-3 py-2 text-left font-medium">necessidade</th>
                                    <th class="px-3 py-2 text-left font-medium">categoria</th>
                                    <th class="px-3 py-2 text-left font-medium">subcategoria</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($itens as $i => $item)
                                @php
                                    $jaImportado = in_array($i, $revisando->imported_item_indices ?? [], true);
                                    $jaExcluido = in_array($i, $revisando->excluded_item_indices ?? [], true);
                                    $resolvido = $jaImportado || $jaExcluido;
                                    $ehReceita = ($item['tipo'] ?? null) === 'receita';
                                    $ehEstorno = $estornoPorItem[$i] ?? false;
                                    $necessidadeItem = $necessidadePorItem[$i] ?? '';
                                    $faltaCategorizar = ! $ehReceita && ($itensFaltandoCategoria[$i] ?? false);
                                    $categoriasDoItem = $necessidadeItem === Necessity::Investment->value
                                        ? $expenseCategories->filter(fn ($c) => $c->necessity === Necessity::Investment)
                                        : $expenseCategories->filter(fn ($c) => $c->necessity === null);
                                @endphp

                                @if ($resolvido)
                                    {{-- Item já teve destino numa rodada anterior (ou nesta)
                                         — não volta a ser oferecido, só mostrado como referência. --}}
                                    <tr class="opacity-50">
                                        <td class="px-3 py-2"></td>
                                        @foreach (collect($colunas)->mapWithKeys(fn ($c) => [$c => $item[$c] ?? null]) as $chave => $valor)
                                            <td class="px-3 py-2 text-slate-500 dark:text-slate-500 {{ in_array($chave, ['valor', 'valor_atual', 'valor_bruto', 'premio']) ? 'text-right tabular-nums' : '' }}">
                                                @if (is_array($valor))
                                                    {{ collect($valor)->map(fn ($v) => is_array($v) ? implode(' ', $v) : $v)->implode(', ') }}
                                                @elseif ($externo && $chave === 'data' && $valor)
                                                    <span class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y') }}</span>
                                                @elseif ($externo && $chave === 'valor' && $valor !== null)
                                                    <span class="whitespace-nowrap">{{ Money::format($valor) }}</span>
                                                @else
                                                    {{ $valor ?? '—' }}
                                                @endif
                                            </td>
                                        @endforeach
                                        @if ($temCategorizacao)
                                            <td colspan="3" class="px-3 py-2 text-xs">
                                                @if ($jaImportado)
                                                    <span class="font-medium text-brand-700 dark:text-brand-300">✓ Já importado</span>
                                                @else
                                                    <span class="font-medium text-slate-500 dark:text-slate-400">Não será importado</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>

                                    @continue
                                @endif

                                <tr class="{{ in_array($i, $aceitos) ? '' : 'opacity-40' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" wire:model.live="aceitos" value="{{ $i }}" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
                                    </td>
                                    @foreach (collect($colunas)->mapWithKeys(fn ($c) => [$c => $item[$c] ?? null]) as $chave => $valor)
                                        <td class="px-3 py-2 text-slate-700 dark:text-slate-300 {{ in_array($chave, ['valor', 'valor_atual', 'valor_bruto', 'premio']) ? 'text-right tabular-nums' : '' }}">
                                            @if (is_array($valor))
                                                {{ collect($valor)->map(fn ($v) => is_array($v) ? implode(' ', $v) : $v)->implode(', ') }}
                                            @elseif ($externo && $chave === 'data' && $valor)
                                                <span class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($valor)->format('d/m/Y') }}</span>
                                            @elseif ($externo && $chave === 'valor' && $valor !== null)
                                                <span class="whitespace-nowrap">{{ Money::format($valor) }}</span>
                                            @else
                                                {{ $valor ?? '—' }}
                                            @endif
                                        </td>
                                    @endforeach

                                    @if ($temCategorizacao)
                                        <td class="px-3 py-2">
                                            @if ($ehEstorno)
                                                <span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Estorno</span>
                                            @elseif (! $ehReceita)
                                                <select wire:model.live="necessidadePorItem.{{ $i }}" @class(['select w-full text-xs', 'ring-2 ring-amber-400 dark:ring-amber-500' => $faltaCategorizar && $necessidadeItem === ''])>
                                                    <option value="">Selecione</option>
                                                    @foreach (Necessity::options() as $valorNecessidade => $rotulo)
                                                        <option value="{{ $valorNecessidade }}">{{ $rotulo }}</option>
                                                    @endforeach
                                                </select>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            @unless ($ehEstorno)
                                                <select wire:model.live="categoriaPorItem.{{ $i }}" @class(['select w-full text-xs', 'ring-2 ring-amber-400 dark:ring-amber-500' => $faltaCategorizar && ($categoriaPorItem[$i] ?? '') === '']) @if (! $ehReceita && $necessidadeItem === '') disabled @endif>
                                                    <option value="">—</option>
                                                    @foreach (($ehReceita ? $incomeCategories : $categoriasDoItem) as $categoria)
                                                        <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                                                    @endforeach
                                                </select>
                                            @endunless
                                        </td>
                                        <td class="px-3 py-2">
                                            @unless ($ehReceita || $ehEstorno || $necessidadeItem === Necessity::Investment->value)
                                                <select wire:model="subcategoriaPorItem.{{ $i }}" @class(['select w-full text-xs', 'ring-2 ring-amber-400 dark:ring-amber-500' => $faltaCategorizar]) @if (($categoriaPorItem[$i] ?? '') === '') disabled @endif>
                                                    <option value="">—</option>
                                                    @foreach ($expenseSubcategories->where('category_id', $categoriaPorItem[$i] ?? null) as $subcategoria)
                                                        <option value="{{ $subcategoria->id }}">{{ $subcategoria->name }}</option>
                                                    @endforeach
                                                </select>
                                                <input
                                                    type="text"
                                                    wire:model="novaSubcategoriaPorItem.{{ $i }}"
                                                    wire:blur="criarSubcategoriaAgora({{ $i }})"
                                                    wire:keydown.enter.prevent="criarSubcategoriaAgora({{ $i }})"
                                                    placeholder="ou crie uma"
                                                    class="input mt-1 w-full py-1 text-xs"
                                                    @if (($categoriaPorItem[$i] ?? '') === '') disabled @endif
                                                >
                                            @endunless
                                        </td>
                                    @endif
                                </tr>

                                @if ($temCategorizacao)
                                    <tr class="{{ in_array($i, $aceitos) ? '' : 'opacity-40' }}">
                                        <td></td>
                                        <td colspan="{{ count($colunas) + 3 }}" class="px-3 pb-2 text-xs">
                                            @if ($externo)
                                                @php $dica = $dicasPorItem[$i] ?? []; @endphp
                                                <div class="mb-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-slate-500 dark:text-slate-400">
                                                    @if (($item['categoria_origem'] ?? '') !== '')
                                                        <span>Origem: {{ implode(' / ', array_filter([$item['categoria_origem'], $item['subcategoria_origem'] ?? ''])) }}</span>
                                                    @endif
                                                    @if ($dica['parcela'] ?? null)
                                                        <span class="badge bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300">Parcela {{ $dica['parcela'] }}</span>
                                                    @endif
                                                    @if ($dica['recorrente'] ?? false)
                                                        <span class="badge bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300" title="Cadastrada no outro aplicativo em um mês anterior ao do gasto">Provável recorrente</span>
                                                    @endif
                                                    @if ($dica['cartao'] ?? null)
                                                        <span class="font-medium text-red-700 dark:text-red-400">Compra no cartão {{ $dica['cartao'] }}: veio desmarcada, pois o Cerne registra compras de cartão pela fatura. Se marcar, entra como saída da conta escolhida</span>
                                                    @endif
                                                    @if ($dica['fatura'] ?? false)
                                                        <span class="font-medium text-red-700 dark:text-red-400">Parece pagamento de fatura de cartão: veio desmarcado para não contar em dobro com as compras da fatura</span>
                                                    @endif
                                                    @if (array_key_exists($i, $contaPorItem))
                                                        <label class="inline-flex items-center gap-1.5">
                                                            Conta de origem: <strong class="text-slate-700 dark:text-slate-200">{{ $dica['outra_conta'] }}</strong>. Lançar em:
                                                            <select wire:model="contaPorItem.{{ $i }}" class="select py-1 text-xs">
                                                                <option value="">Conta do envio</option>
                                                                @foreach ($bankAccounts as $contaDaLista)
                                                                    <option value="{{ $contaDaLista->id }}">{{ $contaDaLista->displayName() }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                    @endif
                                                </div>
                                            @endif

                                            @if ($faltaCategorizar || ($duplicataPorItem[$i] ?? null) || ($regraAplicadaPorItem[$i] ?? null) || ($notaPorItem[$i] ?? null))
                                                <div class="flex flex-wrap gap-x-3 gap-y-1">
                                                    @if ($faltaCategorizar)
                                                        <span class="font-medium text-amber-700 dark:text-amber-400">Falta categorizar</span>
                                                    @endif
                                                    @if ($duplicataPorItem[$i] ?? null)
                                                        <span class="font-medium text-red-700 dark:text-red-400">{{ $duplicataPorItem[$i] }}</span>
                                                    @endif
                                                    @if ($regraAplicadaPorItem[$i] ?? null)
                                                        <span class="text-slate-500 dark:text-slate-400">Categorizado pela regra "{{ $regraAplicadaPorItem[$i] }}"</span>
                                                    @endif
                                                    @if ($notaPorItem[$i] ?? null)
                                                        <span class="text-brand-700 dark:text-brand-300">{{ $notaPorItem[$i] }}</span>
                                                    @endif
                                                </div>
                                            @endif

                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                                @unless ($ehReceita)
                                                    <label class="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
                                                        <input type="checkbox" wire:model.live="estornoPorItem.{{ $i }}" class="rounded border-slate-300 dark:border-slate-600 text-emerald-600 focus:ring-emerald-500">
                                                        Estorno / cashback
                                                    </label>
                                                    <span class="text-slate-300 dark:text-slate-600">·</span>
                                                @endunless

                                                @unless ($ehEstorno)
                                                    <label class="inline-flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                                                        <input type="checkbox" wire:model.live="criarRegraPorItem.{{ $i }}" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
                                                        Criar regra de categorização também
                                                    </label>
                                                @endunless
                                                @if ($criarRegraPorItem[$i] ?? false)
                                                    <input
                                                        type="text"
                                                        wire:model="regraPatternPorItem.{{ $i }}"
                                                        placeholder="Padrão da regra"
                                                        class="input w-40 py-1 text-xs"
                                                    >
                                                    <label class="inline-flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                                                        <input type="checkbox" wire:model="regraValorExatoPorItem.{{ $i }}" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
                                                        só quando o valor for exatamente {{ Money::format($item['valor'] ?? 0) }}
                                                    </label>
                                                @endif

                                                <span class="text-slate-300 dark:text-slate-600">·</span>

                                                @if ($confirmandoExclusaoItem === $i)
                                                    <span class="text-slate-500 dark:text-slate-400">Não importar este item?</span>
                                                    <button type="button" wire:click="excluirItem({{ $i }})" class="font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                                    <button type="button" wire:click="cancelarExclusaoItem" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                                                @else
                                                    <button type="button" wire:click="confirmarExclusaoItem({{ $i }})" class="text-red-600 hover:underline dark:text-red-400">Não importar este item</button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php
                    $faltandoCount = collect($aceitos)->filter(fn ($i) => $itensFaltandoCategoria[$i] ?? false)->count();
                    $importadosCount = count($revisando->imported_item_indices ?? []);
                    $excluidosCount = count($revisando->excluded_item_indices ?? []);
                @endphp

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            {{ count($aceitos) }} de {{ count($itens) }} pendentes selecionados
                            @if ($importadosCount > 0 || $excluidosCount > 0)
                                <span class="text-slate-400">
                                    · {{ $importadosCount }} {{ $importadosCount === 1 ? 'já importado' : 'já importados' }},
                                    {{ $excluidosCount }} {{ $excluidosCount === 1 ? 'não será importado' : 'não serão importados' }}
                                </span>
                            @endif
                        </p>
                        @if ($faltandoCount > 0)
                            <p class="text-xs font-medium text-amber-700 dark:text-amber-400">
                                {{ $faltandoCount }} {{ $faltandoCount === 1 ? 'selecionado ainda está' : 'selecionados ainda estão' }} sem categorização completa — {{ $faltandoCount === 1 ? 'fica' : 'ficam' }} pendente{{ $faltandoCount === 1 ? '' : 's' }}, pode continuar depois.
                            </p>
                        @endif
                        @if (count($duplicataPorItem) > 0)
                            <p class="text-xs font-medium text-red-700 dark:text-red-400">
                                {{ count($duplicataPorItem) }} {{ count($duplicataPorItem) === 1 ? 'possível duplicata foi desmarcada' : 'possíveis duplicatas foram desmarcadas' }} automaticamente — confira antes de marcar de volta.
                            </p>
                        @endif
                    </div>
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
                                @if ($doc->document_type === DocumentType::ExternalReport) · {{ $doc->applies_to_balance ? 'atualiza o saldo' : 'sem alterar o saldo' }} @endif
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
                            @elseif ($doc->processing_status === ProcessingStatus::Failed)
                                <button wire:click="reprocessar('{{ $doc->id }}')" class="btn-secondary px-3 py-1">
                                    Reprocessar
                                </button>
                                <button wire:click="descartar('{{ $doc->id }}')" class="text-sm text-slate-400 hover:text-red-700 dark:hover:text-red-400">
                                    Excluir
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
