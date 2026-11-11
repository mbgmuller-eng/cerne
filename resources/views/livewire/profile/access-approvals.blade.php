<div>
    @if ($partnerRequests->isNotEmpty() || $grantRequests->isNotEmpty())
        <section class="card border-amber-300 p-5 dark:border-amber-500/40" data-access-approvals>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Pedidos do seu consultor esperando a sua aprovação</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Nada vale, e nenhum e-mail sai ao convidado, até você aprovar. Se você não reconhece o pedido, recuse.</p>

            @error('approval') <p class="mt-2 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

            <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($partnerRequests as $pedido)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="pc-{{ $pedido->id }}">
                        <div class="min-w-0 text-sm text-slate-700 dark:text-slate-300">
                            <p><span class="font-medium">{{ $pedido->invitedBy?->name }}</span> quer convidar <span class="font-medium">{{ $pedido->partner_name }}</span> ({{ $pedido->partner_email }}) como seu cônjuge, com login próprio no seu perfil.</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="rejectPartner('{{ $pedido->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Recusar</button>
                            <button type="button" wire:click="approvePartner('{{ $pedido->id }}')" class="btn-primary px-3 py-1.5 text-xs">Aprovar</button>
                        </div>
                    </li>
                @endforeach

                @foreach ($grantRequests as $lote)
                    @php($primeiro = $lote->first())
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="fg-{{ $primeiro->id }}">
                        <div class="min-w-0 text-sm text-slate-700 dark:text-slate-300">
                            <p><span class="font-medium">{{ $primeiro->grantedBy?->name }}</span> quer liberar o acesso de <span class="font-medium">{{ $primeiro->invited_name }}</span> ({{ $primeiro->invited_email }}) a
                                {{ collect([$primeiro->can_view_health ? 'a saúde' : null, $primeiro->can_view_finance ? 'as finanças (só para ver)' : null])->filter()->join(' e ') }}
                                de {{ $lote->map(fn ($l) => $l->member?->name)->filter()->sort()->join(', ', ' e ') }}.
                                Base declarada: {{ mb_strtolower($primeiro->grant_basis?->label() ?? '') }}.</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="rejectGrant('{{ $primeiro->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Recusar</button>
                            <button type="button" wire:click="approveGrant('{{ $primeiro->id }}')" class="btn-primary px-3 py-1.5 text-xs">Aprovar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
