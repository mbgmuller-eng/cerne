<div class="space-y-6">

    @php($soMeuEspaco = $meusEspacos->isNotEmpty() && $minhas->isEmpty() && $acompanho->isEmpty())

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $soMeuEspaco ? 'Meu espaço de saúde' : 'Pessoas e acessos' }}</h1>
        @unless ($soMeuEspaco)
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Convide quem acompanha a sua saúde e cuide da saúde de quem você ajuda (mãe, pai ou quem mais): a saúde e os documentos ficam separados da vida financeira,
                e quem acompanha vê só isso.
            </p>
        @endunless
    </div>

    @if (session('care_status'))
        <div class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:bg-brand-950/40 dark:text-brand-100">{{ session('care_status') }}</div>
    @endif
    @if (session('status'))
        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{{ session('status') }}</div>
    @endif

    {{-- Quem me acompanha: convidar alguém para ver a MINHA saúde. Não exige assinatura. --}}
    @if ($recursoLigado)
        @foreach ($meusMembros as $eu)
            <section class="card space-y-4 p-5" wire:key="seguidores-{{ $eu->id }}">
                <div>
                    <p class="eyebrow">Quem me acompanha</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Convide alguém de confiança (um filho, um irmão, um amigo) para ver a sua ficha de saúde, a agenda, os cuidados e os documentos que você liberar.
                        Quem acompanha não vê nada de finanças{{ $eu->profile && $eu->profile->members()->where('is_active', true)->count() > 1 ? ' nem a saúde de quem divide o perfil com você' : '' }}, e você encerra o acesso quando quiser. Não precisa de assinatura.
                    </p>
                </div>

                @if ($eu->followers->isEmpty())
                    <p class="text-xs text-slate-400">Ninguém acompanha a sua saúde ainda.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($eu->followers as $seguidor)
                            <li class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="seguidor-{{ $seguidor->id }}">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-800 dark:text-slate-200">{{ $seguidor->invited_name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $seguidor->invited_email }} · {{ $seguidor->status->label() }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <select wire:change="changeAccess('{{ $seguidor->id }}', $event.target.value)" class="input py-1 text-xs">
                                        @foreach ($accessOptions as $opcao)
                                            <option value="{{ $opcao->value }}" @selected($seguidor->access_level === $opcao)>{{ $opcao->label() }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="revoke('{{ $seguidor->id }}')" wire:confirm="Encerrar o acesso de {{ $seguidor->invited_name }}?" class="text-xs text-red-700 underline dark:text-red-400">Encerrar acesso</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                    <p class="text-xs font-medium text-slate-700 dark:text-slate-300">Convidar alguém para me acompanhar</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <div>
                            <input type="text" wire:model="followName" placeholder="Nome" class="input w-full">
                            @error('followName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <input type="email" wire:model="followEmail" placeholder="E-mail" class="input w-full">
                            @error('followEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <select wire:model="followAccess" class="input w-full">
                            @foreach ($accessOptions as $opcao)
                                <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="inviteFollower('{{ $eu->id }}')" class="btn-primary px-3 py-1.5 text-sm">Enviar convite</button>
                    @if ($lastFollowLink)
                        <p class="break-all text-xs text-slate-500 dark:text-slate-400">Se o e-mail não chegar, envie este link: {{ $lastFollowLink }}</p>
                    @endif
                </div>
            </section>
        @endforeach
    @endif
    {{-- O meu próprio espaço (quando eu sou a pessoa acompanhada) --}}
    @foreach ($meusEspacos as $espaco)
        <section class="card space-y-4 p-5" wire:key="espaco-{{ $espaco->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="eyebrow">O meu espaço de saúde</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Aqui ficam as suas consultas, remédios, alergias e documentos. Você manda em quem tem acesso.</p>
                </div>
                <form method="POST" action="{{ route('care.open', $espaco->id) }}">
                    @csrf
                    <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Abrir</button>
                </form>
            </div>

            <div>
                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">Quem tem acesso</p>
                <ul class="mt-2 space-y-2 text-sm">
                    <li class="flex flex-wrap items-center justify-between gap-2">
                        <span class="text-slate-700 dark:text-slate-300">{{ $espaco->owner?->name }} <span class="text-xs text-slate-400">· criou o espaço e é responsável por ele</span></span>
                    </li>
                    @foreach ($espaco->caregivers as $cuidador)
                        <li class="flex flex-wrap items-center justify-between gap-2" wire:key="meu-cuidador-{{ $cuidador->id }}">
                            <span class="text-slate-700 dark:text-slate-300">{{ $cuidador->invited_name }} <span class="text-xs text-slate-400">· {{ mb_strtolower($cuidador->access_level->label()) }}</span></span>
                            <button type="button" wire:click="revokeAsPerson('{{ $cuidador->id }}')" wire:confirm="Encerrar o acesso de {{ $cuidador->invited_name }}?" class="text-xs text-red-700 underline dark:text-red-400">Encerrar acesso</button>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="flex flex-wrap items-center gap-4 border-t border-slate-100 pt-3 text-xs dark:border-white/10">
                @if ($espaco->care_consent_at)
                    <button type="button" wire:click="revokeMyConsent('{{ $espaco->id }}')" wire:confirm="Revogar o consentimento fecha a saúde deste espaço e para os avisos. Continuar?" class="text-slate-600 underline dark:text-slate-300">Revogar o meu consentimento</button>
                @else
                    <button type="button" wire:click="grantMyConsent('{{ $espaco->id }}')" class="text-slate-600 underline dark:text-slate-300">Dar o consentimento de novo</button>
                @endif
                <button type="button" wire:click="endMySpace('{{ $espaco->id }}')"
                    wire:confirm="Apagar o seu espaço e todos os seus dados e documentos? Isso não pode ser desfeito e quem cuida de você perde o acesso."
                    class="text-red-700 underline dark:text-red-400">Apagar o meu espaço e todos os meus dados</button>
            </div>
        </section>
    @endforeach

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

                    <p class="text-xs text-slate-400">Cada pessoa acompanhada soma {{ \App\Support\Money::format(\App\Services\CareBillingService::seatPrice()) }} por mês à sua assinatura, a partir da próxima cobrança. Quem cuida junto não paga nada.</p>

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
                                <p class="text-xs text-slate-500 dark:text-slate-400">@if ($pessoa->members->first()?->user_id) Tem acesso próprio · @endif{{ $pessoa->caregivers->where('status', \App\Enums\CaregiverStatus::Active)->count() }} cuidador(es) ativo(s) · {{ \App\Support\Money::format(\App\Services\CareBillingService::seatPrice()) }}/mês</p>
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

                                @unless ($pessoa->members->first()?->user_id)
                                    <div class="space-y-2 rounded-lg bg-slate-50 p-3 dark:bg-white/5">
                                        <p class="text-xs font-medium text-slate-700 dark:text-slate-300">Convidar {{ $pessoa->profile_name }} para usar o app</p>
                                        <p class="text-xs text-slate-400">Ela passa a ver o que está registrado, quem tem acesso e pode encerrar o acesso de cada pessoa. Você continua responsável pelo espaço.</p>
                                        <div class="flex flex-wrap gap-2">
                                            <input type="email" wire:model="selfEmail" placeholder="E-mail dela" class="input min-w-0 flex-1">
                                            <button type="button" wire:click="inviteSelf" class="btn-secondary px-3 py-1.5 text-sm">Enviar convite</button>
                                        </div>
                                        @error('selfEmail') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                                        @if ($lastSelfLink)
                                            <p class="break-all text-xs text-slate-500 dark:text-slate-400">Se o e-mail não chegar, envie este link: {{ $lastSelfLink }}</p>
                                        @endif
                                    </div>
                                @endunless

                                <button type="button" wire:click="deletePerson('{{ $pessoa->id }}')"
                                    wire:confirm="Apagar {{ $pessoa->profile_name }} e todos os dados e documentos dela? Isso não pode ser desfeito, os cuidadores perdem o acesso e a cobrança dela sai a partir da próxima cobrança."
                                    class="text-xs text-red-700 underline dark:text-red-400">Excluir esta pessoa e todos os dados dela</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Pessoas que eu acompanho como cuidador (um acesso por pessoa) --}}
    @if ($acompanho->isNotEmpty())
        <section class="card space-y-3 p-5">
            <p class="eyebrow">Pessoas que me deram acesso</p>
            <ul class="space-y-3">
                @foreach ($acompanho as $vinculo)
                    @php($nome = $vinculo->member?->name ?? $vinculo->profile?->profile_name)
                    <li class="rounded-xl border border-slate-100 p-4 dark:border-white/10" wire:key="acompanho-{{ $vinculo->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-medium text-slate-900 dark:text-white">{{ $nome }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Liberado por {{ $vinculo->invitedBy?->name }} · {{ mb_strtolower($vinculo->access_level->label()) }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('care.open', $vinculo->profile_id) }}">
                                @csrf
                                <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Abrir</button>
                            </form>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-4 text-xs">
                            <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                <input type="checkbox" wire:click="toggleNotify('{{ $vinculo->id }}')" @checked($vinculo->notify_reminders) class="rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                Avisos de consultas e exames (lembretes e mudanças)
                            </label>
                            <label class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                <input type="checkbox" wire:click="toggleNotifyItems('{{ $vinculo->id }}')" @checked($vinculo->notify_care_items) class="rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                                Avisos de troca de itens de cuidado (lembretes e mudanças)
                            </label>
                            <button type="button" wire:click="leave('{{ $vinculo->id }}')" wire:confirm="Deixar de acompanhar {{ $nome }}?" class="text-red-700 underline dark:text-red-400">Deixar de acompanhar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($minhas->isEmpty() && $acompanho->isEmpty() && $meusEspacos->isEmpty() && ! $podeCadastrar)
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
