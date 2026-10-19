@php
    $aberto = $calendario['open'] ?? null;
    $meta = $aberto?->meta ?? [];
@endphp

<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Agenda</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ auth()->user()->isConsultant() ? 'Reuniões com os clientes, prazos de tarefas e retornos de leads.' : 'Os retornos combinados com os seus leads.' }}
        </p>
    </div>

    @if (count($tipos) > 1)
        <div class="flex flex-wrap items-center gap-2 text-xs" role="group" aria-label="O que mostrar na agenda">
            <span class="text-slate-500 dark:text-slate-400">Mostrar:</span>
            @foreach ($tipos as $tipo => $nome)
                <button
                    type="button"
                    wire:click="toggleType('{{ $tipo }}')"
                    aria-pressed="{{ in_array($tipo, $types, true) ? 'true' : 'false' }}"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 font-medium transition',
                        'border-transparent bg-slate-100 text-slate-800 dark:bg-white/10 dark:text-slate-100' => in_array($tipo, $types, true),
                        'border-slate-200 text-slate-400 line-through dark:border-white/10 dark:text-slate-500' => ! in_array($tipo, $types, true),
                    ])
                >
                    <span class="h-2 w-2 rounded-full" style="background-color: {{ $cores[$tipo] }}"></span>
                    {{ $nome }}
                </button>
            @endforeach
        </div>
    @endif

    <x-calendar :data="$calendario" accent="brand">
        <x-slot:legend>
            @foreach ($tipos as $tipo => $nome)
                @if (in_array($tipo, $types, true))
                    <span class="flex items-center gap-1.5">
                        @if ($tipo === 'tasks')
                            <span class="h-2 w-3 rounded-sm border border-dashed" style="border-color: {{ $cores[$tipo] }}"></span>
                        @else
                            <span class="h-2 w-3 rounded-sm" style="background-color: {{ $cores[$tipo] }}"></span>
                        @endif
                        {{ $nome }}
                    </span>
                @endif
            @endforeach
        </x-slot:legend>

        <x-slot:detail>
            @if ($aberto && ($meta['type'] ?? null) === 'meetings')
                <div class="space-y-2 text-sm text-slate-600 dark:text-slate-300" wire:key="detalhe-reuniao-{{ $aberto->key }}">
                    <p><strong class="font-medium text-slate-800 dark:text-slate-200">{{ $meta['client'] }}</strong> · {{ $meta['format'] }}@if ($meta['location']) · {{ $meta['location'] }}@endif</p>
                    @if ($meta['agenda'] !== [])
                        <div>
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Pauta</p>
                            <ul class="mt-1 list-disc space-y-0.5 pl-5">
                                @foreach ($meta['agenda'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($meta['prep'])
                        <div>
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">O que o cliente deve levar</p>
                            <p class="mt-0.5 whitespace-pre-line">{{ $meta['prep'] }}</p>
                        </div>
                    @endif
                    @if ($meta['done'] && $meta['summary'])
                        <div>
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Ata</p>
                            <p class="mt-0.5 whitespace-pre-line">{{ $meta['summary'] }}</p>
                        </div>
                    @endif
                    <div class="flex justify-end pt-2">@include('livewire.consultant.partials.engagement-open', ['profileId' => $meta['profile_id'], 'rotulo' => 'Abrir o acompanhamento', 'classe' => 'btn-secondary px-3 py-1.5 text-xs'])</div>
                </div>
            @elseif ($aberto && ($meta['type'] ?? null) === 'tasks')
                <div class="space-y-2 text-sm text-slate-600 dark:text-slate-300" wire:key="detalhe-tarefa-{{ $aberto->key }}">
                    <p><strong class="font-medium text-slate-800 dark:text-slate-200">{{ $meta['client'] }}</strong></p>
                    @if ($meta['description'])
                        <p class="whitespace-pre-line">{{ $meta['description'] }}</p>
                    @endif
                    <div class="flex justify-end pt-2">@include('livewire.consultant.partials.engagement-open', ['profileId' => $meta['profile_id'], 'rotulo' => 'Abrir o acompanhamento', 'classe' => 'btn-secondary px-3 py-1.5 text-xs'])</div>
                </div>
            @elseif ($aberto && ($meta['type'] ?? null) === 'leads')
                <div class="space-y-2 text-sm text-slate-600 dark:text-slate-300" wire:key="detalhe-lead-{{ $aberto->key }}">
                    @if ($meta['phone'])<p>Telefone: <a href="tel:{{ preg_replace('/[^\d+]/', '', $meta['phone']) }}" class="underline">{{ $meta['phone'] }}</a></p>@endif
                    @if ($meta['email'])<p>E-mail: <a href="mailto:{{ $meta['email'] }}" class="underline">{{ $meta['email'] }}</a></p>@endif
                    <div class="flex justify-end pt-2"><a href="{{ route('consultant.leads') }}" class="btn-secondary px-3 py-1.5 text-xs">Abrir os leads</a></div>
                </div>
            @endif
        </x-slot:detail>
    </x-calendar>
</div>
