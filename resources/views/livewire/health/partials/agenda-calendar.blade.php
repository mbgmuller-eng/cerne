{{-- Calendário da Agenda de Saúde (mês, semana ou dia). Usa as variáveis da tela: calendario, membros, ehCasal, coresDosMembros, consultaAberta, cuidadoAberto. --}}
<x-calendar :data="$calendario" accent="saude">
    <x-slot:toolbar>
        @if ($calendario['view'] === 'day')
            <button type="button" wire:click="newAppointmentOn('{{ $calendario['anchor'] }}')" class="btn-secondary px-3 py-1 text-xs">+ Agendar neste dia</button>
        @endif
    </x-slot:toolbar>

    <x-slot:legend>
        <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-slate-500/40 ring-1 ring-inset ring-slate-500"></span> Consulta ou exame</span>
        <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm border border-dashed border-slate-500"></span> Item de cuidado</span>
        @if ($ehCasal)
            @foreach ($membros as $membro)
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full" style="background-color: {{ $coresDosMembros[$membro->id] }}"></span> {{ $membro->name }}</span>
            @endforeach
        @endif
    </x-slot:legend>

    <x-slot:detail>
        @if ($consultaAberta)
            <div wire:key="detalhe-consulta-{{ $consultaAberta->id }}">
                <x-appointment-details :consulta="$consultaAberta" />
                @if (! $consultaAberta->isPast())
                    <x-calendar-actions :consulta="$consultaAberta" :show-person="$ehCasal" />
                @endif
                <div class="mt-4 flex items-center justify-end gap-2">
                    <button type="button" wire:click="delete('{{ $consultaAberta->id }}')" wire:confirm="Remover este agendamento?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                    <button type="button" wire:click="editAppointment('{{ $consultaAberta->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                </div>
            </div>
        @elseif ($cuidadoAberto)
            <p class="text-xs"><a href="{{ route('health.care.index') }}" class="font-medium text-saude-800 hover:underline dark:text-saude-200">Abrir em Cuidados e itens</a></p>
        @endif
    </x-slot:detail>
</x-calendar>
