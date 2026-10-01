<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Os 3 pacotes de assinatura — mesmos pra quem assina direto e pro que o
 * profissional escolhe cobrir pros clientes vinculados (ver
 * SubscriptionKind). `modules()` é a ÚNICA fonte de verdade de "o que cada
 * pacote libera": tanto o gate de acesso (EntitlementService) quanto a
 * tela de escolha de pacote leem daqui, nunca duplicam a lista.
 */
enum SubscriptionBundle: string
{
    use HasOptions;

    case FinancasSegurosDocumentos = 'financas_seguros_documentos';
    case SaudeDocumentos = 'saude_documentos';
    case Completo = 'completo';

    public function label(): string
    {
        return match ($this) {
            self::FinancasSegurosDocumentos => 'Finanças + Seguros + Documentos',
            self::SaudeDocumentos => 'Saúde + Documentos',
            self::Completo => 'Completo',
        };
    }

    /** @return list<PlatformModule> */
    public function modules(): array
    {
        return match ($this) {
            self::FinancasSegurosDocumentos => [PlatformModule::Financas, PlatformModule::Seguros, PlatformModule::Documentos],
            self::SaudeDocumentos => [PlatformModule::Saude, PlatformModule::Documentos],
            self::Completo => [PlatformModule::Financas, PlatformModule::Seguros, PlatformModule::Documentos, PlatformModule::Saude],
        };
    }

    public function includes(PlatformModule $modulo): bool
    {
        return in_array($modulo, $this->modules(), true);
    }
}
