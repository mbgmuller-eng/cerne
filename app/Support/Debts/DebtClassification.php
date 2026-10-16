<?php

namespace App\Support\Debts;

use App\Enums\ConsortiumType;
use App\Enums\DebtKind;
use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;

/**
 * Classificação sugerida para as parcelas de uma dívida, usando as categorias que já existem no Cerne:
 * Habitação > Prestação da casa (imóvel), Transporte > Prestação Carro (veículo) e Financeiros > Empréstimo.
 * É só o ponto de partida: a pessoa escolhe a categoria e pode mudá-la depois.
 */
final class DebtClassification
{
    public const PROPERTY = 'property';

    public const VEHICLE = 'vehicle';

    public const OTHER = 'other';

    /** @return array<string, string> o que foi financiado */
    public static function assets(): array
    {
        return [self::PROPERTY => 'Imóvel', self::VEHICLE => 'Veículo', self::OTHER => 'Outro bem'];
    }

    /**
     * Antes de contemplado, a parcela do consórcio é investimento (a categoria "Consórcio", da lista de Investimento): é dinheiro
     * sendo juntado para a carta, não gasto com casa ou carro.
     *
     * @return array{category_id: ?string, subcategory_id: null}
     */
    public static function consortiumInvestment(): array
    {
        $cat = ExpenseCategory::available()->where('necessity', Necessity::Investment->value)->where('name', 'Consórcio')->first();

        return ['category_id' => $cat?->id, 'subcategory_id' => null];
    }

    /**
     * Depois de contemplado, a parcela passa a ser despesa do que foi comprado: imóvel vai para Habitação, veículo para Transporte.
     * Consórcio de serviços não tem categoria sugerida: a pessoa escolhe.
     *
     * @return array{category_id: ?string, subcategory_id: ?string}
     */
    public static function consortiumContemplated(ConsortiumType $tipo): array
    {
        return match ($tipo) {
            ConsortiumType::Property => self::suggest(DebtKind::Financing, self::PROPERTY),
            ConsortiumType::Vehicle => self::suggest(DebtKind::Financing, self::VEHICLE),
            ConsortiumType::Services => ['category_id' => null, 'subcategory_id' => null],
        };
    }

    /**
     * @return array{category_id: ?string, subcategory_id: ?string}
     */
    public static function suggest(DebtKind $tipo, ?string $bem = null): array
    {
        [$categoria, $subcategoria] = match (true) {
            $tipo === DebtKind::Financing && $bem === self::PROPERTY => ['Habitação', 'Prestação da casa'],
            $tipo === DebtKind::Financing && $bem === self::VEHICLE => ['Transporte', 'Prestação Carro'],
            $tipo === DebtKind::Loan, $tipo === DebtKind::Debt => ['Financeiros', 'Empréstimo'],
            default => [null, null],
        };

        if ($categoria === null) {
            return ['category_id' => null, 'subcategory_id' => null];
        }

        $cat = ExpenseCategory::available()->where('name', $categoria)->first();
        $sub = $cat?->subcategories()->where('name', $subcategoria)->where('is_active', true)->first();

        return ['category_id' => $cat?->id, 'subcategory_id' => $sub?->id];
    }
}
