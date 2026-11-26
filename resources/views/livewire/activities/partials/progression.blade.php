{{-- Os minutos de aeróbico das próximas semanas. A barra é só apoio: o número vai escrito em cada linha. --}}
@php $maior = max(1, collect($semanas)->max('minutes')); @endphp

<details class="text-sm" @if ($atual === null) open @endif>
    <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Como os minutos evoluem em {{ count($semanas) }} semanas</summary>
    <ol class="mt-2 space-y-1" data-progressao>
        @foreach ($semanas as $s)
            <li class="flex items-center gap-2" @if ($atual === $s['week']) aria-current="step" @endif>
                <span @class(['w-20 shrink-0 text-xs', 'font-semibold text-slate-900 dark:text-white' => $atual === $s['week'], 'text-slate-500 dark:text-slate-400' => $atual !== $s['week']])>Semana {{ $s['week'] }}</span>
                <span class="h-2.5 min-w-0 flex-1" aria-hidden="true"><span @class(['block h-full rounded-sm', 'bg-saude-800 dark:bg-saude-200' => ! $s['deload'], 'bg-slate-300 dark:bg-slate-600' => $s['deload']]) style="width: {{ intdiv($s['minutes'] * 100, $maior) }}%"></span></span>
                <span class="w-28 shrink-0 text-right text-xs tabular-nums text-slate-600 dark:text-slate-300">{{ $s['minutes'] }} min{{ $s['deload'] ? ', alívio' : '' }}</span>
            </li>
        @endforeach
    </ol>
    <p class="mt-1.5 text-xs text-slate-400">{{ $leveDoPlano ? 'No plano leve os minutos não aumentam.' : 'A semana só avança quando a anterior teve ao menos '.\App\Support\Activities\PlanRules::ADHERENCE_PERCENT.'% do aeróbico planejado. A cada '.\App\Support\Activities\PlanRules::DELOAD_EVERY.' semanas, uma é mais leve.' }}</p>
</details>
