<?php
    $ehProfissional = $tipo === 'profissional';
    $metodoInicial = old('metodo', \App\Enums\PaymentMethod::CreditCard->value);
    $tetoSelecionado = (string) old('clientes', $tetoInicial);
    $papelSelecionado = old('papel', $papel);
    $moneyFmt = fn ($valor) => \App\Support\Money::format($valor);
    $precosPorTeto = collect($tetos)->map(fn (string $valor) => $moneyFmt($valor))->all();
?>
<x-layouts.marketing
    :title="$ehProfissional ? 'Cerne para profissionais: finalizar pedido' : 'Cerne: '.$pacote->label()"
    :publico="$ehProfissional ? 'profissional' : 'usuario'"
    :checkout="true"
>
    <div
        class="mx-auto max-w-6xl px-4 py-10 sm:px-6 sm:py-14"
        x-data="{
            metodo: @js($metodoInicial),
            teto: @js($tetoSelecionado),
            precos: @js($precosPorTeto),
            precoFixo: @js($ehProfissional ? null : $moneyFmt($precoMensal)),
            get preco() { return this.precoFixo ?? this.precos[this.teto] ?? ''; },
        }"
    >
        <p class="eyebrow">Finalizar pedido</p>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
            {{ $ehProfissional ? 'Cerne para profissionais' : $pacote->label() }}
        </h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Crie sua conta e comece o teste de 7 dias. Nada é cobrado hoje.</p>

        <div class="mt-8 grid gap-6 lg:grid-cols-5">
            {{-- Conta --}}
            <form method="POST" action="{{ route('checkout.store') }}" class="card space-y-5 p-6 lg:col-span-3">
                @csrf
                <input type="hidden" name="tipo" value="{{ $tipo }}">
                @unless ($ehProfissional)
                    <input type="hidden" name="pacote" value="{{ $pacote->value }}">
                @endunless

                <h2 class="font-display text-lg font-semibold text-slate-900 dark:text-white">1. Crie sua conta</h2>

                @if ($ehProfissional)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="papel" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Você é</label>
                            <select id="papel" name="papel" class="input mt-1.5">
                                <option value="consultant" @selected($papelSelecionado === 'consultant')>Consultor financeiro</option>
                                <option value="broker" @selected($papelSelecionado === 'broker')>Corretor de seguros</option>
                            </select>
                            @error('papel') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="clientes" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Quantos clientes vai vincular</label>
                            <select id="clientes" name="clientes" x-model="teto" class="input mt-1.5">
                                @foreach ($tetos as $limite => $valor)
                                    <option value="{{ $limite }}">Até {{ $limite }} clientes</option>
                                @endforeach
                            </select>
                            @error('clientes') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif

                <div>
                    <label for="nome" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Nome</label>
                    <input id="nome" name="nome" type="text" value="{{ old('nome') }}" autocomplete="name" required class="input mt-1.5">
                    @error('nome') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required class="input mt-1.5">
                        @error('email') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="nascimento" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Data de nascimento</label>
                        <input id="nascimento" name="nascimento" type="date" value="{{ old('nascimento') }}" autocomplete="bday" required class="input mt-1.5">
                        @error('nascimento') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required class="input mt-1.5">
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mínimo de 8 caracteres, com letras e números.</p>
                        @error('password') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Confirme a senha</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input mt-1.5">
                    </div>
                </div>

                <div>
                    <h2 class="font-display text-lg font-semibold text-slate-900 dark:text-white">2. Forma de pagamento</h2>
                    <div @class(['mt-3 grid gap-2', 'sm:grid-cols-3' => count(\App\Enums\PaymentMethod::available()) > 2, 'grid-cols-2' => count(\App\Enums\PaymentMethod::available()) <= 2])>
                        @foreach (\App\Enums\PaymentMethod::available() as $metodo)
                            <label
                                :class="metodo === '{{ $metodo->value }}' ? 'border-brand-700 bg-brand-50 text-brand-900 dark:border-brand-500 dark:bg-brand-500/10 dark:text-brand-200' : 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-400'"
                                class="cursor-pointer rounded-lg border px-3 py-3 text-center text-sm font-medium"
                            >
                                <input type="radio" name="metodo" value="{{ $metodo->value }}" x-model="metodo" class="sr-only">
                                {{ $metodo->label() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        Você confirma o pedido depois de validar o e-mail. A primeira cobrança só acontece quando o teste acabar,
                        e você recebe o link de pagamento por e-mail.
                        <span x-show="metodo === 'pix'" x-cloak>No Pix comum, avisamos 3 dias antes de cada vencimento, porque ele não tem débito automático.</span>
                        @if (in_array(\App\Enums\PaymentMethod::PixAutomatic, \App\Enums\PaymentMethod::available(), true))
                        <span x-show="metodo === 'pix_automatic'" x-cloak>No Pix Automático, perto do fim do teste você autoriza o débito no seu banco por um QR Code. O primeiro mês é pago nessa hora e as cobranças seguintes saem sozinhas.</span>
                        @endif
                    </p>
                    @error('metodo') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-400">
                    <input name="termos" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                    <span>Li e aceito os <a href="{{ route('legal.terms') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Termos de Uso</a>.</span>
                </label>
                @error('termos') <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

                <button type="submit" class="btn-primary w-full py-3 text-base">Criar conta e continuar</button>
                <p class="text-center text-xs text-slate-500 dark:text-slate-400">
                    Já tem conta? <a href="{{ route('login') }}" class="text-brand-800 underline dark:text-brand-300">Entrar</a>
                </p>
            </form>

            {{-- Resumo do pedido --}}
            <aside class="order-first lg:order-none lg:col-span-2">
                <div class="card p-6 lg:sticky lg:top-32">
                    <p class="eyebrow">Resumo do pedido</p>

                    <div class="mt-3 flex items-start justify-between gap-4">
                        <div>
                            <p class="font-display text-lg font-semibold text-slate-900 dark:text-white">
                                {{ $ehProfissional ? 'Cerne para profissionais' : $pacote->label() }}
                            </p>
                            @if ($ehProfissional)
                                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Até <span x-text="teto"></span> clientes vinculados</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-right text-lg font-semibold text-slate-900 dark:text-white">
                            <span x-text="preco">{{ $ehProfissional ? ($precosPorTeto[$tetoSelecionado] ?? '') : $moneyFmt($precoMensal) }}</span><span class="text-xs font-normal text-slate-400">/mês</span>
                        </p>
                    </div>

                    <ul class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm text-slate-600 dark:border-white/10 dark:text-slate-300">
                        @if ($ehProfissional)
                            <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> Todas as áreas do Cerne para os seus clientes, sem custo para eles</li>
                            <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> Acompanhamento da carteira dos clientes vinculados, dentro da sua área</li>
                        @else
                            @foreach ($pacote->modules() as $modulo)
                                <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> <span><strong class="text-slate-900 dark:text-white">{{ $modulo->label() }}:</strong> {{ $modulo->summary() }}</span></li>
                            @endforeach
                            <li class="flex gap-2"><span class="text-accent-600 dark:text-accent-400">✓</span> Perfil individual ou de casal, na mesma assinatura</li>
                        @endif
                    </ul>

                    <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-white/10">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600 dark:text-slate-300">Hoje <span class="text-xs text-slate-400">(7 dias grátis)</span></dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $moneyFmt(0) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600 dark:text-slate-300">A partir de {{ $primeiraCobranca->format('d/m/Y') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white"><span x-text="preco">{{ $ehProfissional ? ($precosPorTeto[$tetoSelecionado] ?? '') : $moneyFmt($precoMensal) }}</span>/mês</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600 dark:text-slate-300">Pagamento</dt>
                            <dd class="font-medium text-slate-900 dark:text-white" x-text="{ pix: 'Pix', pix_automatic: 'Pix Automático' }[metodo] ?? 'Cartão de crédito'">Cartão de crédito</dd>
                        </div>
                    </dl>

                    <p class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                        Cancele quando quiser, sem fidelidade. Pagamento processado pela Asaas.
                    </p>

                    <ol class="mt-4 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                        <li><strong class="text-slate-700 dark:text-slate-200">1.</strong> Crie sua conta aqui</li>
                        <li><strong class="text-slate-700 dark:text-slate-200">2.</strong> Confirme seu e-mail</li>
                        <li><strong class="text-slate-700 dark:text-slate-200">3.</strong> Informe o CPF e confirme o pedido</li>
                    </ol>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.marketing>
