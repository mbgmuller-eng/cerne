<?php

namespace App\Livewire\Health\Concerns;

use App\Models\HealthProfessional;
use App\Services\HealthProfessionalService;
use App\Support\Health\ExamCatalog;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;

/**
 * O campo de profissional dos formulários de saúde (um por tela): escolher um guardado, ou digitar um novo e pedir para guardar. A view é
 * `<x-health.professional-field>`; o componente diz de quem é o formulário e o que fazer ao escolher.
 */
trait PicksHealthProfessional
{
    public string $professionalId = '';

    public bool $keepProfessional = true;

    /** A pessoa de quem é o formulário aberto (o id vem do componente, já conferido por quem grava). */
    abstract protected function professionalPersonId(): string;

    /** Preenche o formulário com o profissional escolhido. */
    abstract protected function applyProfessional(HealthProfessional $profissional): void;

    public function pickProfessional(string $id): void
    {
        $profissional = app(HealthProfessionalService::class)->forPerson($this->professionalPersonId())->firstWhere('id', $id);
        abort_if($profissional === null, 404);

        $this->professionalId = $profissional->id;
        $this->applyProfessional($profissional);
    }

    /** @param  array{specialty?: ?string, place?: ?string, address?: ?string, phone?: ?string}  $extras */
    protected function resolveProfessional(string $membroId, ?string $nome, array $extras = []): ?string
    {
        return app(HealthProfessionalService::class)->resolve(
            $membroId, $this->professionalId !== '' ? $this->professionalId : null, $nome, $this->keepProfessional, $extras, app(ProfileContext::class)->member(),
        );
    }

    /** Ao abrir um registro que já existe, não se guarda nada sem a pessoa marcar. */
    protected function resetProfessional(?string $id = null, bool $guardar = true): void
    {
        $this->professionalId = (string) $id;
        $this->keepProfessional = $guardar;
    }

    /**
     * O que o campo precisa: as opções da pessoa e se o nome digitado é novo (aí aparece "Guardar este profissional").
     *
     * @return array{options: Collection<int, HealthProfessional>, canKeep: bool}
     */
    protected function professionalField(string $membroId, string $digitado): array
    {
        $opcoes = $membroId !== '' ? app(HealthProfessionalService::class)->forPerson($membroId) : collect();
        $chave = ExamCatalog::normalize($digitado);

        return ['options' => $opcoes, 'canKeep' => $chave !== '' && ! $opcoes->contains(fn (HealthProfessional $p) => ExamCatalog::normalize($p->name) === $chave)];
    }
}
