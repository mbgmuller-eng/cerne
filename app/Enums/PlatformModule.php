<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * A qual produto do Cerne uma tela "pertence" — é o que
 * EntitlementService checa pra liberar ou barrar acesso. Ver
 * SubscriptionBundle pra quais módulos cada pacote de assinatura libera.
 */
enum PlatformModule: string
{
    use HasOptions;

    case Financas = 'financas';
    case Seguros = 'seguros';
    case Documentos = 'documentos';
    case Saude = 'saude';

    public function label(): string
    {
        return match ($this) {
            self::Financas => 'Finanças',
            self::Seguros => 'Seguros',
            self::Documentos => 'Documentos',
            self::Saude => 'Saúde',
        };
    }

    /** Uma linha sobre o que o módulo entrega, usada nas páginas de plano e no resumo do pedido. */
    public function summary(): string
    {
        return match ($this) {
            self::Financas => 'fluxo de caixa, contas fixas, investimentos e objetivos',
            self::Seguros => 'apólices, vencimentos e renovações',
            self::Documentos => 'CNH, passaporte, apólices e exames em um cofre com controle de acesso',
            self::Saude => 'treino com histórico, ficha de saúde e QR de emergência',
        };
    }
}
