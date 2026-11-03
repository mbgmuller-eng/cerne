<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Acesso da família</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Libere o acesso de um familiar de {{ $perfil->profile_name }} à saúde e/ou às finanças (só para ver), em nome de quem não consegue fazer isso sozinho.
            Fica registrado quem liberou, quando e com que base, as pessoas são avisadas e encerram o acesso quando quiserem.
        </p>
    </div>

    @if (session('status'))
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ session('status') }}</p>
    @endif

    <section class="card space-y-4 p-5">
        <p class="eyebrow">Liberar um acesso</p>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">Nome do familiar</label>
                <input type="text" wire:model="name" class="input mt-1.5 w-full" placeholder="Ex.: Ana, filha">
                @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">E-mail do familiar</label>
                <input type="email" wire:model="email" class="input mt-1.5 w-full">
                @error('email') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset>
            <legend class="text-xs font-medium text-slate-600 dark:text-slate-300">Acompanha os dados de quem?</legend>
            <div class="mt-1.5 flex flex-wrap gap-4">
                @foreach ($membros as $membro)
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="memberIds" value="{{ $membro->id }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                        {{ $membro->name }}
                    </label>
                @endforeach
            </div>
            @error('memberIds') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset>
            <legend class="text-xs font-medium text-slate-600 dark:text-slate-300">O que acompanha</legend>
            <div class="mt-1.5 flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" wire:model.live="health" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500"> Saúde
                </label>
                <select wire:model="access" class="input py-1 text-sm" aria-label="Nível do acesso à saúde" @disabled(! $health)>
                    @foreach ($niveis as $nivel)
                        <option value="{{ $nivel->value }}">{{ $nivel->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" wire:model.live="finance" class="rounded border-slate-300 text-brand-700 focus:ring-brand-500"> Finanças, só para ver
                </label>
            </div>
            <p class="mt-1 text-xs text-slate-400">As finanças são sempre só leitura e sem o que a pessoa marcou como privado.</p>
        </fieldset>

        <fieldset>
            <legend class="text-xs font-medium text-slate-600 dark:text-slate-300">Com que base você libera este acesso?</legend>
            <div class="mt-1.5 space-y-1.5">
                @foreach ($bases as $base)
                    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="radio" wire:model="basis" value="{{ $base->value }}" class="mt-1"> <span>{{ $base->label() }}</span>
                    </label>
                @endforeach
            </div>
            @error('basis') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        </fieldset>

        <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input type="checkbox" wire:model="declared" class="mt-1 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
            <span>Declaro que as pessoas marcadas sabem e concordam com este acesso, ou que sou representante legal delas. Sei que elas são avisadas e podem encerrá-lo a qualquer momento.</span>
        </label>
        @error('declared') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

        <button type="button" wire:click="grant" wire:loading.attr="disabled" class="btn-primary">Liberar acesso e enviar o convite</button>
        @if ($lastLink)
            <p class="break-all text-xs text-slate-500 dark:text-slate-400">Se o e-mail não chegar, envie este link ao familiar: {{ $lastLink }}</p>
        @endif
    </section>

    <section class="card p-5">
        <p class="eyebrow">Quem já tem acesso</p>
        @forelse ($acessos as $acesso)
            @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="acesso-{{ $acesso->id }}">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800 dark:text-slate-200">{{ $acesso->invited_name }} <span class="font-normal text-slate-400">· {{ $acesso->invited_email }}</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Dados de {{ $acesso->member?->name ?? 'todos' }} ·
                            {{ implode(' e ', array_filter([$acesso->can_view_health ? 'saúde ('.mb_strtolower($acesso->access_level->label()).')' : null, $acesso->can_view_finance ? 'finanças (só leitura)' : null])) }} · {{ $acesso->status->label() }}
                        </p>
                        @if ($acesso->granted_by_user_id)
                            <p class="text-xs text-slate-400">Liberado por {{ $acesso->grantedBy?->name }} em {{ $acesso->granted_declared_at?->format('d/m/Y') }} · {{ mb_strtolower($acesso->grant_basis?->label() ?? '') }}</p>
                        @else
                            <p class="text-xs text-slate-400">Liberado pela própria pessoa.</p>
                        @endif
                    </div>
                    @if ($acesso->granted_by_user_id === auth()->id())
                        <button type="button" wire:click="revoke('{{ $acesso->id }}')" wire:confirm="Encerrar o acesso de {{ $acesso->invited_name }}?" class="text-xs text-red-700 underline dark:text-red-400">Encerrar acesso</button>
                    @endif
                </li>
            @if ($loop->last)</ul>@endif
        @empty
            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Ninguém acompanha este perfil ainda.</p>
        @endforelse
    </section>
</div>
