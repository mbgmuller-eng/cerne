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
    // Saldo em corretora de cripto/câmbio, por moeda (ex.: Onil). Valor na moeda de origem em native_amount.
    case Usdt = 'usdt';
    case Btc = 'btc';
    case Eth = 'eth';
    case Brl = 'brl';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
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
            self::Usdt => 'USDT',
            self::Btc => 'BTC',
            self::Eth => 'ETH',
            self::Brl => 'BRL',
            self::Outro => 'Outro',
        };
    }

    /** A moeda em que o valor deste ativo é guardado (BRL para tudo que não é USDT, BTC ou ETH). */
    public function currency(): string
    {
        return match ($this) {
            self::Usdt => 'USDT',
            self::Btc => 'BTC',
            self::Eth => 'ETH',
            default => 'BRL',
        };
    }

    /** Guardado em USDT, BTC ou ETH: o valor em reais vem da quantidade vezes a cotação do dia. */
    public function isForeignCurrency(): bool
    {
        return $this->currency() !== 'BRL';
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
            self::Poupanca => InvestmentSector::Reserve,
            self::Previdencia => InvestmentSector::Retirement,
            self::Cdb, self::Tesouro, self::Lca, self::Lci, self::Consorcio => InvestmentSector::FixedIncome,
            self::Acao, self::Fii, self::Etf, self::FundoInfra, self::Cripto, self::Fundo, self::Outro, self::Usdt, self::Btc, self::Eth, self::Brl => InvestmentSector::VariableIncome,
            self::EtfInternacional, self::AcaoExterior => InvestmentSector::International,
        };
    }

    /**
     * Classe usada na comparação com a alocação RECOMENDADA
     * (recommended_allocations, ver AllocationAssetClass). Previdência,
     * poupança e "outro" ficam de fora — não fazem parte da alocação de
     * investimento recomendada pelo consultor. Um investimento marcado
     * como reserva (investment_records.reserve_type) também fica fora
     * dessa comparação, mas isso é decidido em quem lê `reserve_type`
     * antes de checar isto — não depende da classe do ativo, ver
     * InvestmentsIndex::getInvestorAllocationsProperty().
     */
    public function allocationClass(): ?AllocationAssetClass
    {
        return match ($this) {
            self::Cdb, self::Tesouro, self::Lca, self::Lci, self::Consorcio => AllocationAssetClass::FixedIncome,
            self::Fundo, self::FundoInfra => AllocationAssetClass::Funds,
            self::Acao, self::Fii => AllocationAssetClass::EquitiesFiis,
            self::Cripto, self::Usdt, self::Btc, self::Eth => AllocationAssetClass::DigitalAssets,
            self::Etf => AllocationAssetClass::Etfs,
            self::EtfInternacional, self::AcaoExterior => AllocationAssetClass::International,
            self::Previdencia, self::Poupanca, self::Outro, self::Brl => null,
        };
    }
}
