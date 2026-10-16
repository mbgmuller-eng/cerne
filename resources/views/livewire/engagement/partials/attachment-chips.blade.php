{{-- Documentos anexados a uma reunião ou tarefa. Recebe $anexos e $visitante. --}}
@if ($anexos->isNotEmpty())
    <ul class="flex flex-wrap gap-1.5">
        @foreach ($anexos as $a)
            <li class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-2.5 pr-1 text-xs text-slate-700 dark:bg-white/10 dark:text-slate-300" wire:key="anexo-{{ $a->id }}">
                <a href="{{ route('documents.vault.file', $a->document) }}" target="_blank" rel="noopener" class="max-w-[14rem] truncate hover:underline">{{ $a->document->title }}</a>
                @if ($visitante->isConsultant() || $a->attached_by_user_id === $visitante->userId)
                    <button type="button" wire:click="detachDocument('{{ $a->id }}')" wire:confirm="Remover este anexo? O documento continua na área de Documentos." class="rounded-full px-1.5 text-slate-400 hover:text-red-600" aria-label="Remover anexo">×</button>
                @endif
            </li>
        @endforeach
    </ul>
@endif
