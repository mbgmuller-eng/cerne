<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Pessoas que acompanho</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Mãe, pai ou quem mais você ajuda a cuidar: a saúde e os documentos dessa pessoa ficam em um espaço próprio, separado da sua vida financeira.
            Quem cuida junto vê só isso.
        </p>
    </div>

    @if (session('care_status'))
        <div class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-950/40 dark:text-brand-100">{{ session('care_status') }}</div>
    @endif
    @if (session('status'))
        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{{ session('status') }}</div>
    @endif

    {{-- Pessoas que eu cadastrei --}}
    @if ($minhas->isNotEmpty() || $podeCadastrar)
        <section class="card space-y-4 p-5">
            <div class="flex items-center justify-between gap-3">
                <p class="eyebrow">Pessoas que eu cadastrei</p>
                @if ($podeCadastrar && ! $adding)
                    <button type="button" wire:click="startAdding" class="btn-primary px-3 py-2 text-sm">+ Adicionar pessoa</button>
                @endif
            </div>

            @if ($adding)
                <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">Nome</label>
                            <input type="text" wire:model="newName" class="input mt-1 w-full" placeholder="Maria Souza">
                            @error('newName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">Data de nascimento</label>
                            <input type="date" wire:model="newBirthdate" class="input mt-1 w-full">
                            @error('newBirthdate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-medium text-slate-700 dark:text-slate-300">Com que base você cadastra esta pessoa?</p>
                        <label class="mt-2 flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="radio" wire:model="newBasis" value="authorized" class="mt-1">
                            <span>Ela sabe e autorizou o cadastro dos dados de saúde dela.</span>
                        </label>
                        <label class="mt-1 flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="radio" wire:model="newBasis" value="legal_representative" class="mt-1">
                            <span>Ela não tem como decidir sozinha e eu sou o representante legal (procuração ou curatela).</span>
                        </label>
                        @error('newBasis') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="declared" class="mt-1 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                        <span>Declaro que as informações acima são verdadeiras e que usarei os dados dela só para ajudar a cuidar da saúde dela. Ela pode pedir a exclusão a qualquer momento.</span>
                    </label>
                    @error('declared') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

                    <p class="text-xs text-slate-400">Cada pessoa acompanhada soma R$ 4,90 por mês à sua assinatura.</p>

                    <div class="flex gap-2">
                        <button type="button" wire:click="addPerson" class="btn-primary px-3 py-1.5 text-sm">Cadastrar</button>
                        <button type="button" wire:click="cancelAdding" class="btn-secondary px-3 py-1.5 text-sm">Cancelar</button>
                    </div>
                </div>
            @endif

            @if ($minhas->isEmpty() && ! $adding)
                <p class="text-xs text-slate-400">Nenhuma pessoa cadastrada ainda.</p>
            @endif

            <ul class="space-y-3">
                @foreach ($minhas as $pessoa)
                    <li class="rounded-xl border border-slate-100 p-4 dark:border-white/10" wire:key="minha-{{ $pessoa->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-medium text-slate-900 dark:text-white">{{ $pessoa->profile_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $pessoa->caregivers->where('status', \App\Enums\CaregiverStatus::Active)->count() }} cuidador(es) ativo(s)</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('care.open', $pessoa->id) }}">
                                    @csrf
                                    <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Abrir</button>
                                </form>
                                <button type="button" wire:click="manage('{{ $pessoa->id }}')" class="btn-secondary px-3 py-1.5 text-sm">Cuidadores</button>
                            </div>
                        </div>

                        @if ($managingId === $pessoa->id)
                            <div class="mt-4 space-y-4 border-t border-slate-100 pt-4 dark:border-white/10">
                                @if ($pessoa->caregivers->isEmpty())
                                    <p class="text-xs text-slate-400">Ninguém foi convidado ainda.</p>
                                @else
                                    <ul class="space-y-2">
                                        @foreach ($pessoa->caregivers as $cuidador)
                                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="cuidador-{{ $cuidador->id }}">
                                                <div class="min-w-0">
                                                    <p class="font-medium text-slate-800 dark:text-slate-200">{{ $cuidador->invited_name }}</p>
                                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $cuidador->invited_email }} · {{ $cuidador->status->label() }}</p>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <select wire:change="changeAccess('{{ $cuidador->id }}', $event.target.value)" class="input py-1 text-xs">
                                                        @foreach ($accessOptions as $opcao)
                                                            <option value="{{ $opcao->value }}" @selected($cuidador->access_level === $opcao)>{{ $opcao->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="button" wire:click="revoke('{{ $cuidador->id }}')" wire:confirm="Encerrar o acesso de {{ $cuidador->invited_name }}?" class="text-xs text-red-700 underline dark:text-red-400">Encerrar acesso</button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                <div class="space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                                    <p class="text-xs font-medium text-slate-700 dark:text-slate-300">Convidar alguém para cuidar</p>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <div>
                                            <input type="text" wire:model="inviteName" placeholder="Nome" class="input w-full">
                                            @error('inviteName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <input type="email" wire:model="inviteEmail" placeholder="E-mail" class="input w-full">
                                            @error('inviteEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                        </div>
                                        <select wire:model="inviteAccess" class="input w-full">
                                            @foreach ($accessOptions as $opcao)
                                                <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <p class="text-xs text-slate-400">Quem cuida não paga nada e só vê a saúde e os documentos que você liberar.</p>
                                    <button type="button" wire:click="invite" class="btn-primary px-3 py-1.5 text-sm">Enviar convite</button>
                                    @if ($lastInviteLink)
                                        <p class="break-all text-xs text-slate-500 dark:text-slate-400">Se o e-mail não chegar, envie este link: {{ $lastInviteLink }}</p>
                                    @endif
                                </div>

                                <button type="button" wire:click="deletePerson('{{ $pessoa->id }}')"
                                    wire:confirm="Apagar {{ $pessoa->profile_name }} e todos os dados e documentos dela? Isso não pode ser desfeito e os cuidadores perdem o acesso."
                                    class="text-xs text-red-700 underline dark:text-red-400">Excluir esta pessoa e todos os dados dela</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Pessoas que eu acompanho como cuidador --}}
    @if ($acompanho->isNotEmpty())
        <section class="card space-y-3 p-5">
            <p class="eyebrow">Pessoas que me deram acesso</p>
            <ul class="space-y-3">
                @foreach ($acompanho as $pessoa)
                    @php($vinculo = $vinculos[$pessoa->id] ?? null)
                    <li class="rounded-xl border border-slate-100 p-4 dark:border-white/10" wire:key="acompanho-{{ $pessoa->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-medium text-slate-900 dark:text-white">{{ $pessoa->profile_name }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Liberado por {{ $pessoa->owner?->name }} · {{ mb_strtolower($vinculo?->access_level->label() ?? '') }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('care.open', $pessoa->id) }}">
                                @csrf
                                <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Abrir</button>
                            </form>
                        </div>
                        @if ($vinculo)
                            <div class="mt-3 flex flex-wrap items-center gap-4 text-xs">
                                <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                    <input type="checkbox" wire:click="toggleNotify('{{ $vinculo->id }}')" @checked($vinculo->notify_reminders) class="rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                    Receber os avisos de consulta e de troca de item
                                </label>
                                <button type="button" wire:click="leave('{{ $vinculo->id }}')" wire:confirm="Deixar de acompanhar {{ $pessoa->profile_name }}?" class="text-red-700 underline dark:text-red-400">Deixar de acompanhar</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($minhas->isEmpty() && $acompanho->isEmpty() && ! $podeCadastrar)
        <div class="card p-5 text-sm text-slate-500 dark:text-slate-400">
            @if ($recursoLigado)
                Você ainda não acompanha ninguém. Para cadastrar uma pessoa é preciso ter uma assinatura com Saúde; quem for convidado a cuidar não precisa de assinatura.
            @else
                Este recurso ainda não está disponível.
            @endif
        </div>
    @endif

    @unless ($temPerfilProprio)
        <p class="text-xs text-slate-400">Quer organizar a sua própria vida no Cerne? <a href="{{ route('subscription.index') }}" class="underline">Veja os planos</a>.</p>
    @endunless

    @if ($temPerfilProprio)
        <form method="POST" action="{{ route('care.exit') }}">
            @csrf
            <button type="submit" class="btn-secondary px-3 py-2 text-sm">Voltar para minha conta</button>
        </form>
    @endif
</div>
