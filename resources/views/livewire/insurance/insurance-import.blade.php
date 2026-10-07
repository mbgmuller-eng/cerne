@use('App\Enums\InsuranceType')
@use('App\Enums\PaymentFrequency')
@use('App\Enums\ProcessingStatus')

@php
    $statusClasses = [
        'stone' => 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
        'amber' => 'bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'red' => 'bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20',
        'teal' => 'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300',
    ];
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
@endphp

<div class="space-y-4" @if ($emAndamento) wire:poll.5s @endif>

    @if ($mensagem)
        <div class="flex items-start justify-between gap-3 rounded-xl border border-accent-200 bg-accent-50 px-4 py-3 text-sm text-accent-800 dark:border-accent-500/30 dark:bg-accent-500/10 dark:text-accent-200" role="status">
            <p>{{ $mensagem }}</p>
            <button type="button" wire:click="$set('mensagem', null)" class="shrink-0 text-xs underline">Fechar</button>
        </div>
    @endif

    {{-- Leituras em andamento ou aguardando revisão ------------------- --}}
    @if ($leituras->isNotEmpty())
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Apólices importadas por PDF</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Nada entra na lista de apólices antes de você conferir os dados lidos.</p>

            <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($leituras as $leitura)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3" wire:key="leitura-{{ $leitura->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $leitura->original_filename }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                enviado {{ $leitura->created_at->diffForHumans() }}
                                @if ($leitura->processing_status === ProcessingStatus::Completed)
                                    · {{ $leitura->records_extracted === 1 ? '1 apólice lida' : count($leitura->extractedItems()).' apólices lidas' }}
                                @endif
                            </p>
                            @if ($leitura->processing_status === ProcessingStatus::Failed && $leitura->error_message)
                                <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $leitura->error_message }}</p>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="badge {{ $statusClasses[$leitura->processing_status->color()] }}">
                                {{ $leitura->processing_status === ProcessingStatus::Pending && ! $iaConfigurada ? 'Na fila' : $leitura->processing_status->label() }}
                            </span>

                            @if ($leitura->processing_status === ProcessingStatus::Completed)
                                <button type="button" wire:click="revisar('{{ $leitura->id }}')" class="btn-primary px-3 py-1.5 text-xs">Revisar</button>
                                <button type="button" wire:click="descartar('{{ $leitura->id }}')" wire:confirm="Descartar esta leitura? O PDF enviado será apagado." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Descartar</button>
                            @elseif ($leitura->processing_status === ProcessingStatus::Failed)
                                <button type="button" wire:click="tentarDeNovo('{{ $leitura->id }}')" class="btn-ghost px-2 py-1 text-xs">Tentar de novo</button>
                                <button type="button" wire:click="descartar('{{ $leitura->id }}')" wire:confirm="Descartar esta leitura? O PDF enviado será apagado." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Descartar</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Envio ---------------------------------------------------------- --}}
    <x-modal wire-model="showUpload" max-width="lg">
        <form wire:submit="enviar" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Importar apólice</h2>
                <button type="button" wire:click="$set('showUpload', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <p class="text-sm text-slate-600 dark:text-slate-400">
                Envie o PDF da apólice. O Cerne lê seguradora, tipo de seguro, proteções, vigência, custo e forma de pagamento,
                e você confere tudo antes de salvar. Pode criar uma apólice nova ou atualizar uma que já existe.
            </p>

            <div>
                <label class="{{ $campo }}">Arquivo PDF</label>
                <input
                    type="file"
                    wire:model="arquivo"
                    accept="application/pdf"
                    class="mt-1 block w-full text-sm text-slate-700 dark:text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 dark:file:bg-slate-700 file:px-3 file:py-1.5 file:text-sm file:text-slate-700 dark:file:text-slate-300 hover:file:bg-slate-200 dark:hover:file:bg-slate-600"
                >
                <p wire:loading wire:target="arquivo" class="mt-1 text-xs text-slate-500">Carregando o arquivo...</p>
                @error('arquivo') <p class="{{ $erro }}">{{ $message }}</p> @enderror
            </div>

            <div class="space-y-1 text-xs text-slate-500 dark:text-slate-400">
                <p>O PDF é enviado a um serviço de inteligência artificial (Anthropic) apenas para a leitura, e fica guardado em Documentos, ligado à apólice.</p>
                <p>Leituras restantes hoje neste perfil: <strong>{{ $restantesHoje }}</strong> de {{ $limiteDiario }}.</p>
                @unless ($iaConfigurada)
                    <p class="text-amber-800 dark:text-amber-300">A leitura automática ainda não está ativa neste ambiente: o arquivo fica na fila.</p>
                @endunless
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-white/10">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="arquivo,enviar" @disabled($restantesHoje === 0)>Enviar para leitura</button>
            </div>
        </form>
    </x-modal>

    {{-- Revisão --------------------------------------------------------- --}}
    <x-modal wire-model="showReview" max-width="2xl">
        @if ($revisando)
            <form wire:submit="confirmar" class="space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Revisar apólice lida</h2>
                        <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">{{ $revisando->original_filename }}</p>
                    </div>
                    <button type="button" wire:click="fecharRevisao" class="btn-ghost shrink-0 px-2 py-1 text-xs">Fechar</button>
                </div>

                @if ($revisando->extractionNotes())
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <span class="font-medium">Observações da leitura:</span> {{ $revisando->extractionNotes() }}
                    </div>
                @endif

                @error('rows') <p class="{{ $erro }}">{{ $message }}</p> @enderror

                @foreach ($rows as $i => $linha)
                    @php $atualizando = $linha['target'] !== 'new'; @endphp

                    <fieldset class="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-white/10" wire:key="linha-{{ $i }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <label class="flex items-center gap-2 text-sm font-medium text-slate-900 dark:text-white">
                                <input type="checkbox" wire:model.live="rows.{{ $i }}.import" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                @if (count($rows) > 1) Apólice {{ $loop->iteration }} de {{ count($rows) }} @else Importar esta apólice @endif
                            </label>
                            @unless ($linha['import'])
                                <span class="text-xs text-slate-500">Não será importada.</span>
                            @endunless
                        </div>

                        @if ($linha['import'])
                            @if ($linha['incertos'] !== [])
                                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                    A leitura ficou em dúvida em: <strong>{{ implode(', ', $linha['incertos']) }}</strong>. Confira no PDF.
                                </div>
                            @endif

                            <div>
                                <label class="{{ $campo }}">O que fazer com esta apólice</label>
                                <select wire:model.live="rows.{{ $i }}.target" class="select mt-1.5 w-full">
                                    <option value="new">Criar uma apólice nova</option>
                                    @foreach ($apolicesExistentes as $existente)
                                        <option value="{{ $existente->id }}">
                                            Atualizar: {{ $existente->insurance_type->label() }} · {{ $existente->insurer_name }}{{ $existente->policy_number ? ' · '.$existente->policy_number : '' }}{{ $existente->personLabel() ? ' · '.$existente->personLabel() : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error("rows.$i.target") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                @if ($atualizando)
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Os dados abaixo substituem os da apólice escolhida. Pessoa, privacidade e anotações dela são mantidos, e o PDF novo vai para Documentos.</p>
                                @endif
                            </div>

                            <div class="grid gap-4 @sm:grid-cols-2">
                                <div>
                                    <label class="{{ $campo }}">Tipo de seguro</label>
                                    <select wire:model="rows.{{ $i }}.tipo" class="select mt-1.5 w-full">
                                        @foreach (InsuranceType::options() as $valor => $rotulo)
                                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                                        @endforeach
                                    </select>
                                    @error("rows.$i.tipo") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Seguradora</label>
                                    <input type="text" wire:model="rows.{{ $i }}.seguradora" class="input mt-1.5">
                                    @error("rows.$i.seguradora") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Número da apólice</label>
                                    <input type="text" wire:model="rows.{{ $i }}.numero" class="input mt-1.5">
                                    @error("rows.$i.numero") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Item segurado</label>
                                    <input type="text" wire:model="rows.{{ $i }}.objeto" class="input mt-1.5" placeholder="Ex.: Honda Civic 2022, placa ABC1D23">
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Valor segurado</label>
                                    <input type="number" step="0.01" wire:model="rows.{{ $i }}.valor_segurado" class="input mt-1.5" placeholder="0,00">
                                    @error("rows.$i.valor_segurado") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Forma de pagamento</label>
                                    <select wire:model="rows.{{ $i }}.periodicidade" class="select mt-1.5 w-full">
                                        @foreach (PaymentFrequency::options() as $valor => $rotulo)
                                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                                        @endforeach
                                    </select>
                                    @error("rows.$i.periodicidade") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Custo de cada pagamento</label>
                                    <input type="number" step="0.01" wire:model="rows.{{ $i }}.premio" class="input mt-1.5" placeholder="0,00">
                                    @error("rows.$i.premio") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Prêmio anual (opcional)</label>
                                    <input type="number" step="0.01" wire:model="rows.{{ $i }}.premio_anual" class="input mt-1.5" placeholder="0,00">
                                    @error("rows.$i.premio_anual") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Início de vigência</label>
                                    <input type="date" wire:model="rows.{{ $i }}.inicio" class="input mt-1.5">
                                    @error("rows.$i.inicio") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="{{ $campo }}">Vencimento (vazio se não tem fim)</label>
                                    <input type="date" wire:model="rows.{{ $i }}.fim" class="input mt-1.5">
                                    @error("rows.$i.fim") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                </div>

                                @unless ($atualizando)
                                    <div>
                                        <label class="{{ $campo }}">Membro</label>
                                        <select wire:model="rows.{{ $i }}.member_id" class="select mt-1.5 w-full">
                                            <option value="">Seguro familiar / outra pessoa</option>
                                            @foreach ($members as $membro)
                                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                                            @endforeach
                                        </select>
                                        @error("rows.$i.member_id") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                    </div>

                                    <div>
                                        <label class="{{ $campo }}">Outra pessoa (opcional)</label>
                                        <input type="text" wire:model="rows.{{ $i }}.pessoa" class="input mt-1.5" placeholder="Ex.: Filha, Maria">
                                        <p class="mt-1 text-xs text-slate-400">Só vale quando não há membro escolhido.</p>
                                    </div>

                                    <div class="flex items-center gap-2 @sm:col-span-2">
                                        <input type="checkbox" wire:model="rows.{{ $i }}.privado" id="privado-{{ $i }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <label for="privado-{{ $i }}" class="text-sm text-slate-600 dark:text-slate-400">Ocultar do cônjuge</label>
                                    </div>

                                    <div class="@sm:col-span-2">
                                        <label class="{{ $campo }}">Notas (opcional)</label>
                                        <textarea wire:model="rows.{{ $i }}.notas" rows="2" class="input mt-1.5"></textarea>
                                    </div>
                                @endunless
                            </div>

                            {{-- Proteções -------------------------------------------- --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">Proteções</p>
                                    <button type="button" wire:click="addCobertura({{ $i }})" class="btn-ghost px-2 py-1 text-xs">+ Adicionar</button>
                                </div>
                                @forelse ($linha['coberturas'] as $j => $cobertura)
                                    <div class="grid gap-2 @sm:grid-cols-[1fr_9rem_9rem_auto] @sm:items-start" wire:key="cob-{{ $i }}-{{ $j }}">
                                        <input type="text" wire:model="rows.{{ $i }}.coberturas.{{ $j }}.nome" class="input" placeholder="Proteção">
                                        <input type="number" step="0.01" wire:model="rows.{{ $i }}.coberturas.{{ $j }}.valor" class="input" placeholder="Valor">
                                        <input type="text" wire:model="rows.{{ $i }}.coberturas.{{ $j }}.franquia" class="input" placeholder="Franquia">
                                        <button type="button" wire:click="removeCobertura({{ $i }}, {{ $j }})" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400" aria-label="Remover proteção">Remover</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Nenhuma proteção lida. Se a apólice lista coberturas, adicione aqui.</p>
                                @endforelse
                                @error("rows.$i.coberturas.*.valor") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                            </div>

                            {{-- Beneficiários ---------------------------------------- --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">Beneficiários</p>
                                    <button type="button" wire:click="addBeneficiario({{ $i }})" class="btn-ghost px-2 py-1 text-xs">+ Adicionar</button>
                                </div>
                                @foreach ($linha['beneficiarios'] as $j => $beneficiario)
                                    <div class="grid gap-2 @sm:grid-cols-[1fr_7rem_9rem_auto] @sm:items-start" wire:key="ben-{{ $i }}-{{ $j }}">
                                        <input type="text" wire:model="rows.{{ $i }}.beneficiarios.{{ $j }}.nome" class="input" placeholder="Nome">
                                        <input type="number" step="0.01" wire:model="rows.{{ $i }}.beneficiarios.{{ $j }}.percentual" class="input" placeholder="%">
                                        <input type="text" wire:model="rows.{{ $i }}.beneficiarios.{{ $j }}.parentesco" class="input" placeholder="Parentesco">
                                        <button type="button" wire:click="removeBeneficiario({{ $i }}, {{ $j }})" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400" aria-label="Remover beneficiário">Remover</button>
                                    </div>
                                @endforeach
                                @error("rows.$i.beneficiarios") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                                @error("rows.$i.beneficiarios.*.percentual") <p class="{{ $erro }}">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    </fieldset>
                @endforeach

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs text-slate-500 dark:text-slate-400">O PDF fica guardado em Documentos, ligado à apólice.</p>
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="confirmar">Confirmar importação</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
