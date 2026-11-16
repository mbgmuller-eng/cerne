<section class="{{ $standalone ? '' : 'card p-5' }}" id="dois-passos" data-two-factor>
    <p class="text-sm font-semibold text-slate-900 dark:text-white">Verificação em dois passos</p>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
        Além da senha, o login pede um código de 6 dígitos de um aplicativo autenticador no seu celular. Mesmo que descubram a sua senha, ninguém entra sem o aparelho.
        @if ($exigido) <strong>Para a sua função, ele é obrigatório.</strong> @endif
    </p>

    @if ($recoveryCodes !== [])
        <div class="mt-4 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30" data-recovery-codes>
            <p class="text-sm font-medium text-amber-900 dark:text-amber-200">Guarde estes códigos de recuperação agora</p>
            <p class="mt-1 text-xs text-amber-800 dark:text-amber-300">Eles só aparecem esta vez. Cada um entra no lugar do código do aplicativo uma única vez, se você perder o celular.</p>
            <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm text-slate-900 dark:text-white">
                @foreach ($recoveryCodes as $c)
                    <li>{{ $c }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($ativo)
        <p class="mt-4 text-sm text-emerald-700 dark:text-emerald-400">Ligada. Restam {{ $codigosRestantes }} código(s) de recuperação.</p>

        @unless ($exigido)
            <div class="mt-4 grid gap-3 sm:max-w-xs">
                <div>
                    <label for="tf-password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Sua senha</label>
                    <input id="tf-password" type="password" wire:model="password" autocomplete="current-password" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Pedimos a senha para gerar novos códigos ou desligar.</p>
                    @error('password') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="regenerateCodes" class="btn-secondary px-3 py-1.5 text-xs">Gerar novos códigos</button>
                    <button type="button" wire:click="disable" wire:confirm="Desligar a verificação em dois passos?" class="btn-secondary px-3 py-1.5 text-xs">Desligar</button>
                </div>
            </div>
        @else
            <div class="mt-4 grid gap-3 sm:max-w-xs">
                <div>
                    <label for="tf-password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Sua senha</label>
                    <input id="tf-password" type="password" wire:model="password" autocomplete="current-password" class="input mt-1.5">
                    @error('password') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div><button type="button" wire:click="regenerateCodes" class="btn-secondary px-3 py-1.5 text-xs">Gerar novos códigos</button></div>
            </div>
        @endunless
        {{-- Navegadores em que a pessoa escolheu "confiar neste navegador" (o código não é pedido ali até vencer) --}}
        <div class="mt-6 border-t border-slate-100 pt-5 dark:border-white/10" data-trusted-browsers>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Navegadores confiáveis</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Nestes navegadores o código não é pedido até a data de vencimento (a senha continua sendo pedida). A confiança vale {{ $diasConfianca }} dias e é dada na tela do código, marcando "Confiar neste navegador".
                Trocar a senha ou refazer o segundo passo tira todos.
            </p>

            @if ($navegadores->isEmpty())
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400" data-no-trusted>Nenhum navegador confiável.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($navegadores as $n)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="td-{{ $n->id }}" data-trusted-row="{{ $n->id }}">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-800 dark:text-slate-200">{{ $n->label }} @if ($n->id === $navegadorAtual) <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] text-brand-800 dark:bg-brand-500/10 dark:text-brand-300">este navegador</span> @endif</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Confiado em {{ $n->created_at->format('d/m/Y') }} · último uso {{ $n->last_used_at?->format('d/m/Y') ?? 'nunca' }} · vence em {{ $n->expires_at->format('d/m/Y') }}</p>
                            </div>
                            <button type="button" wire:click="revokeDevice('{{ $n->id }}')" wire:confirm="Tirar este navegador? Nele o código volta a ser pedido." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Tirar</button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-2">
                    <button type="button" wire:click="revokeAllDevices" wire:confirm="Tirar todos os navegadores confiáveis?" class="btn-secondary px-3 py-1.5 text-xs" data-revoke-all>Tirar todos</button>
                </div>
            @endif
        </div>
    @elseif ($segredo !== null)
        <div class="mt-4 space-y-3 sm:max-w-xs">
            <p class="text-sm text-slate-700 dark:text-slate-300">1. No aplicativo autenticador (Google Authenticator, Microsoft Authenticator, 1Password, Authy), leia este QR Code.</p>
            <div class="inline-block rounded-xl bg-white p-2" data-two-factor-qr>{!! $qr !!}</div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Se não der para ler, digite esta chave no aplicativo: <span class="font-mono text-slate-900 dark:text-white" data-two-factor-secret>{{ $segredo }}</span></p>
            <p class="text-sm text-slate-700 dark:text-slate-300">2. Digite o código de 6 dígitos que o aplicativo mostra.</p>
            <form wire:submit="confirmSetup" class="flex items-start gap-2">
                <div>
                    <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="code" maxlength="12" class="input w-36" aria-label="Código de 6 dígitos">
                    @error('code') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary px-4 py-2 text-sm" wire:loading.attr="disabled">Ligar</button>
            </form>
            <button type="button" wire:click="cancelSetup" class="text-xs text-slate-500 underline dark:text-slate-400">Cancelar</button>
        </div>
    @else
        <div class="mt-4">
            <button type="button" wire:click="startSetup" class="btn-primary px-4 py-2 text-sm">Configurar</button>
        </div>
    @endif
</section>
