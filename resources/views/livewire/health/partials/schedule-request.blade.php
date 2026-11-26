{{-- "Marcar data" de um exame pedido: usado nos check-ups e na linha do tempo do acompanhamento (o componente dono define schedule()). --}}
<form wire:submit="schedule" class="mt-3 space-y-3">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
            <input type="date" wire:model="scheduleDate" class="input mt-1.5">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Hora</label>
            <input type="time" wire:model="scheduleTime" class="input mt-1.5">
        </div>
    </div>
    <x-form-consequence :lines="\App\Support\Forms\FormConsequence::healthSchedule()" />
    <x-form-errors />
    <button type="submit" class="btn-primary px-3 py-1.5 text-sm">Salvar data</button>
</form>
