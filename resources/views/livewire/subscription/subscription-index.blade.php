<div class="mx-auto max-w-2xl space-y-6" @if ($aguardandoPagamento) wire:poll.5s="atualizarPagamento" @endif>

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Assinatura</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            @if ($precisaPagar && ! $temAcessoAtivo)
                Seu acesso gratuito terminou. Escolha como pagar para continuar.
            @elseif ($precisaPagar)
                Você está no teste grátis. Escolha como pagar quando quiser.
            @elseif ($resumoDoPedido)
                Confira o seu pedido e confirme para começar o teste grátis.
            @else
                Escolha o pacote que cobre o que você precisa.
            @endif
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            {{ session('status') }}
        </div>
    @endif

    @if ($assinaturaAtual)
        <section class="card space-y-3 p-5">
            <p class="eyebrow">Sua assinatura</p>
            <x-subscription-summary :subscription="$assinaturaAtual">
                <x-slot:actions>
                    {{-- Pix Automático: autorização do débito mensal. O QR aparece aqui depois de a
                         pessoa escolher essa forma no pagamento. --}}
                    @if ($assinaturaAtual->billing_type === \App\Enums\PaymentMethod::PixAutomatic && $assinaturaAtual->status !== \App\Enums\SubscriptionStatus::Cancelled)
                        <div class="mt-3 space-y-3 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                            @if ($assinaturaAtual->hasActivePixAuthorization())
                                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">Débito automático ativo</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    As cobranças mensais são debitadas da sua conta pelo Pix Automático, sem nenhuma ação sua.
                                    Para interromper, cancele a assinatura aqui ou a autorização no aplicativo do seu banco.
                                </p>
                            @else
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">Débito automático por Pix</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Você paga o primeiro mês ({{ \App\Support\Money::format($assinaturaAtual->monthlyPrice()) }}) por um QR Code.
                                    Esse pagamento também autoriza o seu banco a debitar as cobranças seguintes sozinho.
                                    Você pode cancelar a autorização quando quiser.
                                </p>

                                @if (in_array($assinaturaAtual->pix_authorization_status, ['REFUSED', 'CANCELLED', 'EXPIRED'], true))
                                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                        A autorização anterior não foi concluída. Gere um novo QR Code para tentar de novo.
                                    </p>
                                @endif

                                <button type="button" wire:click="ativarDebitoAutomatico" wire:loading.attr="disabled" class="btn-primary">
                                    {{ $pixQr ? 'Gerar um novo QR Code' : 'Pagar o primeiro mês e ativar' }}
                                </button>

                                @if ($pixQr)
                                    {{-- Enquanto o QR está aberto, confere a ativação a cada 5 segundos. --}}
                                    <div wire:poll.5s="atualizarAtivacao" class="space-y-3 border-t border-slate-100 pt-3 dark:border-white/10" x-data="{ copiado: false }">
                                        @if ($pixQr['qrImage'])
                                            <img src="data:image/png;base64,{{ $pixQr['qrImage'] }}" alt="QR Code do Pix Automático" class="mx-auto h-48 w-48 rounded-lg bg-white p-2 ring-1 ring-slate-200">
                                        @endif

                                        @if ($pixQr['payload'])
                                            <div>
                                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pix copia e cola</label>
                                                <textarea x-ref="codigo" readonly rows="3" class="input mt-1.5 text-xs">{{ $pixQr['payload'] }}</textarea>
                                                <button type="button" class="btn-secondary mt-2 px-3 py-1.5 text-xs"
                                                    @click="navigator.clipboard.writeText($refs.codigo.value); copiado = true; setTimeout(() => copiado = false, 2000)">
                                                    <span x-show="!copiado">Copiar código</span>
                                                    <span x-show="copiado" x-cloak>Código copiado</span>
                                                </button>
                                            </div>
                                        @endif

                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            @if ($pixQr['expiresAt'])
                                                O QR Code vale até {{ \Illuminate\Support\Carbon::parse($pixQr['expiresAt'])->format('d/m/Y \à\s H:i') }}.
                                            @endif
                                            Abra o aplicativo do seu banco, pague e aceite o débito automático. Esta tela atualiza sozinha quando o banco confirmar.
                                        </p>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    @if ($assinaturaAtual->status !== \App\Enums\SubscriptionStatus::Cancelled)
                        @if ($souProfissional && $assinaturaAtual->client_cap !== null && $temAcessoAtivo)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Até {{ $assinaturaAtual->client_cap }} clientes vinculados.</p>
                        @elseif ($souProfissional && $assinaturaAtual->client_cap === null && $temAcessoAtivo)
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Sem limite de clientes vinculados.</p>
                        @endif
                        <button
                            type="button"
                            wire:click="cancelar"
                            wire:confirm="Cancelar sua assinatura? O acesso é encerrado na hora, sem prorata do período já pago."
                            wire:loading.attr="disabled"
                            class="btn-ghost mt-2 px-0 text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                        >
                            Cancelar assinatura
                        </button>
                    @endif
                </x-slot:actions>
            </x-subscription-summary>
        </section>

        {{-- Pagamento: aparece enquanto a assinatura ainda não está paga, durante o teste ou
             depois dele (quando o acesso já está travado). --}}
        @if ($precisaPagar)
            <section class="card space-y-4 p-5">
                <p class="eyebrow">{{ $temAcessoAtivo ? 'Pagar agora' : 'Pagar para continuar' }}</p>

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-display text-lg font-semibold text-slate-900 dark:text-white">
                            {{ $souProfissional ? 'Cerne para profissionais' : $assinaturaAtual->bundle->label() }}
                        </p>
                        @if ($souProfissional && $assinaturaAtual->client_cap !== null)
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Até {{ $assinaturaAtual->client_cap }} clientes vinculados</p>
                        @endif
                    </div>
                    <p class="shrink-0 text-right text-lg font-semibold text-slate-900 dark:text-white">
                        {{ \App\Support\Money::format($assinaturaAtual->monthlyPrice()) }}<span class="text-xs font-normal text-slate-400">/mês</span>
                    </p>
                </div>

                @if ($temAcessoAtivo)
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Se você pagar agora, os dias de teste que sobram continuam valendo: o primeiro mês começa a contar em {{ $assinaturaAtual->current_period_ends_at->format('d/m/Y') }}.
                    </p>
                @endif

                @if ($assinaturaAtual->billing_type === \App\Enums\PaymentMethod::PixAutomatic)
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Você escolheu o débito automático por Pix. Gere o QR Code na caixa acima para autorizar.
                    </p>
                    <button type="button" wire:click="trocarFormaDePagamento" class="text-xs text-brand-700 underline dark:text-brand-300">Escolher outra forma de pagamento</button>
                @elseif ($assinaturaAtual->asaas_subscription_id !== null)
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Sua cobrança por {{ $assinaturaAtual->billing_type?->label() ?? 'forma escolhida' }} foi gerada. Pague pela fatura da Asaas; esta tela atualiza sozinha quando o pagamento for confirmado.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="abrirFatura" wire:loading.attr="disabled" class="btn-primary">
                            <span wire:loading.remove wire:target="abrirFatura">Abrir fatura</span>
                            <span wire:loading wire:target="abrirFatura">Abrindo...</span>
                        </button>
                        <button type="button" wire:click="trocarFormaDePagamento" wire:loading.attr="disabled" class="text-xs text-brand-700 underline dark:text-brand-300">Escolher outra forma de pagamento</button>
                    </div>
                @else
                    <div class="space-y-3 border-t border-slate-100 pt-4 dark:border-white/10">
                        <div>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Dados para a nota fiscal</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Usamos estes dados para emitir a nota fiscal da sua assinatura. Eles ficam guardados no Cerne e são enviados
                                à Asaas, nossa processadora de pagamento.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome completo ou razão social</label>
                                <input type="text" wire:model="fiscalNome" autocomplete="name" class="input mt-1.5" maxlength="150">
                                @error('fiscalNome') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CPF ou CNPJ</label>
                                <input type="text" wire:model="cpfCnpj" inputmode="numeric" class="input mt-1.5" placeholder="Só números" maxlength="18">
                                @error('cpfCnpj') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data de nascimento <span class="font-normal text-slate-400">(pessoa física)</span></label>
                                <input type="date" wire:model="fiscalNascimento" autocomplete="bday" class="input mt-1.5">
                                @error('fiscalNascimento') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CEP</label>
                                <input type="text" wire:model="cep" inputmode="numeric" autocomplete="postal-code" class="input mt-1.5" placeholder="00000-000" maxlength="9">
                                @error('cep') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Número</label>
                                <input type="text" wire:model="numero" class="input mt-1.5" placeholder="Ou S/N" maxlength="20">
                                @error('numero') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Rua</label>
                                <input type="text" wire:model="rua" autocomplete="address-line1" class="input mt-1.5" maxlength="120">
                                @error('rua') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Complemento <span class="font-normal text-slate-400">(opcional)</span></label>
                                <input type="text" wire:model="complemento" class="input mt-1.5" maxlength="60">
                                @error('complemento') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Bairro</label>
                                <input type="text" wire:model="bairro" class="input mt-1.5" maxlength="80">
                                @error('bairro') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cidade</label>
                                <input type="text" wire:model="cidade" autocomplete="address-level2" class="input mt-1.5" maxlength="80">
                                @error('cidade') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">UF</label>
                                <select wire:model="uf" class="input mt-1.5">
                                    <option value="">Escolha</option>
                                    @foreach (\App\Models\BillingDetail::STATES as $sigla)
                                        <option value="{{ $sigla }}">{{ $sigla }}</option>
                                    @endforeach
                                </select>
                                @error('uf') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Forma de pagamento</label>
                        <div @class(['mt-1.5 grid gap-2', 'sm:grid-cols-3' => count($metodos) > 2, 'grid-cols-2' => count($metodos) <= 2])>
                            @foreach ($metodos as $metodo)
                                <label @class([
                                    'cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-medium',
                                    'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-200' => $metodoPagamento === $metodo->value,
                                    'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-400' => $metodoPagamento !== $metodo->value,
                                ])>
                                    <input type="radio" wire:model.live="metodoPagamento" value="{{ $metodo->value }}" class="sr-only">
                                    {{ $metodo->label() }}
                                </label>
                            @endforeach
                        </div>
                        @if ($metodoPagamento === 'pix')
                            <p class="mt-1.5 text-xs text-slate-400">Você paga a fatura de cada mês pelo Pix. Avisamos por e-mail 3 dias antes de cada vencimento.</p>
                        @elseif ($metodoPagamento === 'credit_card')
                            <p class="mt-1.5 text-xs text-slate-400">Você paga a fatura com o cartão na página da Asaas. Os dados do cartão não passam pelo Cerne.</p>
                        @elseif ($metodoPagamento === 'pix_automatic')
                            <p class="mt-1.5 text-xs text-slate-400">Você autoriza o débito no seu banco por um QR Code. O primeiro mês é pago nessa hora e as cobranças seguintes saem sozinhas.</p>
                        @endif
                        @error('metodoPagamento') <p class="mt-1.5 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <button type="button" wire:click="iniciarPagamento" wire:loading.attr="disabled" class="btn-primary w-full py-3 text-base">
                        <span wire:loading.remove wire:target="iniciarPagamento">Ir para o pagamento</span>
                        <span wire:loading wire:target="iniciarPagamento">Gerando a cobrança...</span>
                    </button>
                    <p class="text-center text-xs text-slate-400">Cancele quando quiser, sem fidelidade. Pagamento processado pela Asaas.</p>

                    <button type="button" wire:click="trocarPlano" class="mx-auto block text-xs text-brand-700 underline dark:text-brand-300">Trocar de plano</button>
                @endif
            </section>
        @endif

        {{-- Aumentar limite: só quem já tem teto (cortesia e contrato
             especial têm client_cap nulo, não tem o que "aumentar"). --}}
        @if ($temAcessoAtivo && $souProfissional && $assinaturaAtual->client_cap !== null)
            <section class="card space-y-3 p-5">
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Aumentar limite de clientes</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    O acesso aos clientes novos é imediato. O novo valor passa a valer a partir da próxima cobrança.
                </p>

                <div class="grid gap-2 @sm:grid-cols-2">
                    @foreach ($tetosClientes as $teto)
                        @continue($teto <= $assinaturaAtual->client_cap)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700" wire:key="teto-upgrade-{{ $teto }}">
                            <div>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Até {{ $teto }} clientes</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ \App\Support\Money::format(\App\Support\ProfessionalPricing::priceFor($teto)) }}/mês</p>
                            </div>
                            <button
                                type="button"
                                wire:click="aumentarFaixa({{ $teto }})"
                                wire:confirm="Aumentar para até {{ $teto }} clientes? A partir da próxima cobrança o valor passa a ser {{ \App\Support\Money::format(\App\Support\ProfessionalPricing::priceFor($teto)) }}/mês."
                                wire:loading.attr="disabled"
                                class="btn-secondary shrink-0 px-3 py-1.5 text-xs"
                            >
                                Escolher
                            </button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    @if ($mostrarPlanos)
        <div class="space-y-4">
            @if (! $assinaturaAtual)
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
                    7 dias grátis, sem pedir forma de pagamento. Você escolhe como pagar só perto do fim do teste.
                </p>
            @else
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                    O teste grátis é um só por conta. Escolha o plano e depois como pagar para liberar o acesso.
                </p>
            @endif

            @if ($trocandoPlano && ($temIntencao || $precisaPagar))
                <button type="button" wire:click="voltarAoPedido" class="text-xs text-brand-700 underline dark:text-brand-300">Voltar</button>
            @endif

            @if ($souProfissional && ! $resumoDoPedido)
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Seus clientes vinculados e ativos recebem todas as áreas do Cerne, sem pagar nada. O valor depende só de quantos clientes você vincula.
                </p>
            @endif

            @if ($resumoDoPedido && ! $trocandoPlano)
                @php
                    $valorMensal = $souProfissional
                        ? \App\Support\ProfessionalPricing::priceFor((int) $clientCap)
                        : (string) config('billing.prices.'.$pacoteDoPedido->value);
                @endphp
                <section class="card p-5">
                    <p class="eyebrow">Resumo do pedido</p>
                    <div class="mt-3 flex items-start justify-between gap-4">
                        <div>
                            @if ($souProfissional)
                                <p class="font-display text-lg font-semibold text-slate-900 dark:text-white">Cerne para profissionais</p>
                                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Até {{ $clientCap }} clientes vinculados</p>
                            @else
                                <p class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $pacoteDoPedido->label() }}</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-right text-lg font-semibold text-slate-900 dark:text-white">
                            {{ \App\Support\Money::format($valorMensal) }}<span class="text-xs font-normal text-slate-400">/mês</span>
                        </p>
                    </div>

                    <ul class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">
                        @if ($souProfissional)
                            <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> Todas as áreas do Cerne para os seus clientes, sem custo para eles</li>
                        @else
                            @foreach ($pacoteDoPedido->modules() as $modulo)
                                <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> <span><strong class="text-slate-900 dark:text-white">{{ $modulo->label() }}:</strong> {{ $modulo->summary() }}</span></li>
                            @endforeach
                        @endif
                    </ul>

                    <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-white/10">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600 dark:text-slate-300">Hoje <span class="text-xs text-slate-400">(7 dias grátis)</span></dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ \App\Support\Money::format(0) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600 dark:text-slate-300">A partir de {{ $fimDoTeste->format('d/m/Y') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ \App\Support\Money::format($valorMensal) }}/mês</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-slate-400">Cancele quando quiser, sem fidelidade. O acesso é encerrado na hora do cancelamento.</p>
                </section>

                <button type="button" wire:click="assinar('{{ $souProfissional ? \App\Enums\SubscriptionBundle::Completo->value : $pacoteDoPedido->value }}')" wire:loading.attr="disabled" class="btn-primary w-full py-3 text-base">
                    <span wire:loading.remove wire:target="assinar">{{ $assinaturaAtual ? 'Confirmar o plano' : 'Confirmar e começar 7 dias grátis' }}</span>
                    <span wire:loading wire:target="assinar">Criando a sua assinatura...</span>
                </button>
                <p class="text-center text-xs text-slate-400">Nada é cobrado agora e você não precisa escolher a forma de pagamento ainda. Avisamos 3 dias antes de o teste acabar.</p>
                <button type="button" wire:click="trocarPlano" class="mx-auto block text-xs text-brand-700 underline dark:text-brand-300">Escolher outro plano</button>
            @elseif ($souProfissional)
                <div class="card space-y-4 p-5">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantos clientes você vai vincular</label>
                        <select wire:model.live="clientCap" class="input mt-1.5">
                            <option value="">Escolha</option>
                            @foreach ($tetosClientes as $teto)
                                <option value="{{ $teto }}">Até {{ $teto }} clientes · {{ \App\Support\Money::format(\App\Support\ProfessionalPricing::priceFor($teto)) }}/mês</option>
                            @endforeach
                        </select>
                        @error('clientCap') <p class="mt-1.5 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <button type="button" wire:click="assinar('{{ \App\Enums\SubscriptionBundle::Completo->value }}')" wire:loading.attr="disabled" class="btn-primary w-full">
                        <span wire:loading.remove wire:target="assinar">{{ $assinaturaAtual ? 'Confirmar o plano' : 'Começar 7 dias grátis' }}</span>
                        <span wire:loading wire:target="assinar">Criando...</span>
                    </button>
                </div>
            @else
                <div class="grid gap-3 @sm:grid-cols-3">
                    @foreach ($bundles as $pacote)
                        <div class="card flex flex-col gap-3 p-5" wire:key="pacote-{{ $pacote->value }}">
                            <div>
                                <p class="font-display text-lg font-semibold text-slate-900 dark:text-white">{{ $pacote->label() }}</p>
                                <ul class="mt-2 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @foreach ($pacote->modules() as $modulo)
                                        <li>{{ $modulo->label() }}</li>
                                    @endforeach
                                </ul>
                            </div>
                            <p class="mt-auto font-display text-2xl font-semibold text-slate-900 dark:text-white">
                                {{ \App\Support\Money::format(config('billing.prices.'.$pacote->value)) }}
                                <span class="text-xs font-normal text-slate-400">/mês</span>
                            </p>
                            <button type="button" wire:click="assinar('{{ $pacote->value }}')" wire:loading.attr="disabled" class="btn-primary w-full">
                                <span wire:loading.remove wire:target="assinar">{{ $assinaturaAtual ? 'Escolher' : 'Começar 7 dias grátis' }}</span>
                                <span wire:loading wire:target="assinar">Criando...</span>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
