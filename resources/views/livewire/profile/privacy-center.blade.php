<section class="card p-5" id="privacidade">
    <p class="text-sm font-semibold text-slate-900 dark:text-white">Privacidade e seus dados</p>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Seus direitos pela LGPD: ter uma cópia dos seus dados, revogar o consentimento e apagar a conta. Mais detalhes na
        <a href="{{ route('legal.privacy') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Política de Privacidade</a>.
    </p>

    @if (session('privacy_status'))
        <div class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-950/40 dark:text-brand-100">{{ session('privacy_status') }}</div>
    @endif

    {{-- Exclusão agendada: o aviso vem primeiro, porque é o que mais importa ver. --}}
    @if ($user->deletion_due_at)
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
            <p class="font-medium">Sua conta será apagada em {{ $user->deletion_due_at->format('d/m/Y') }}.</p>
            <p class="mt-1">Depois dessa data não há como recuperar nada. Mudou de ideia?</p>
            <button type="button" wire:click="cancelDeletion" class="btn-secondary mt-2 px-3 py-1.5 text-sm">Cancelar a exclusão</button>
        </div>
    @endif

    <div class="mt-4 space-y-5">
        {{-- Baixar meus dados --}}
        <div>
            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Baixar meus dados</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Um arquivo com tudo o que você pode ver no Cerne (cadastro, lançamentos, investimentos, documentos...), em formato aberto. Você recebe o link por e-mail, válido por 7 dias.
            </p>

            @if ($latestExport?->isAvailable())
                <a href="{{ URL::temporarySignedRoute('privacy.export.download', $latestExport->expires_at, ['export' => $latestExport->id]) }}" class="btn-secondary mt-2 inline-block px-3 py-1.5 text-sm">
                    Baixar a cópia pronta ({{ number_format($latestExport->size_bytes / 1048576, 1, ',', '.') }} MB, vale até {{ $latestExport->expires_at->format('d/m') }})
                </a>
            @elseif ($latestExport?->status === \App\Models\DataExport::PENDING)
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Sua cópia está sendo preparada. Avisamos por e-mail quando ficar pronta.</p>
            @else
                <button type="button" wire:click="requestExport" class="btn-secondary mt-2 px-3 py-1.5 text-sm">Pedir uma cópia dos meus dados</button>
            @endif
            @error('export') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- Consentimento de saúde --}}
        @if ($isHealthUser)
            <div>
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Consentimento para dados de saúde</p>
                @if ($user->hasCurrentHealthConsent())
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Dado em {{ $user->health_consent_at->format('d/m/Y') }}. Ao revogar, a área de Saúde fecha e os avisos de saúde param; seus dados continuam guardados até você apagar a conta.
                    </p>
                    <button type="button" wire:click="revokeHealthConsent" wire:confirm="Revogar o consentimento fecha a área de Saúde. Continuar?" class="btn-secondary mt-2 px-3 py-1.5 text-sm">Revogar consentimento</button>
                @else
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Você não deu o consentimento, então a área de Saúde está fechada. Ele é pedido na primeira vez que você abrir Saúde.</p>
                @endif
            </div>
        @endif

        {{-- Excluir a conta --}}
        @unless ($user->deletion_due_at)
            <div>
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Excluir minha conta</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Apaga a conta e todos os dados e arquivos ligados a ela, e cancela a assinatura. Há {{ \App\Livewire\Profile\PrivacyCenter::GRACE_DAYS }} dias de carência para desistir. Não dá para recuperar depois.
                </p>

                @if (! $confirmingDeletion)
                    <button type="button" wire:click="$set('confirmingDeletion', true)" class="mt-2 rounded-lg border border-red-300 px-3 py-1.5 text-sm text-red-800 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950/30">Quero excluir minha conta</button>
                @else
                    <div class="mt-3 space-y-3 rounded-lg border border-red-200 p-3 dark:border-red-900">
                        @if ($carePeople !== [])
                            <div class="rounded-md bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                                Você cadastrou {{ implode(', ', $carePeople) }}. Apagar a sua conta apaga também esse espaço, com os dados de saúde, os documentos e o acesso de quem cuida junto com você.
                                <label class="mt-2 flex items-start gap-2">
                                    <input type="checkbox" wire:model="understandsShared" class="mt-0.5 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                    <span>Entendo e quero apagar também essas pessoas acompanhadas.</span>
                                </label>
                                @error('understandsShared') <p class="mt-1 text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        @if ($sharedWith !== [])
                            <div class="rounded-md bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                                Você é o titular de um perfil que {{ implode(', ', $sharedWith) }} também usa. Apagar a sua conta apaga esse perfil inteiro, inclusive o que essa pessoa lançou.
                                <label class="mt-2 flex items-start gap-2">
                                    <input type="checkbox" wire:model="understandsShared" class="mt-0.5 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                    <span>Entendo e quero apagar o perfil compartilhado.</span>
                                </label>
                                @error('understandsShared') <p class="mt-1 text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div>
                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">Sua senha</label>
                            <input type="password" wire:model="deletePassword" autocomplete="current-password" class="input mt-1 w-full">
                            @error('deletePassword') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">Digite o e-mail da conta para confirmar</label>
                            <input type="email" wire:model="deleteEmail" placeholder="{{ $user->email }}" autocomplete="off" class="input mt-1 w-full">
                            @error('deleteEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex gap-2">
                            <button type="button" wire:click="startDeletion" class="rounded-lg bg-red-700 px-3 py-1.5 text-sm text-white hover:bg-red-800">Agendar a exclusão</button>
                            <button type="button" wire:click="$set('confirmingDeletion', false)" class="btn-secondary px-3 py-1.5 text-sm">Voltar</button>
                        </div>
                    </div>
                @endif
            </div>
        @endunless
    </div>
</section>
