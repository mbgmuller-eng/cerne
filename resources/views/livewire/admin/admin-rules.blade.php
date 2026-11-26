@php $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400'; @endphp

<div class="space-y-5">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Revisão das regras</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">As regras de atividade física do Cerne, para um profissional de educação física revisar. O revisor não vê dados de nenhuma pessoa.</p>
    </div>

    <form wire:submit="invite" class="card space-y-3 p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Convidar um revisor</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <div><label class="{{ $campo }}">Nome</label><input type="text" wire:model="name" class="input mt-1.5"></div>
            <div><label class="{{ $campo }}">Registro no CREF</label><input type="text" wire:model="registry" class="input mt-1.5"></div>
            <div><label class="{{ $campo }}">E-mail (opcional)</label><input type="email" wire:model="email" class="input mt-1.5"></div>
        </div>
        <x-form-consequence :lines="['O Cerne cria um link que vale '.\App\Services\RuleReviewService::LINK_DAYS.' dias. Ele aparece uma vez só, para você enviar. O Cerne não manda e-mail e não confere o CREF.']" />
        <x-form-errors />
        <button type="submit" class="btn-primary px-4 py-2 text-sm">Criar o link</button>
        @if ($newLink !== '')
            <div class="rounded-xl bg-amber-50 px-3 py-2 dark:bg-amber-500/10" data-link-do-revisor>
                <p class="text-xs font-medium text-amber-900 dark:text-amber-300">Copie agora. Quem tiver este link consegue enviar pareceres no nome do revisor.</p>
                <input type="text" readonly value="{{ $newLink }}" class="input mt-1.5 w-full text-xs" onclick="this.select()">
            </div>
        @endif
    </form>

    <div class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Revisores</h2>
        @forelse ($revisores as $r)
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2 first:border-t-0 dark:border-white/10" wire:key="rev-{{ $r->id }}">
                <p class="min-w-0 text-sm text-slate-700 dark:text-slate-300">{{ $r->name }} · CREF {{ $r->registry }}<span class="block text-xs text-slate-400">{{ $r->revoked_at ? 'Acesso revogado em '.$r->revoked_at->format('d/m/Y') : ($r->expires_at->isPast() ? 'Link vencido' : 'Link válido até '.$r->expires_at->format('d/m/Y')) }}{{ $r->last_access_at ? ' · último acesso '.$r->last_access_at->format('d/m/Y H:i') : ' · ainda não acessou' }}</span></p>
                @if ($r->isActive())
                    <button type="button" wire:click="revoke('{{ $r->id }}')" wire:confirm="Revogar o acesso deste revisor?" class="text-xs text-slate-400 hover:text-red-700">Revogar</button>
                @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum revisor convidado.</p>
        @endforelse
    </div>

    <div class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Situação de cada regra ({{ $aprovacoes->filter()->count() }} de {{ count($regras) }} aprovadas)</h2>
        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($regras as $chave => $regra)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="sit-{{ $chave }}">
                    <span class="text-slate-700 dark:text-slate-300">{{ $regra['title'] }}</span>
                    <span class="text-xs {{ $aprovacoes[$chave] ? 'text-emerald-800 dark:text-emerald-300' : 'text-slate-400' }}">{{ $aprovacoes[$chave] ? 'Aprovada por '.$aprovacoes[$chave]['name'].' em '.$aprovacoes[$chave]['on']->format('d/m/Y') : 'Não aprovada nesta versão' }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Pareceres enviados</h2>
        @forelse ($pareceres as $p)
            <div class="border-t border-slate-100 py-2 text-sm first:border-t-0 dark:border-white/10" wire:key="par-{{ $p->id }}">
                <p class="font-medium text-slate-800 dark:text-slate-200">{{ $regras[$p->rule_key]['title'] ?? $p->rule_key }} · {{ $p->decision === 'approved' ? 'aprovada' : 'pede ajuste' }}@if (($regras[$p->rule_key]['version'] ?? null) !== $p->rule_version) <span class="text-xs font-normal text-slate-400">(versão antiga)</span>@endif</p>
                <p class="text-xs text-slate-400">{{ $p->reviewer->name }} · {{ $p->submitted_at->format('d/m/Y H:i') }}</p>
                @if ($p->comment) <p class="whitespace-pre-line text-slate-600 dark:text-slate-300">{{ $p->comment }}</p> @endif
                @if ($p->wrong_items) <p class="text-slate-600 dark:text-slate-300">Não deveria marcar: {{ implode('; ', $p->wrong_items) }}.</p> @endif
                @if ($p->missing) <p class="text-slate-600 dark:text-slate-300">Falta marcar: {{ $p->missing }}</p> @endif
            </div>
        @empty
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum parecer enviado.</p>
        @endforelse
    </div>
</div>
