<?php

namespace App\Livewire\Health\Exams;

use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthExamRequest;
use App\Services\HealthExamReadingService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Envio do PDF de um laudo para leitura, dentro de um pedido ou avulso (um laudo antigo, para começar o histórico). */
class HealthExamUpload extends Component
{
    use FindsExamPeople, RequiresPersonalHealth, WithFileUploads;

    #[Locked]
    public ?string $pedidoId = null;

    public string $memberId = '';

    public $arquivo = null;

    public bool $open = false;

    public function mount(?string $pedidoId = null): void
    {
        $this->abortUnlessHealthParticipant();
        $this->pedidoId = $pedidoId !== null ? HealthExamRequest::query()->findOrFail($pedidoId)->id : null;
        $this->memberId = $this->defaultPersonId();
    }

    public function send(HealthExamReadingService $leitor): void
    {
        $this->validate(
            ['arquivo' => ['required', 'file', 'mimes:pdf', 'max:'.(config('cerne.ai.max_upload_mb') * 1024)]],
            ['arquivo.mimes' => 'Envie o laudo em PDF.'],
            ['arquivo' => 'arquivo'],
        );

        $pedido = $this->pedidoId !== null ? HealthExamRequest::query()->findOrFail($this->pedidoId) : null;

        try {
            $leitura = $leitor->request($this->personOrFail($pedido?->member_id ?? $this->memberId), $pedido, $this->arquivo, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('arquivo', $e->getMessage());

            return;
        }

        $this->redirect(route('health.exams.reading', $leitura->id), navigate: true);
    }

    public function render(HealthExamReadingService $leitor)
    {
        $perfil = (string) app(ProfileContext::class)->profileId();

        return view('livewire.health.exams.health-exam-upload', [
            'disponivel' => $leitor->available(),
            'restantes' => max(0, (int) config('cerne.ai.exam_reads_per_day') - $leitor->readsToday($perfil)),
            'pessoas' => $this->pedidoId === null ? $this->people() : collect(),
            'consequencia' => FormConsequence::examReading(),
        ]);
    }
}
