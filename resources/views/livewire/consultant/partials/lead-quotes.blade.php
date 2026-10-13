@use('App\Support\Money')
@use('App\Enums\InsuranceType')
@use('App\Enums\LeadQuoteStatus')

{{--
    Janela de cotações de um lead de seguros: as simulações feitas (seguradora, prêmio estimado, capital e proteções,
    validade), o PDF de cada uma e os documentos do contato. Recebe $lead (já com quotes.attachments); o resto
    ($quoteStatuses, $showQuoteForm etc.) vem do componente pai.
--}}
@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $dadosParaCotar = collect($lead->quote_data ?? [])->flatMap(function (array $campos, string $tipo) use ($quoteFields) {
        $rotulos = collect($quoteFields[$tipo] ?? [])->keyBy('key');

        return collect($campos)->map(fn ($valor, $chave) => ($rotulos[$chave]['label'] ?? $chave).': '.($rotulos[$chave]['options'][$valor] ?? $valor))->values();
    });
@endphp

@php
    $todosAnexos = $lead->attachments->merge($lead->quotes->flatMap->attachments);
    $algumaLendo = $todosAnexos->contains(fn ($a) => in_array($a->extraction_status, [\App\Enums\ProcessingStatus::Pending, \App\Enums\ProcessingStatus::Processing], true));
@endphp

@php $grupos = \App\Support\LeadQuoteComparison::build($lead->quotes); @endphp

<div class="space-y-5" @if ($algumaLendo) wire:poll.4s @endif>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="truncate text-sm font-semibold text-slate-900 dark:text-white">Cotações · {{ $lead->name }}</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                {{ $lead->stageLabel() }}
                @if ($lead->birth_date) · nascimento {{ $lead->birth_date->format('d/m/Y') }} @endif
            </p>
            @if ($dadosParaCotar->isNotEmpty())
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $dadosParaCotar->implode(' · ') }}</p>
            @endif
        </div>
        <button type="button" wire:click="closeQuotes" class="btn-ghost shrink-0 px-2 py-1 text-xs">Fechar</button>
    </div>

    @if (session('status'))
        <p class="rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-900 dark:bg-brand-500/10 dark:text-brand-200">{{ session('status') }}</p>
    @endif

    {{-- Formulário de cotação ------------------------------------------------ --}}
    @if ($showQuoteForm)
        <form wire:submit="saveQuote" class="space-y-4 rounded-xl border border-slate-100 p-4 dark:border-white/10" wire:key="quote-form">
            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingQuoteId ? 'Editar cotação' : 'Nova cotação' }}</p>

            @if ($reviewingAttachmentId)
                @php
                    $anexoLido = $todosAnexos->firstWhere('id', $reviewingAttachmentId);
                    $opcoesLidas = count($anexoLido?->extraction_summary['cotacoes'] ?? []);
                    $aplicadas = $anexoLido?->extraction_summary['aplicadas'] ?? [];
                @endphp
                <div class="space-y-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <p><strong>Preenchido pela leitura automática</strong> de {{ $anexoLido?->original_filename }}. Confira cada campo com a simulação antes de salvar: nada é gravado até você salvar.</p>
                    @if ($reviewUncertain !== [])
                        <p>A leitura ficou em dúvida em: {{ implode(', ', $reviewUncertain) }}.</p>
                    @endif
                    @if ($opcoesLidas > 1)
                        <p class="flex flex-wrap items-center gap-1.5">
                            O PDF traz {{ $opcoesLidas }} opções:
                            @for ($o = 0; $o < $opcoesLidas; $o++)
                                <button type="button" wire:click="reviewReading('{{ $reviewingAttachmentId }}', {{ $o }})" wire:confirm="Carregar a opção {{ $o + 1 }}? O que você editou no formulário será trocado." class="rounded-full px-2 py-0.5 font-medium {{ $o === $reviewingItem ? 'bg-amber-700 text-white' : 'bg-white text-amber-800 ring-1 ring-amber-300 dark:bg-transparent dark:text-amber-200' }}">{{ $o + 1 }}{{ in_array($o, $aplicadas, true) ? ' (já cadastrada)' : '' }}</button>
                            @endfor
                        </p>
                    @endif
                </div>
            @endif

            <div class="grid gap-3 @sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">Tipo de seguro</label>
                    <select wire:model="quoteType" class="select mt-1.5 w-full">
                        @foreach ($insuranceTypes as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('quoteType') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Seguradora</label>
                    <input type="text" wire:model="quoteInsurer" class="input mt-1.5" placeholder="Ex.: Azos, Porto Seguro">
                    @error('quoteInsurer') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Produto (opcional)</label>
                    <input type="text" wire:model="quoteProduct" class="input mt-1.5" placeholder="Ex.: Vida Individual">
                    @error('quoteProduct') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Nº da simulação (opcional)</label>
                    <input type="text" wire:model="quoteReference" class="input mt-1.5">
                    @error('quoteReference') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Prêmio estimado (R$)</label>
                    <input type="number" step="0.01" min="0" wire:model="quotePremium" class="input mt-1.5" placeholder="0,00">
                    @error('quotePremium') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">O prêmio é</label>
                    <select wire:model="quoteFrequency" class="select mt-1.5 w-full">
                        @foreach ($frequencies as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('quoteFrequency') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Capital principal (R$)</label>
                    <input type="number" step="0.01" min="0" wire:model="quoteCoverageAmount" class="input mt-1.5" placeholder="Em vida, o de morte">
                    @error('quoteCoverageAmount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Comissão prevista</label>
                    <div class="mt-1.5 flex gap-2">
                        <select wire:model.live="quoteCommissionType" class="select w-44 shrink-0" aria-label="Tipo de comissão">
                            @foreach (\App\Enums\CommissionType::cases() as $tipoComissao)
                                <option value="{{ $tipoComissao->value }}">{{ $tipoComissao->label() }}</option>
                            @endforeach
                        </select>
                        <input type="number" step="0.01" min="0" @if ($quoteCommissionType === 'percent') max="100" @endif wire:model="quoteCommission" class="input min-w-0 flex-1" placeholder="Opcional">
                    </div>
                    <p class="mt-1 text-xs text-slate-400">{{ $quoteCommissionType === 'percent' ? 'Percentual sobre o prêmio anual.' : 'Valor total previsto para o 1º ano.' }}</p>
                    @error('quoteCommissionType') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    @error('quoteCommission') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Válida até</label>
                    <input type="date" wire:model="quoteValidUntil" class="input mt-1.5">
                    @error('quoteValidUntil') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Situação</label>
                    <select wire:model="quoteStatus" class="select mt-1.5 w-full">
                        @foreach ($quoteStatuses as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('quoteStatus') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Proteções ---------------------------------------------------------- --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Proteções (opcional)</p>
                    <button type="button" wire:click="addQuoteCoverage" class="btn-ghost px-2 py-1 text-xs">+ Proteção</button>
                </div>
                @foreach ($quoteCoverages as $i => $cobertura)
                    <div class="grid grid-cols-[1fr_7rem_6rem_auto] items-start gap-2" wire:key="cob-{{ $i }}">
                        <input type="text" wire:model="quoteCoverages.{{ $i }}.name" class="input py-1.5 text-xs" placeholder="Ex.: Doenças graves">
                        <input type="number" step="0.01" min="0" wire:model="quoteCoverages.{{ $i }}.value" class="input py-1.5 text-xs" placeholder="Valor">
                        <input type="text" wire:model="quoteCoverages.{{ $i }}.deductible" class="input py-1.5 text-xs" placeholder="Franquia">
                        <button type="button" wire:click="removeQuoteCoverage({{ $i }})" class="px-1 text-xs text-slate-400 hover:text-red-700" aria-label="Remover proteção">✕</button>
                    </div>
                    @error("quoteCoverages.$i.value") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                @endforeach
            </div>

            <div>
                <label class="{{ $campo }}">Observações (opcional)</label>
                <textarea wire:model="quoteNotes" rows="2" class="input mt-1.5"></textarea>
                @error('quoteNotes') <p class="{{ $erro }}">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-end gap-2">
                <button type="button" wire:click="cancelQuoteForm" class="btn-ghost px-3 py-1.5 text-xs">Cancelar</button>
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar cotação</button>
            </div>
        </form>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="newQuote" class="btn-secondary px-3 py-1.5 text-sm">+ Nova cotação</button>
            @if ($grupos !== [])
                <button type="button" wire:click="toggleComparison" class="btn-ghost px-3 py-1.5 text-sm">{{ $showComparison ? 'Voltar à lista' : 'Comparar cotações' }}</button>
            @endif
            @if ($aiReading)
                <button type="button" wire:click="chooseAttachTarget('{{ $attachTarget === 'ia' ? '' : 'ia' }}')" class="btn-ghost px-3 py-1.5 text-sm">{{ $attachTarget === 'ia' ? 'Cancelar' : 'Ler simulação com IA' }}</button>
            @endif
        </div>

        @if ($attachTarget === 'ia')
            <div class="rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
                <p class="mb-1.5 text-xs text-slate-500 dark:text-slate-400">Envie o PDF da simulação. A IA lê seguradora, prêmio, capital e proteções e preenche a cotação para você conferir; nada é cadastrado sem a sua confirmação.</p>
                <input type="file" wire:model="anexo" accept=".pdf" class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-1.5 file:text-xs file:font-medium dark:text-slate-300 dark:file:bg-slate-700">
                <p class="mt-1 text-xs text-slate-400" wire:loading wire:target="anexo">Enviando...</p>
                @error('anexo') <p class="{{ $erro }}">{{ $message }}</p> @enderror
            </div>
        @endif
    @endif

    {{-- Cotações feitas -------------------------------------------------------- --}}
    @if ($showComparison && $grupos !== [])
        @include('livewire.consultant.partials.lead-quote-comparison', ['groups' => $grupos])
    @else
    @forelse ($lead->quotes as $quote)
        @php
            $vencida = $quote->isExpired();
            $dias = $quote->daysToExpire();
            $coberturas = $quote->displayCoverages();
        @endphp
        <div class="space-y-2 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="quote-{{ $quote->id }}">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                        {{ $quote->insurer_name }}
                        <span class="ml-1 rounded-full bg-seguros-200/40 px-2 py-0.5 text-[10px] font-medium text-seguros-800 dark:bg-seguros-800/30 dark:text-seguros-200">{{ $quote->insurance_type->label() }}</span>
                    </p>
                    @if ($quote->product || $quote->reference)
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ collect([$quote->product, $quote->reference ? 'simulação '.$quote->reference : null])->filter()->implode(' · ') }}</p>
                    @endif
                </div>
                <span class="rounded-full px-2 py-0.5 text-[10px] font-medium text-white" style="background: {{ $vencida ? '#dc2626' : $quote->status->color() }}">{{ $vencida ? 'Vencida' : $quote->status->label() }}</span>
            </div>

            <p class="text-sm text-slate-700 dark:text-slate-300">
                @if ($quote->premium !== null)
                    <strong class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($quote->premium) }}</strong>
                    {{ match ($quote->payment_frequency->value) { 'monthly' => 'por mês', 'quarterly' => 'por trimestre', default => 'por ano' } }}
                    @if ($quote->payment_frequency->value !== 'annual')
                        <span class="text-xs text-slate-400">· {{ Money::format($quote->annualPremium()) }} por ano</span>
                    @endif
                @else
                    <span class="text-xs text-slate-400">Prêmio ainda não informado</span>
                @endif
                @if ($quote->coverage_amount !== null)
                    · {{ $quote->insurance_type === InsuranceType::Vida ? 'capital por morte' : 'capital' }} <strong class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($quote->coverage_amount) }}</strong>
                @endif
            </p>

            <p class="text-xs text-slate-500 dark:text-slate-400">
                @if ($quote->valid_until)
                    Válida até {{ $quote->valid_until->format('d/m/Y') }}
                    @if ($dias !== null && $dias <= 7) <span class="font-medium text-amber-700 dark:text-amber-400">({{ $dias === 0 ? 'vence hoje' : 'faltam '.$dias.' '.($dias === 1 ? 'dia' : 'dias') }})</span> @endif
                @endif
                @if ($quote->expectedCommission() !== null)
                    {{ $quote->valid_until ? '·' : '' }} comissão prevista {{ Money::format($quote->expectedCommission()) }} no 1º ano
                @endif
            </p>

            @if ($coberturas !== [])
                <details class="text-xs">
                    <summary class="cursor-pointer text-slate-500 dark:text-slate-400">{{ count($coberturas) }} {{ count($coberturas) === 1 ? 'proteção' : 'proteções' }}</summary>
                    <ul class="mt-1.5 space-y-0.5 text-slate-600 dark:text-slate-300">
                        @foreach ($coberturas as $c)
                            <li>{{ $c['name'] }}@if ($c['value'] !== null) · {{ Money::format($c['value']) }}@endif @if ($c['note']) <span class="text-slate-400">({{ $c['note'] }})</span> @endif</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($quote->notes)
                <p class="whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $quote->notes }}</p>
            @endif

            {{-- A simulação em PDF --------------------------------------------------- --}}
            @if ($quote->attachments->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($quote->attachments as $anexo)
                        @include('livewire.consultant.partials.lead-attachment-row', ['anexo' => $anexo])
                    @endforeach
                </div>
            @endif

            @if ($attachTarget === $quote->id)
                <div class="rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
                    <input type="file" wire:model="anexo" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-1.5 file:text-xs file:font-medium dark:text-slate-300 dark:file:bg-slate-700">
                    <p class="mt-1 text-xs text-slate-400" wire:loading wire:target="anexo">Enviando...</p>
                    @error('anexo') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-1.5 pt-1">
                <select wire:change="setQuoteStatus('{{ $quote->id }}', $event.target.value)" class="select w-auto py-1 text-xs" aria-label="Situação da cotação">
                    @foreach ($quoteStatuses as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected($quote->status->value === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="chooseAttachTarget('{{ $attachTarget === $quote->id ? '' : $quote->id }}')" class="btn-ghost px-2 py-1 text-xs">{{ $attachTarget === $quote->id ? 'Cancelar anexo' : 'Anexar simulação' }}</button>
                <button type="button" wire:click="editQuote('{{ $quote->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                <button type="button" wire:click="deleteQuote('{{ $quote->id }}')" wire:confirm="Excluir esta cotação e os arquivos dela?" class="ml-auto text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">excluir</button>
            </div>
        </div>
    @empty
        <p class="rounded-xl border border-dashed border-slate-300 px-4 py-6 text-center text-xs text-slate-400 dark:border-slate-600">Nenhuma cotação ainda. Cadastre as simulações que fizer para este contato.</p>
    @endforelse
    @endif

    {{-- Arquivos do contato (CNH, CRLV, documentos em geral) ---------------------------- --}}
    <div class="space-y-2 border-t border-slate-100 pt-4 dark:border-white/10">
        <div class="flex items-center justify-between">
            <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Arquivos do contato</p>
            <button type="button" wire:click="chooseAttachTarget('{{ $attachTarget === '' ? 'geral' : '' }}')" class="btn-ghost px-2 py-1 text-xs">{{ $attachTarget === 'geral' ? 'Cancelar' : '+ Anexar arquivo' }}</button>
        </div>

        @if ($attachTarget === 'geral')
            <div class="rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
                <input type="file" wire:model="anexo" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-200 file:px-3 file:py-1.5 file:text-xs file:font-medium dark:text-slate-300 dark:file:bg-slate-700">
                <p class="mt-1 text-xs text-slate-400" wire:loading wire:target="anexo">Enviando...</p>
                @error('anexo') <p class="{{ $erro }}">{{ $message }}</p> @enderror
            </div>
        @endif

        @forelse ($lead->attachments as $anexo)
            @include('livewire.consultant.partials.lead-attachment-row', ['anexo' => $anexo])
        @empty
            <p class="text-xs text-slate-400">Nenhum arquivo. PDF ou imagem, até 10 MB cada.</p>
        @endforelse

        <p class="text-xs text-slate-400">Os arquivos ficam em área privada, só você abre. Quando um contato é marcado como perdido, os arquivos saem do servidor depois de 12 meses.</p>
    </div>
</div>
