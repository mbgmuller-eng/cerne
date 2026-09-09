<?php

namespace App\Enums;

/**
 * Como a carteira é exibida na tela de Investimentos ("Carteira por
 * setor") — mais fina que InvestmentSector (que só tem 5 valores e
 * empilha quase tudo em "Renda fixa"/"Renda variável"), e mais explícita
 * que AllocationAssetClass (que não tem onde colocar reserva/previdência
 * porque elas nem entram na alocação recomendada).
 *
 * Reserva de paz/oportunidade viram grupo próprio, não somem dentro de
 * Renda fixa; Previdência e Internacional também ficam com grupo
 * próprio, mesmo não fazendo parte das 6 classes com meta recomendada
 * (ver InvestorType::recommendedAllocations()) — continuam contando no
 * patrimônio total, só não entram na comparação de %.
 *
 * A ORDEM DE DECLARAÇÃO É A ORDEM DE EXIBIÇÃO — ver
 * InvestmentRecord::displayGroup() (decide o grupo de cada investimento)
 * e InvestmentsIndex::getByGroupProperty() (agrupa nesta ordem).
 */
enum PortfolioDisplayGroup: string
{
    case ReservaPaz = 'reserva_paz';
    case ReservaOportunidade = 'reserva_oportunidade';
    case FixedIncome = 'fixed_income';
    case Funds = 'funds';
    case EquitiesFiis = 'equities_fiis';
    case DigitalAssets = 'digital_assets';
    case FxCurrencies = 'fx_currencies';
    case Etfs = 'etfs';
    case Retirement = 'retirement';
    case International = 'international';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ReservaPaz => 'Reserva de paz',
            self::ReservaOportunidade => 'Reserva de oportunidade',
            self::FixedIncome => 'Renda fixa',
            self::Funds => 'Fundos de investimento',
            self::EquitiesFiis => 'Ações e FIIs',
            self::DigitalAssets => 'Ativos digitais',
            self::FxCurrencies => 'Moedas',
            self::Etfs => 'ETFs',
            self::Retirement => 'Previdência',
            self::International => 'Internacional',
            self::Other => 'Outros',
        };
    }

    /**
     * Cor fixa por grupo (mesma em claro/escuro, igual ao padrão já usado
     * em AllocationAssetClass::color() pros pontinhos da carteira
     * recomendada) — aqui alimenta as colunas empilhadas da "Evolução do
     * patrimônio" por grupo. As 6 classes com meta recomendada reusam
     * exatamente o hex de AllocationAssetClass, pra a mesma classe ter a
     * mesma cor em qualquer gráfico da tela.
     */
    public function color(): string
    {
        return match ($this) {
            self::ReservaPaz => '#2563eb',
            self::ReservaOportunidade => '#ea580c',
            self::FixedIncome => '#0f766e',
            self::Funds => '#6366f1',
            self::EquitiesFiis => '#d97706',
            self::DigitalAssets => '#9333ea',
            self::FxCurrencies => '#0891b2',
            self::Etfs => '#e11d48',
            self::Retirement => '#1e3a8a',
            self::International => '#475569',
            self::Other => '#94a3b8',
        };
    }
}
