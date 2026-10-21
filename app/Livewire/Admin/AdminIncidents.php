<?php

namespace App\Livewire\Admin;

use App\Models\SecurityIncident;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Registro de incidentes de segurança com dado pessoal: o que aconteceu, o que foi atingido, o risco, as providências e quando a ANPD e as
 * pessoas foram comunicadas. Mostra o prazo de comunicação (3 dias úteis) de cada incidente em aberto. O roteiro de resposta está em
 * INCIDENTES.md. Só o administrador.
 */
#[Layout('components.layouts.app')]
class AdminIncidents extends Component
{
    public bool $showForm = false;

    public string $title = '';

    public string $description = '';

    public string $occurredAt = '';

    public string $detectedAt = '';

    public string $dataAffected = '';

    public string $peopleAffected = '';

    public string $risk = 'unknown';

    public string $measures = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
        $this->detectedAt = now()->format('Y-m-d\TH:i');
    }

    public function novo(): void
    {
        $this->reset('title', 'description', 'occurredAt', 'dataAffected', 'peopleAffected', 'risk', 'measures');
        $this->detectedAt = now()->format('Y-m-d\TH:i');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function fechar(): void
    {
        $this->showForm = false;
    }

    public function salvar(): void
    {
        $d = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:4000'],
            'occurredAt' => ['nullable', 'date'],
            'detectedAt' => ['required', 'date', 'before_or_equal:now'],
            'dataAffected' => ['nullable', 'string', 'max:2000'],
            'peopleAffected' => ['nullable', 'integer', 'min:0'],
            'risk' => ['required', Rule::in(array_keys(SecurityIncident::RISKS))],
            'measures' => ['nullable', 'string', 'max:4000'],
        ], attributes: ['title' => 'título', 'description' => 'descrição', 'detectedAt' => 'data da descoberta', 'occurredAt' => 'data do ocorrido', 'peopleAffected' => 'pessoas afetadas']);

        SecurityIncident::create([
            'title' => trim($d['title']), 'description' => trim($d['description']),
            'occurred_at' => $d['occurredAt'] !== '' ? $d['occurredAt'] : null, 'detected_at' => $d['detectedAt'],
            'data_affected' => $d['dataAffected'] !== '' ? $d['dataAffected'] : null,
            'people_affected' => $d['peopleAffected'] !== '' ? (int) $d['peopleAffected'] : null,
            'risk' => $d['risk'], 'measures' => $d['measures'] !== '' ? $d['measures'] : null, 'registered_by' => auth()->id(),
        ]);

        $this->showForm = false;
        session()->flash('status', 'Incidente registrado. Confira o prazo de comunicação à ANPD na lista.');
    }

    public function marcarAnpd(int $id): void
    {
        SecurityIncident::query()->findOrFail($id)->forceFill(['anpd_notified_at' => now()])->save();
    }

    public function marcarTitulares(int $id): void
    {
        SecurityIncident::query()->findOrFail($id)->forceFill(['people_notified_at' => now()])->save();
    }

    public function mudarStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, SecurityIncident::STATUSES), 422);

        SecurityIncident::query()->findOrFail($id)->forceFill(['status' => $status])->save();
    }

    public function render()
    {
        return view('livewire.admin.admin-incidents', [
            'incidentes' => SecurityIncident::query()->orderByRaw("status = 'closed'")->orderByDesc('detected_at')->get(),
        ]);
    }
}
