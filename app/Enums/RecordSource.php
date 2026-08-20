<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Origem do registro. Dado vindo de `open_finance` é somente leitura na UI
 * até que o usuário destrave a edição (is_locked_by_sync), momento em que
 * o registro vira `manual` e para de ser sobrescrito pela sincronização.
 * Ver seção 11 da especificação — Open Finance é v2, não bloqueia o MVP.
 */
enum RecordSource: string
{
    use HasOptions;

    case Manual = 'manual';
    case PdfImport = 'pdf_import';
    case OpenFinance = 'open_finance';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::PdfImport => 'Importado de PDF',
            self::OpenFinance => 'Open Finance',
        };
    }

    public function isReadOnlyByDefault(): bool
    {
        return $this === self::OpenFinance;
    }
}
