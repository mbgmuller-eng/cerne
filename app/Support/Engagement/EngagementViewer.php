<?php

namespace App\Support\Engagement;

/**
 * Quem está olhando o acompanhamento de um perfil, e de qual relação de consultoria. O cliente (dono ou cônjuge) enxerga a
 * relação com o consultor vinculado e ativo; o consultor enxerga só a sua.
 */
final class EngagementViewer
{
    /** @param  list<string>  $consultantIds */
    public function __construct(
        public readonly string $role,
        public readonly string $userId,
        public readonly string $profileId,
        public readonly array $consultantIds,
    ) {}

    public function isConsultant(): bool
    {
        return $this->role === 'consultant';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    /** O consultor em cuja relação um registro novo nasce (o próprio, ou o primeiro vinculado, para o cliente). */
    public function consultantId(): string
    {
        return $this->consultantIds[0];
    }
}
