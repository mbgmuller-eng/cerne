<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Acessos às contas</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Cada vez que alguém do time usou "Entrar como": quem entrou, em qual conta, quando e por quê. O registro é guardado por 5 anos.
        </p>
    </div>

    <input type="search" wire:model.live.debounce.400ms="busca" placeholder="Buscar por conta, administrador ou motivo" class="input w-full max-w-sm">

    <div class="card overflow-hidden p-0">
        @if ($acessos->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Nenhum acesso registrado.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Quando</th>
                            <th class="px-3 py-2 font-medium">Quem entrou</th>
                            <th class="px-3 py-2 font-medium">Na conta de</th>
                            <th class="px-3 py-2 font-medium">Motivo</th>
                            <th class="px-3 py-2 font-medium">Duração</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($acessos as $a)
                            <tr class="align-top">
                                <td class="px-5 py-3 whitespace-nowrap text-slate-600 dark:text-slate-300">{{ $a->started_at->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-3 text-slate-700 dark:text-slate-300">{{ $a->admin_email }}</td>
                                <td class="px-3 py-3">
                                    <span class="text-slate-800 dark:text-slate-200">{{ $a->target_name ?? '[excluído]' }}</span>
                                    <span class="block text-xs text-slate-400">{{ $a->target_email ?? '' }} @if ($a->target_role)· {{ $a->target_role }}@endif</span>
                                </td>
                                <td class="max-w-sm px-3 py-3 text-slate-600 dark:text-slate-300">{{ $a->reason }}</td>
                                <td class="px-3 py-3 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                    @if ($a->isOpen())
                                        <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">sem encerramento</span>
                                    @else
                                        {{ max(1, (int) $a->started_at->diffInMinutes($a->ended_at)) }} min
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
