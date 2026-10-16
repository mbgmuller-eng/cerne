<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Acompanhamento</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                @if ($ehConsultor)
                    Reuniões, tarefas e demandas com {{ $nomeDoPerfil }}.
                @else
                    Reuniões, tarefas e demandas com {{ $consultores->pluck('name')->join(', ', ' e ') }}.
                @endif
            </p>
        </div>
        @if ($ehConsultor)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('consultant.week') }}" class="btn-ghost px-3 py-1.5 text-sm">Minha semana</a>
                <button type="button" wire:click="enviarPlano" wire:confirm="Enviar o plano em PDF para o e-mail do cliente?" class="btn-ghost px-3 py-1.5 text-sm">Enviar plano ao cliente</button>
                <button type="button" wire:click="baixarPlano" class="btn-secondary px-3 py-1.5 text-sm">Baixar plano em PDF</button>
            </div>
        @endif
    </div>

    @if ($envio)
        <p class="rounded-lg bg-accent-50 px-4 py-3 text-xs text-accent-700 dark:bg-accent-500/10 dark:text-accent-300">{{ $envio }}</p>
    @endif

    <livewire:engagement.engagement-meetings />

    <livewire:engagement.engagement-plan />

    <div class="grid gap-6 lg:grid-cols-2 *:min-w-0">
        <livewire:engagement.engagement-tasks />
        <livewire:engagement.engagement-requests />
    </div>

    <livewire:engagement.engagement-notes />
</div>
