<div>
    @if ($tutorial)
        {{-- Cartão de primeira visita: aparece uma vez por tela e por pessoa. "Ver" abre o tutorial; "Dispensar" some de vez. --}}
        @if ($primeiraVisita)
            <div data-tutorial-card class="mb-6 flex flex-col gap-3 rounded-xl bg-brand-50 px-4 py-3 ring-1 ring-brand-200 sm:flex-row sm:items-center sm:justify-between dark:bg-brand-500/10 dark:ring-brand-500/20">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white" aria-hidden="true">?</span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-900 dark:text-brand-100">Primeira vez nesta tela?</p>
                        <p class="mt-0.5 text-sm text-brand-900/80 dark:text-brand-200">{{ $tutorial['summary'] }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" wire:click="openTutorial" class="btn-primary px-3 py-1.5 text-xs">Ver como usar</button>
                    <button type="button" wire:click="dismissCard" class="btn-ghost px-3 py-1.5 text-xs">Dispensar</button>
                </div>
            </div>
        @endif

        <x-modal wire-model="open" max-width="lg">
            <div class="space-y-4">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Como usar: {{ $tutorial['title'] }}</h2>
                    <button type="button" wire:click="$set('open', false)" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
                </div>

                <p class="text-sm text-slate-600 dark:text-slate-300">{{ $tutorial['summary'] }}</p>

                <ol class="space-y-3">
                    @foreach ($tutorial['steps'] as $passo)
                        <li class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800 dark:bg-brand-500/20 dark:text-brand-200">{{ $loop->iteration }}</span>
                            <span class="min-w-0 text-sm text-slate-800 dark:text-slate-200">{{ $passo }}</span>
                        </li>
                    @endforeach
                </ol>

                @if (! empty($tutorial['tip']))
                    <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                        <span class="font-semibold">Bom saber:</span> {{ $tutorial['tip'] }}
                    </div>
                @endif

                <div class="flex justify-end">
                    <button type="button" wire:click="$set('open', false)" class="btn-primary px-4 py-2 text-sm">Entendi</button>
                </div>
            </div>
        </x-modal>
    @endif
</div>
