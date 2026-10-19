@use('App\Enums\ProcessingStatus')
@use('App\Support\Money')

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

    @if ($leituras->isNotEmpty())
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Extratos de consórcio enviados</h2>
            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Nada entra em Dívidas antes de você conferir os dados lidos.</p>

            <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($leituras as $leitura)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3" wire:key="leitura-{{ $leitura->id }}">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $leitura->original_filename }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">enviado {{ $leitura->created_at->diffForHumans() }}</p>
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
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Importar extrato do consórcio</h2>
                <button type="button" wire:click="$set('showUpload', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <p class="text-sm text-slate-600 dark:text-slate-400">
                Envie o PDF do "Extrato do Consorciado" da administradora. O Cerne lê o plano, a carta, a parcela atual e o valor realmente
                pago em cada parcela (inclusive as que ficaram sem pagamento), e você confere tudo antes de salvar. Pode criar um consórcio
                novo ou atualizar um que já existe.
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
                <p>O PDF é enviado a um serviço de inteligência artificial (Anthropic) apenas para a leitura e é apagado do Cerne assim que você confirma ou descarta a leitura, porque traz dados pessoais (CPF e endereço) que não precisam ficar guardados.</p>
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
        @if ($revisando && $row !== [])
            @php
                $bate = $row['declared_paid'] === '' || bccomp($row['declared_paid'], $row['read_paid'], 2) === 0;
                $existente = $row['target'] !== 'new';
            @endphp
            <form wire:submit="confirmar" class="space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Conferir o extrato do consórcio</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Grupo e cota {{ $row['group'] ?: '—' }} · {{ count($row['installments']) }} parcelas pagas lidas</p>
                    </div>
                    <button type="button" wire:click="fecharRevisao" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                @if ($row['uncertain'] !== [])
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300">
                        A leitura ficou em dúvida sobre: {{ implode(', ', $row['uncertain']) }}. Confira esses campos.
                    </div>
                @endif

                @if (! $bate)
                    <div class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                        A soma dos valores pagos lidos ({{ Money::format($row['read_paid']) }}) é diferente do total do extrato ({{ Money::format($row['declared_paid']) }}). Alguma parcela pode ter sido lida errado: confira a tabela abaixo.
                    </div>
                @endif

                <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">O que fazer com este extrato</label>
                        <select wire:model.live="row.target" class="select mt-1.5 w-full">
                            <option value="new">Cadastrar um consórcio novo</option>
                            @foreach ($consorcios as $c)
                                <option value="{{ $c->id }}">Atualizar: {{ $c->name }}{{ $c->consortium_group ? ' ('.$c->consortium_group.')' : '' }}</option>
                            @endforeach
                        </select>
                        @error('row.target') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                        @if ($existente)
                            <p class="mt-1 text-xs text-slate-400">As parcelas pagas pelo Cerne (com despesa ligada) não são mexidas. As demais passam a seguir o extrato.</p>
                        @endif
                    </div>

                    <div>
                        <label class="{{ $campo }}">Nome <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="row.name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Consórcio do apartamento">
                        @error('row.name') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Administradora</label>
                        <input type="text" wire:model="row.creditor" maxlength="120" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="{{ $campo }}">Tipo do consórcio</label>
                        <select wire:model="row.consortium_type" class="select mt-1.5 w-full">
                            @foreach ($tiposConsorcio as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $campo }}">Grupo e cota</label>
                        <input type="text" wire:model="row.group" maxlength="40" class="input mt-1.5">
                        @error('row.group') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Contrato <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="row.contract" maxlength="40" class="input mt-1.5">
                    </div>
                    <div>
                        <label class="{{ $campo }}">Prazo do plano (parcelas)</label>
                        <input type="number" wire:model="row.term" min="1" max="480" class="input mt-1.5">
                        @error('row.term') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Carta (crédito atual)</label>
                        <input type="text" wire:model="row.credit_amount" inputmode="decimal" class="input mt-1.5">
                        @error('row.credit_amount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Parcela atual</label>
                        <input type="text" wire:model="row.installment_amount" inputmode="decimal" class="input mt-1.5">
                        @error('row.installment_amount') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Parcela flex</label>
                        <select wire:model="row.flex_percent" class="select mt-1.5 w-full">
                            <option value="">Não é flex</option>
                            @foreach ([10, 20, 30, 40, 50] as $pct)
                                <option value="{{ $pct }}">{{ $pct }}%</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">Quanto a parcela é menor até a contemplação.</p>
                        @error('row.flex_percent') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $campo }}">Parcela de referência</label>
                        <div class="mt-1.5 grid grid-cols-2 gap-2">
                            <input type="number" wire:model="row.anchor_number" min="1" class="input" aria-label="Número da parcela de referência">
                            <input type="date" wire:model="row.anchor_due" class="input" aria-label="Vencimento da parcela de referência">
                        </div>
                        <p class="mt-1 text-xs text-slate-400">Número da parcela e o vencimento dela.</p>
                        @error('row.anchor_number') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                        @error('row.anchor_due') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $campo }}">Índice de reajuste</label>
                        <select wire:model.live="row.adjustment_index" class="select mt-1.5 w-full">
                            <option value="">Sem reajuste</option>
                            @foreach ($indices as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($row['adjustment_index'] !== '')
                        <div>
                            <label class="{{ $campo }}">Reajustar a cada (meses)</label>
                            <input type="number" wire:model="row.adjustment_every_months" min="1" max="60" class="input mt-1.5">
                            @error('row.adjustment_every_months') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                        </div>
                        <div class="@sm:col-span-2">
                            <label class="{{ $campo }}">Último reajuste <span class="font-normal text-slate-400">(o próximo vem dele)</span></label>
                            <input type="date" wire:model="row.last_adjustment_on" class="input mt-1.5">
                            @error('row.last_adjustment_on') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if (! $existente)
                        <div class="@sm:col-span-2">
                            <label class="{{ $campo }}">Objetivo <span class="font-normal text-slate-400">(opcional): o progresso dele passa a ser pelas parcelas pagas</span></label>
                            <select wire:model.live="row.goal_choice" class="select mt-1.5 w-full">
                                <option value="">Nenhum</option>
                                @foreach ($objetivos as $obj)
                                    <option value="{{ $obj->id }}">{{ $obj->name }}</option>
                                @endforeach
                                <option value="new">Criar um objetivo para este consórcio</option>
                            </select>
                        </div>
                        @if ($row['goal_choice'] === 'new')
                            <div class="@sm:col-span-2">
                                <label class="{{ $campo }}">Nome do objetivo</label>
                                <input type="text" wire:model="row.goal_name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Trocar de carro">
                                @error('row.goal_name') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @endif

                    @if ($membros->count() > 1)
                        <div class="@sm:col-span-2">
                            <label class="{{ $campo }}">De quem é</label>
                            <select wire:model="row.member_id" class="select mt-1.5 w-full">
                                <option value="">Do casal</option>
                                @foreach ($membros as $m)
                                    <option value="{{ $m->id }}">{{ $m->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                <div>
                    <h3 class="text-xs font-semibold text-slate-500 dark:text-slate-400">Parcelas lidas no extrato</h3>
                    @if ($row['skipped'] !== [])
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">Sem pagamento no extrato (ficam como puladas, com o valor diluído nas seguintes): parcela {{ implode(', ', $row['skipped']) }}.</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-400">Pagas lidas: {{ Money::format($row['read_paid']) }}@if ($row['declared_paid'] !== '') · total do extrato {{ Money::format($row['declared_paid']) }} @endif</p>

                    <div class="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-100 dark:border-white/10">
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-white text-left text-slate-400 dark:bg-slate-800">
                                <tr>
                                    <th class="px-2 py-1.5 font-medium">Nº</th>
                                    <th class="px-2 py-1.5 font-medium">Pagamento</th>
                                    <th class="px-2 py-1.5 text-right font-medium">A pagar</th>
                                    <th class="px-2 py-1.5 text-right font-medium">Pago</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700 dark:divide-white/10 dark:text-slate-300">
                                @foreach ($row['installments'] as $p)
                                    <tr wire:key="lida-{{ $p['numero'] }}">
                                        <td class="px-2 py-1 tabular-nums">{{ $p['numero'] }}</td>
                                        <td class="px-2 py-1 tabular-nums">{{ \Carbon\CarbonImmutable::parse($p['pagamento'])->format('d/m/Y') }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums">{{ Money::format($p['valor_a_pagar']) }}</td>
                                        <td class="px-2 py-1 text-right tabular-nums {{ bccomp($p['valor_a_pagar'], $p['valor_pago'], 2) !== 0 ? 'text-amber-700 dark:text-amber-400' : '' }}">{{ Money::format($p['valor_pago']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="text-xs text-slate-400">Estas parcelas passam a constar como pagas com a data e o valor do extrato, sem criar despesa no fluxo de caixa (já foram pagas). O que ainda falta fica em aberto com a parcela atual. O PDF será apagado ao confirmar.</p>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 dark:border-white/10">
                    <button type="button" wire:click="descartar('{{ $reviewingId }}')" wire:confirm="Descartar esta leitura? O PDF enviado será apagado." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Descartar leitura</button>
                    <button type="submit" class="btn-primary px-4 py-2 text-sm" wire:loading.attr="disabled" wire:target="confirmar">{{ $existente ? 'Atualizar consórcio' : 'Cadastrar consórcio' }}</button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
