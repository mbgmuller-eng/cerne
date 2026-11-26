{{-- Laudos enviados que ainda pedem alguma coisa: lendo, esperando revisão ou com falha. --}}
@if ($leituras->isNotEmpty())
    <section class="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-laudos-abertos>
        <p class="font-medium">{{ $leituras->count() === 1 ? '1 laudo em andamento' : $leituras->count().' laudos em andamento' }}</p>
        <ul class="space-y-1">
            @foreach ($leituras as $l)
                <li wire:key="leitura-{{ $l->id }}">
                    {{ ($variasPessoas ?? false) ? $l->member->name.' · ' : '' }}{{ $l->original_filename }}:
                    {{ match ($l->status) { \App\Enums\ProcessingStatus::Completed => 'aguardando a sua revisão', \App\Enums\ProcessingStatus::Failed => 'não deu para ler', default => 'lendo' } }}
                    <a href="{{ route('health.exams.reading', $l->id) }}" wire:navigate class="font-medium underline">abrir</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
