<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Classe do ativo concreto na carteira (investment_records.asset_class).
 *
 * Os valores seguem a especificação em português; não renomear sem
 * migração, pois são gravados no banco.
 */
enum AssetClass: string
{
    use HasOptions;

    case ReservaPaz = 'reserva_paz';
    case ReservaOportunidade = 'reserva_oportunidade';
    case Previdencia = 'previdencia';
    case Cdb = 'cdb';
    case Tesouro = 'tesouro';
    case Lca = 'lca';
    case Lci = 'lci';
    case Fundo = 'fundo';
    case Acao = 'acao';
    case Fii = 'fii';
    case Etf = 'etf';
    case FundoInfra = 'fundo_infra';
    case Cripto = 'cripto';
    case EtfInternacional = 'etf_internacional';
    case AcaoExterior = 'acao_exterior';
    case Poupanca = 'poupanca';
    case Consorcio = 'consorcio';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::ReservaPaz => 'Reserva de paz',
            self::ReservaOportunidade => 'Reserva de oportunidade',
            self::Previdencia => 'Previdência',
            self::Cdb => 'CDB',
            self::Tesouro => 'Tesouro Direto',
            self::Lca => 'LCA',
            self::Lci => 'LCI',
            self::Fundo => 'Fundo',
            self::Acao => 'Ação',
            self::Fii => 'FII',
            self::Etf => 'ETF',
            self::FundoInfra => 'Fundo de infraestrutura',
            self::Cripto => 'Cripto',
            self::EtfInternacional => 'ETF internacional',
            self::AcaoExterior => 'Ação no exterior',
            self::Poupanca => 'Poupança',
            self::Consorcio => 'Consórcio',
            self::Outro => 'Outro',
        };
    }

    /**
     * Ativos negociados em cotas — só estes usam preço médio, quantidade
     * e as transações de compra/venda (ver InvestmentTransactionService).
     */
    public function hasQuantity(): bool
    {
        return in_array($this, [
            self::Acao,
            self::Fii,
            self::Etf,
            self::FundoInfra,
            self::Cripto,
            self::EtfInternacional,
            self::AcaoExterior,
            self::Fundo,
        ], true);
    }

    public function sector(): InvestmentSector
    {
        return match ($this) {
            self::ReservaPaz, self::ReservaOportunidade, self::Poupanca => InvestmentSector::Reserve,
            self::Previdencia => InvestmentSector::Retirement,
            self::Cdb, self::Tesouro, self::Lca, self::Lci, self::Consorcio => InvestmentSector::FixedIncome,
            self::Acao, self::Fii, self::Etf, self::FundoInfra, self::Cripto, self::Fundo, self::Outro => InvestmentSector::VariableIncome,
            self::EtfInternacional, self::AcaoExterior => InvestmentSector::International,
        };
    }
}
