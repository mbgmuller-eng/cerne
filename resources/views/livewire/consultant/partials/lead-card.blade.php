@use('App\Enums\LeadStage')
@use('App\Enums\LeadKind')
@use('App\Enums\InsuranceType')
@use('App\Support\Money')

{{--
    Cartão de um lead — usado tanto nas colunas do quadro quanto na lista
    de convertidos/perdidos. Recebe $lead do @include; o resto ($loggingActivityLeadId
    etc.) já está no escopo do componente pai.
--}}
@php
    $seguros = $lead->kind === LeadKind::Insurance;
    $etapas = LeadStage::openFor($lead->kind);
    $encerrado = $lead->stage->isClosed();
    $estimada = $seguros && ! $encerrado ? $lead->estimatedQuote() : null;
    $diasParaVencer = $seguros && ! $encerrado
        ? $lead->quotes->map(fn ($q) => $q->daysToExpire())->filter(fn ($d) => $d !== null && $d <= 3)->min()
        : null;
    $vencidas = $seguros && ! $encerrado ? $lead->quotes->filter(fn ($q) => $q->isExpired())->count() : 0;
    $diasSemContato = $encerrado ? null : $lead->daysSinceContact();
    $whatsapp = $lead->whatsappUrl();
    $anexosSaem = $lead->stage === LeadStage::Lost && $lead->attachments_count > 0
        ? app(\App\Services\LeadService::class)->attachmentsExpireOn($lead)
        : null;
@endphp
<div class="card space-y-2 p-3">
    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $lead->name }}</p>
        <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
            @if ($lead->email) {{ $lead->email }} @endif
            @if ($lead->phone) · {{ $lead->phone }} @endif
            @if ($whatsapp) · <a href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" class="font-medium text-accent-700 hover:underline dark:text-accent-400">WhatsApp</a> @endif
        </p>

        @if ($encerrado)
            <p class="mt-0.5 text-xs text-slate-400">{{ $lead->stageLabel() }}</p>
        @endif

        @if ($lead->isClientDeal())
            <p class="mt-1"><span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-medium text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">Já é cliente</span></p>
        @endif

        {{-- O que ele quer e de onde veio ------------------------------------ --}}
        @if ($seguros && ! empty($lead->interests))
            <div class="mt-1.5 flex flex-wrap gap-1">
                @foreach ($lead->interests as $tipo)
                    <span class="rounded-full bg-seguros-200/40 px-2 py-0.5 text-[10px] font-medium text-seguros-800 dark:bg-seguros-800/30 dark:text-seguros-200">{{ InsuranceType::tryFrom($tipo)?->label() ?? $tipo }}</span>
                @endforeach
            </div>
        @endif
        @if ($lead->source)
            <p class="mt-1 truncate text-xs text-slate-400">Origem: {{ $lead->source->label() }}@if ($lead->source_detail) · {{ $lead->source_detail }}@endif</p>
        @endif

        {{-- Cotações: o número, o valor que representa e o que está vencendo ---------- --}}
        @if ($seguros)
            <p class="mt-1.5 text-xs text-slate-600 dark:text-slate-300">
                {{ $lead->quotes->count() }} {{ $lead->quotes->count() === 1 ? 'cotação' : 'cotações' }}
                @if ($estimada)
                    · a partir de <strong class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($estimada->monthlyPremium()) }}</strong>/mês
                @endif
                @if ($lead->attachments_count > 0)
                    · {{ $lead->attachments_count }} {{ $lead->attachments_count === 1 ? 'arquivo' : 'arquivos' }}
                @endif
            </p>
            @if ($diasParaVencer !== null)
                <p class="mt-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                    cotação vence {{ $diasParaVencer === 0 ? 'hoje' : ($diasParaVencer === 1 ? 'amanhã' : 'em '.$diasParaVencer.' dias') }}
                </p>
            @endif
            @if ($vencidas > 0)
                <p class="mt-0.5 text-xs font-medium text-red-700 dark:text-red-400">{{ $vencidas }} {{ $vencidas === 1 ? 'cotação vencida' : 'cotações vencidas' }}</p>
            @endif
        @endif

        @if ($lead->next_action_at)
            <p class="mt-0.5 truncate text-xs text-accent-600 dark:text-accent-400">próximo contato {{ $lead->next_action_at->format('d/m \à\s H:i') }}</p>
        @endif
        @if ($diasSemContato !== null && $diasSemContato >= 7)
            <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">sem contato há {{ $diasSemContato }} dias</p>
        @endif

        @if ($lead->activities->isNotEmpty())
            <ul class="mt-1.5 space-y-0.5">
                @foreach ($lead->activities as $atividade)
                    <li class="text-xs text-slate-400">
                        {{ $atividade->type->label() }} · {{ $atividade->occurred_at->format('d/m') }}
                        @if ($atividade->description) — {{ $atividade->description }} @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($lead->stage === LeadStage::Lost && ($lead->lost_category || $lead->lost_reason))
            <p class="mt-1 text-xs text-red-600 dark:text-red-400">
                Perdido: {{ collect([$lead->lost_category?->label(), $lead->lost_reason])->filter()->implode(' · ') }}
            </p>
        @endif
        @if ($anexosSaem)
            <p class="mt-0.5 text-xs text-slate-400">Os arquivos deste contato saem do servidor em {{ $anexosSaem->format('d/m/Y') }}.</p>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
        @unless ($encerrado)
            @if ($lead->stage !== $etapas[0])
                <button type="button" wire:click="regressStage('{{ $lead->id }}')" class="btn-ghost px-2 py-1 text-xs" title="Voltar">←</button>
            @endif
            @if ($lead->stage !== end($etapas))
                <button type="button" wire:click="advanceStage('{{ $lead->id }}')" class="btn-ghost px-2 py-1 text-xs" title="Avançar">Avançar →</button>
            @endif

            @if ($seguros)
                <button type="button" wire:click="openQuotes('{{ $lead->id }}')" class="btn-secondary px-2 py-1 text-xs whitespace-nowrap">Cotações</button>
            @endif
            <button type="button" wire:click="toggleLogActivity('{{ $lead->id }}')" class="btn-secondary px-2 py-1 text-xs whitespace-nowrap">
                {{ $loggingActivityLeadId === $lead->id ? 'Cancelar' : 'Registrar' }}
            </button>
            @if ($lead->isClientDeal())
                <button wire:click="closeDeal('{{ $lead->id }}')" wire:confirm="Marcar este negócio como fechado?" class="btn-primary px-2 py-1 text-xs whitespace-nowrap">Fechado</button>
            @else
                <button wire:click="convertLead('{{ $lead->id }}')" class="btn-primary px-2 py-1 text-xs whitespace-nowrap">
                    Converter
                </button>
            @endif
        @else
            @if ($seguros)
                <button type="button" wire:click="openQuotes('{{ $lead->id }}')" class="btn-ghost px-2 py-1 text-xs whitespace-nowrap">Ver cotações</button>
            @endif
        @endunless

        @if ($confirmingDeleteLeadId === $lead->id)
            <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
            <button wire:click="deleteLead('{{ $lead->id }}')" class="text-xs font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
            <button wire:click="cancelDeleteLead" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
        @else
            <div class="relative ml-auto" x-data="{ open: false, top: 0, right: 0 }" @click.outside="open = false" @scroll.window="open = false">
                {{-- Menu fixo na tela: dentro do quadro que rola para o lado, um menu absoluto seria cortado. --}}
                <button type="button" @click="const r = $el.getBoundingClientRect(); top = r.bottom + 4; right = window.innerWidth - r.right; open = !open" class="btn-ghost px-1.5 py-1" aria-label="Mais ações">
                    <x-nav-icon name="dots" class="h-4 w-4" />
                </button>
                <div x-show="open" x-transition x-cloak @click="open = false" :style="`top:${top}px;right:${right}px`" class="fixed z-30 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10">
                    <button wire:click="editLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                    @unless ($encerrado)
                        <button wire:click="toggleMarkLost('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Marcar perdido</button>
                    @endunless
                    <button wire:click="confirmDeleteLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                </div>
            </div>
        @endif
    </div>

    {{-- Registrar contato --------------------------------------------- --}}
    @if ($loggingActivityLeadId === $lead->id)
        <form wire:submit="logActivity" class="space-y-2 rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
            <select wire:model="activityType" class="select w-full py-1.5 text-xs">
                @foreach ($activityTypes as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <input type="datetime-local" wire:model="activityOccurredAt" class="input w-full py-1.5 text-xs">
            <input type="text" wire:model="activityDescription" placeholder="O que foi conversado (opcional)" class="input w-full py-1.5 text-xs">
            @error('activityOccurredAt') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full px-2 py-1.5 text-xs" wire:loading.attr="disabled">Salvar</button>
        </form>
    @endif

    {{-- Marcar perdido ---------------------------------------------------- --}}
    @if ($markingLostLeadId === $lead->id)
        <form wire:submit="markLost" class="space-y-2 rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
            <select wire:model="lostCategory" class="select w-full py-1.5 text-xs">
                <option value="">{{ $seguros ? 'Por que não fechou?' : 'Categoria (opcional)' }}</option>
                @foreach ($lostReasons as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            @error('lostCategory') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            <input type="text" wire:model="lostReason" placeholder="{{ $seguros ? 'Anotação (opcional)' : 'Por que não avançou?' }}" class="input w-full py-1.5 text-xs">
            @error('lostReason') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full px-2 py-1.5 text-xs" wire:loading.attr="disabled">Confirmar</button>
        </form>
    @endif

    {{-- Resultado da conversão --------------------------------------------- --}}
    @if ($lastConvertedLeadId === $lead->id && $lastInviteLink)
        <div class="rounded-lg bg-brand-50 p-2.5 dark:bg-brand-500/10">
            <p class="text-xs font-medium text-brand-900 dark:text-brand-200">Convite enviado — link de cadastro:</p>
            <p class="mt-1 font-mono text-xs break-all text-brand-800 dark:text-brand-300">{{ $lastInviteLink }}</p>
        </div>
    @endif

    @error('convert') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
</div>
