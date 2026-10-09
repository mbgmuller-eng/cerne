<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Agenda de Saúde</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Consultas e exames, com quem atende, onde fica e como falar com o local. Visível para você e seu cônjuge. Avisamos um dia antes.
            </p>
        </div>
        <button type="button" wire:click="newAppointment" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Agendar</button>
    </div>

    {{-- O que vem primeiro na tela: a preferência fica na conta, a tela mostra sempre os dois. --}}
    @php
        $ehCasal = $membros->count() > 1;
        $calendarioPrimeiro = $layout === \App\Enums\AgendaLayout::Calendar;
    @endphp
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400" role="group" aria-label="O que mostrar primeiro">
            <span>Mostrar primeiro:</span>
            @foreach (\App\Enums\AgendaLayout::cases() as $opcao)
                <button
                    type="button"
                    wire:click="setLayout('{{ $opcao->value }}')"
                    aria-pressed="{{ $layout === $opcao ? 'true' : 'false' }}"
                    @class([
                        'rounded-full px-3 py-1 font-medium transition',
                        'bg-saude-800 text-white dark:bg-saude-200 dark:text-saude-950' => $layout === $opcao,
                        'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-white/10 dark:text-slate-300 dark:hover:bg-white/15' => $layout !== $opcao,
                    ])
                >{{ $opcao === \App\Enums\AgendaLayout::Calendar ? 'Calendário' : 'Lista' }}</button>
            @endforeach
        </div>
        @if ($ehCasal)
            <button type="button" wire:click="toggleColors" class="btn-ghost px-2 py-1 text-xs">{{ $showColors ? 'Fechar cores' : 'Cores do calendário' }}</button>
        @endif
    </div>

    {{-- Cada pessoa do casal tem a sua cor; qualquer uma das duas pode mudar. Só cores da paleta. --}}
    @if ($ehCasal && $showColors)
        <section class="card space-y-4 p-5">
            <p class="eyebrow">Cores do calendário</p>
            @foreach ($membros as $membro)
                <div class="flex flex-wrap items-center gap-3" wire:key="cor-{{ $membro->id }}">
                    <span class="flex min-w-24 items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-200">
                        <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $coresDosMembros[$membro->id] }}"></span>
                        {{ $membro->name }}
                    </span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($paleta as $hex => $nome)
                            <button
                                type="button"
                                wire:click="setMemberColor('{{ $membro->id }}', '{{ $hex }}')"
                                title="{{ $nome }}"
                                aria-label="{{ $nome }} para {{ $membro->name }}"
                                aria-pressed="{{ $coresDosMembros[$membro->id] === $hex ? 'true' : 'false' }}"
                                @class([
                                    'h-7 w-7 rounded-full ring-offset-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 dark:ring-offset-slate-900',
                                    'ring-2 ring-slate-800 dark:ring-white' => $coresDosMembros[$membro->id] === $hex,
                                ])
                                style="background-color: {{ $hex }}"
                            ></button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    <div class="space-y-6">

    {{-- Na ordem que a pessoa escolheu: o HTML já sai nela, para o foco do teclado e o leitor de tela seguirem o que se vê. --}}
    @if ($calendarioPrimeiro)
        @include('livewire.health.partials.agenda-calendar')
    @endif

    <div class="space-y-6">

    <section class="card space-y-3 p-5">
        <p class="eyebrow">Próximas</p>
        @if ($upcoming->isEmpty())
            <p class="text-xs text-slate-400">Nada agendado.</p>
        @else
            <ul class="space-y-2">
                @foreach ($upcoming as $consulta)
                    <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="up-{{ $consulta->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ $consulta->title }}
                                <span class="ml-1 rounded-full bg-saude-200/40 px-2 py-0.5 text-[10px] font-medium text-saude-800 dark:bg-saude-800/30 dark:text-saude-200">{{ $consulta->kind->label() }}</span>
                            </p>
                            <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                @if ($ehCasal)<span class="inline-block h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $coresDosMembros[$consulta->member_id] ?? '#2563EB' }}"></span>@endif
                                <span>{{ $consulta->member->name }} · {{ $consulta->scheduled_at->format('d/m/Y \à\s H:i') }}</span>
                            </p>
                            <x-appointment-details :consulta="$consulta" />
                            <x-calendar-actions :consulta="$consulta" :show-person="$membros->count() > 1" />
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="editAppointment('{{ $consulta->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            <button type="button" wire:click="delete('{{ $consulta->id }}')" wire:confirm="Remover este agendamento?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card space-y-3 p-5">
        <p class="eyebrow">Histórico</p>
        @if ($past->isEmpty())
            <p class="text-xs text-slate-400">Nenhum registro ainda.</p>
        @else
            <ul class="space-y-2">
                @foreach ($past as $consulta)
                    <li class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="past-{{ $consulta->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {{ $consulta->title }}
                                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-white/10 dark:text-slate-400">{{ $consulta->kind->label() }}</span>
                                </p>
                                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($ehCasal)<span class="inline-block h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $coresDosMembros[$consulta->member_id] ?? '#2563EB' }}"></span>@endif
                                    <span>{{ $consulta->member->name }} · {{ $consulta->scheduled_at->format('d/m/Y') }}</span>
                                </p>
                                <x-appointment-details :consulta="$consulta" />
                            </div>
                            <button type="button" wire:click="editAppointment('{{ $consulta->id }}')" class="btn-ghost shrink-0 px-2 py-1 text-xs">Editar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    </div>{{-- /lista (próximas e histórico) --}}

    @unless ($calendarioPrimeiro)
        @include('livewire.health.partials.agenda-calendar')
    @endunless

    </div>{{-- /calendário + lista, na ordem escolhida --}}

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar' : 'Agendar' }}</h2>
                <button type="button" wire:click="closeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model="memberId" class="select mt-1.5 w-full">
                        @foreach ($membros as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('memberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model.live="kind" class="select mt-1.5 w-full">
                        <option value="consultation">Consulta</option>
                        <option value="exam">Exame</option>
                    </select>
                    @error('kind') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @if ($kind === 'consultation')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Profissional</label>
                        <input type="text" wire:model="professionalName" class="input mt-1.5" placeholder="Ex.: Dr. João Silva" maxlength="120">
                        @error('professionalName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Especialidade</label>
                        <input type="text" wire:model="specialty" class="input mt-1.5" placeholder="Ex.: Otorrinolaringologia" maxlength="80">
                        @error('specialty') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $kind === 'consultation' ? 'Título' : 'Nome do exame' }}
                        @if ($kind === 'consultation')<span class="font-normal text-slate-400">(opcional se informar profissional ou especialidade)</span>@endif
                    </label>
                    <input type="text" wire:model="title" class="input mt-1.5" placeholder="{{ $kind === 'consultation' ? 'Ex.: Retorno do aparelho auditivo' : 'Ex.: Hemograma completo' }}" maxlength="120">
                    @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                    <input type="date" wire:model="scheduledDate" class="input mt-1.5">
                    @error('scheduledDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Hora</label>
                    <input type="time" wire:model="scheduledTime" class="input mt-1.5">
                    @error('scheduledTime') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Onde fica</p>
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Estabelecimento <span class="font-normal text-slate-400">(clínica, hospital, laboratório)</span></label>
                    <input type="text" wire:model="location" class="input mt-1.5" placeholder="Ex.: Clínica Vida" maxlength="160">
                    @error('location') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Endereço</label>
                    <input type="text" wire:model="address" autocomplete="off" class="input mt-1.5" placeholder="Ex.: Rua das Flores, 100, sala 3" maxlength="200">
                    @error('address') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Telefone ou contato</label>
                    <input type="tel" wire:model="phone" autocomplete="off" class="input mt-1.5" placeholder="(41) 3000-0000" maxlength="30">
                    @error('phone') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Agendamento</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Agendado por</label>
                    <input type="text" wire:model="bookedByName" class="input mt-1.5" placeholder="Quem da família marcou" maxlength="120">
                    @error('bookedByName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Agendado com <span class="font-normal text-slate-400">(secretária ou atendente)</span></label>
                    <input type="text" wire:model="bookedWithName" class="input mt-1.5" placeholder="Ex.: Maria, secretária" maxlength="120">
                    @error('bookedWithName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações</label>
                    <textarea wire:model="notes" class="input mt-1.5" rows="2" placeholder="Antes: levar exames anteriores. Depois: o que aconteceu, resultado, próximos passos."></textarea>
                    @error('notes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>
</div>
