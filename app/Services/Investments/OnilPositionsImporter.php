<?php

namespace App\Services\Investments;

use App\Enums\AssetClass;
use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\RecordSource;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\ConsultantCapacityService;
use App\Services\CurrencyRateService;
use App\Services\DashboardService;
use Illuminate\Support\Facades\DB;

/**
 * Carga da posição dos clientes de uma corretora de cripto (Onil) na carteira do consultor. O arquivo traz, por cliente, o saldo e os
 * saques em processamento em reais, USDT, BTC e ETH; o que o Cerne grava é UM investimento por moeda com o total dos dois
 * (saldo + saque em processamento: o dinheiro ainda é do cliente).
 *
 * - Cliente sem conta é cadastrado (ClientOnboardingService::createManagedClient). Cliente com conta mas sem vínculo ativo com o
 *   consultor NÃO é vinculado aqui: o vínculo depende da autorização dele (ConsultantLinkService), então a linha fica de fora.
 * - `$mesclar` junta o conteúdo de um cliente no de outro (ex.: a empresa na conta do sócio): soma moeda a moeda.
 * - Idempotente: cada investimento leva `external_asset_id = onil:{id}:{moeda}`; rodar de novo atualiza a quantidade em vez de duplicar
 *   (e zera/desativa quem ficou sem saldo).
 * - Valor em reais = quantidade vezes a última cotação (CurrencyRateService). Sem cotação, a moeda é pulada e a linha avisa.
 * - Sem `$aplicar` nada é gravado: o relatório mostra o que seria feito.
 *
 * Nunca mexe no que já estava cadastrado à mão (ex.: contratos antigos): só nos investimentos que ele mesmo criou.
 */
class OnilPositionsImporter
{
    public const INSTITUTION = 'Onil';

    /** @var array<string, AssetClass> */
    private const CLASSES = ['BRL' => AssetClass::Brl, 'USDT' => AssetClass::Usdt, 'BTC' => AssetClass::Btc, 'ETH' => AssetClass::Eth];

    public function __construct(
        private readonly ClientOnboardingService $onboarding,
        private readonly CurrencyRateService $cotacoes,
        private readonly ConsultantCapacityService $capacidade,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $linhas  onil_id, nome, email, saldo{BRL,USDT,BTC,ETH}, saque{...}
     * @param  array<string, string>  $mesclar  e-mail de origem => e-mail de destino
     * @param  list<string>  $pular  e-mails que ficam de fora desta carga
     * @return list<array{email: string, nome: string, conta: string, vinculo: string, posicoes: array<string, array{total: string, acao: string}>, aviso: ?string}>
     */
    public function run(User $consultor, array $linhas, array $mesclar = [], array $pular = [], bool $aplicar = false): array
    {
        $porEmail = [];

        foreach ($linhas as $l) {
            $email = strtolower(trim((string) $l['email']));
            $porEmail[$email] = ['onil_id' => (string) $l['onil_id'], 'nome' => (string) $l['nome'], 'totais' => $this->totals($l), 'base' => $this->bases($l)];
        }

        foreach ($mesclar as $de => $para) {
            $de = strtolower($de);
            $para = strtolower($para);

            if (! isset($porEmail[$de], $porEmail[$para])) {
                throw new \InvalidArgumentException("Mesclagem inválida: {$de} → {$para} (os dois precisam estar no arquivo).");
            }

            foreach ($porEmail[$de]['totais'] as $moeda => $valor) {
                $porEmail[$para]['totais'][$moeda] = bcadd($porEmail[$para]['totais'][$moeda], $valor, 10);
            }

            // A base e o resgate também se somam: é a carteira da empresa passando a ser da pessoa.
            foreach ($porEmail[$de]['base'] as $moeda => $b) {
                foreach ($b as $campo => $valor) {
                    $porEmail[$para]['base'][$moeda][$campo] = bcadd($porEmail[$para]['base'][$moeda][$campo] ?? '0', $valor, 10);
                }
            }

            unset($porEmail[$de]);
        }

        $relatorio = [];

        foreach ($porEmail as $email => $cliente) {
            if (in_array($email, array_map('strtolower', $pular), true)) {
                continue;
            }

            $relatorio[] = $this->client($consultor, $email, $cliente, $aplicar);
        }

        return $relatorio;
    }

    /** @return array<string, string> saldo + saque por moeda, 10 casas ("n/d" e vazio contam como zero) */
    private function totals(array $l): array
    {
        $out = [];

        foreach (array_keys(self::CLASSES) as $moeda) {
            $a = $l['saldo'][$moeda] ?? null;
            $b = $l['saque'][$moeda] ?? null;
            $out[$moeda] = bcadd(is_numeric($a) ? (string) $a : '0', is_numeric($b) ? (string) $b : '0', 10);
        }

        return $out;
    }

    /**
     * Depósitos, contratos e saques aprovados por moeda, quando o arquivo traz (a aba "Base e performance"). Sem isso o custo é o do dia.
     *
     * @return array<string, array<string, string>>
     */
    private function bases(array $l): array
    {
        $out = [];

        foreach ((array) ($l['base'] ?? []) as $moeda => $b) {
            $out[$moeda] = array_map(fn ($v) => is_numeric($v) ? bcadd((string) $v, '0', 10) : '0', array_intersect_key((array) $b, array_flip(['depositos', 'valor_inicial_contratos', 'performance_contratos', 'saques_aprovados'])));
        }

        return $out;
    }

    /** @param  array{onil_id: string, nome: string, totais: array<string, string>, base: array<string, array<string, string>>}  $cliente */
    private function client(User $consultor, string $email, array $cliente, bool $aplicar): array
    {
        $linha = ['email' => $email, 'nome' => $cliente['nome'], 'conta' => 'existente', 'vinculo' => 'ativo', 'posicoes' => [], 'aviso' => null];
        $usuario = User::query()->where('email', $email)->first();

        if ($usuario === null) {
            if (! $this->capacidade->hasRoomForNewClient($consultor)) {
                return ['conta' => 'sem vaga', 'aviso' => 'O consultor não tem vaga para mais um cliente: nada foi feito.'] + $linha;
            }

            $linha['conta'] = 'criar';
            $linha['vinculo'] = 'criar (ativo)';

            if ($aplicar) {
                $usuario = $this->onboarding->createManagedClient($consultor, $cliente['nome'], $email);
                $linha['conta'] = 'criada';
                $linha['vinculo'] = 'ativo';
            }
        } else {
            if (! $usuario->isClient()) {
                return ['aviso' => 'O e-mail pertence a uma conta que não é de cliente: nada foi feito.'] + $linha;
            }

            $vinculo = ConsultantClient::query()->where('consultant_id', $consultor->id)->where('client_id', $usuario->id)->first();
            $linha['vinculo'] = $vinculo?->status?->value ?? 'nenhum';

            if ($vinculo?->status !== ConsultantClientStatus::Active) {
                return ['aviso' => 'Cliente sem vínculo ativo com o consultor: peça a autorização pelo convite antes (nada foi feito).'] + $linha;
            }
        }

        foreach ($cliente['totais'] as $moeda => $total) {
            $linha['posicoes'][$moeda] = ['total' => $total, 'acao' => 'sem saldo'];
        }

        // Em simulação o cliente novo ainda não existe: só descreve o que seria gravado.
        $membro = $usuario !== null ? $this->owner($usuario) : null;

        foreach ($cliente['totais'] as $moeda => $total) {
            $zero = bccomp($total, '0', 10) === 0;
            $existente = $membro !== null ? $this->existing($membro, $cliente['onil_id'], $moeda) : null;

            if ($zero && $existente === null) {
                continue;
            }

            $classe = self::CLASSES[$moeda];

            if (! $zero && $classe->isForeignCurrency() && $this->cotacoes->latest($moeda) === null) {
                $linha['posicoes'][$moeda]['acao'] = 'sem cotação';
                $linha['aviso'] = "Ainda não há cotação de {$moeda}: rode cerne:update-currency-rates e repita.";

                continue;
            }

            $linha['posicoes'][$moeda]['acao'] = $zero ? 'zerar' : ($existente === null ? 'criar' : 'atualizar');

            // Base e resgate, quando o arquivo traz a aba de base: sem ela fica o custo do dia (ou o que já estava).
            $base = $zero || ! isset($cliente['base'][$moeda]) ? null : OnilBasePerformance::resolve($total, $cliente['base'][$moeda]);
            $linha['posicoes'][$moeda]['base'] = $base['method'] ?? null;

            if ($aplicar && $membro !== null) {
                $this->save($consultor, $membro, $cliente['onil_id'], $moeda, $classe, $total, $existente, $base);
                $linha['posicoes'][$moeda]['acao'] .= ' ✓';
            }
        }

        return $linha;
    }

    /** O titular do perfil do cliente: dono dos investimentos que a carga cria. */
    private function owner(User $usuario): ?ProfileMember
    {
        $perfil = FinancialProfile::query()->where('owner_user_id', $usuario->id)->first();

        return $perfil === null ? null : ProfileMember::query()->where('profile_id', $perfil->id)->where('role', MemberRole::Primary)->first();
    }

    private function existing(ProfileMember $membro, string $onilId, string $moeda): ?InvestmentRecord
    {
        return InvestmentRecord::withoutGlobalScopes()
            ->where('profile_id', $membro->profile_id)
            ->where('external_asset_id', $this->key($onilId, $moeda))
            ->first();
    }

    private function key(string $onilId, string $moeda): string
    {
        return "onil:{$onilId}:{$moeda}";
    }

    /** @param  array{invested: string, realized: string, method: string}|null  $base */
    private function save(User $consultor, ProfileMember $membro, string $onilId, string $moeda, AssetClass $classe, string $total, ?InvestmentRecord $existente, ?array $base = null): void
    {
        DB::transaction(function () use ($consultor, $membro, $onilId, $moeda, $classe, $total, $existente, $base): void {
            $zero = bccomp($total, '0', 10) === 0;
            $estrangeira = $classe->isForeignCurrency();
            $reais = $estrangeira ? ($zero ? '0.00' : $this->cotacoes->toBrl($total, $moeda)) : bcadd($total, '0', 2);
            $campos = ['current_amount' => $reais, 'native_amount' => $estrangeira ? bcadd($total, '0', 10) : null, 'is_active' => ! $zero];

            if ($base !== null) {
                $semResgate = bccomp($base['realized'], '0', 10) === 0;

                $campos += $estrangeira
                    ? [
                        'invested_native' => bcadd($base['invested'], '0', 10),
                        'realized_native' => $semResgate ? null : bcadd($base['realized'], '0', 10),
                        'invested_amount' => $this->cotacoes->toBrl($base['invested'], $moeda),
                        'realized_amount' => $semResgate ? null : $this->cotacoes->toBrl($base['realized'], $moeda),
                    ]
                    : [
                        'invested_amount' => bcadd($base['invested'], '0.005', 2),
                        'realized_amount' => $semResgate ? null : bcadd($base['realized'], '0.005', 2),
                    ];
            }

            if ($existente !== null) {
                // O custo só entra uma vez, no cadastro: depois disso o valor muda pela cotação e pelo saldo, não por aqui.
                $existente->forceFill($campos + ($existente->invested_amount === null ? ['invested_amount' => $reais] : []))->save();
            } else {
                InvestmentRecord::create($campos + ['invested_amount' => $reais] + [
                    'profile_id' => $membro->profile_id,
                    'member_id' => $membro->id,
                    'sector' => $classe->sector(),
                    'asset_class' => $classe,
                    'name' => 'Carteira '.self::INSTITUTION.' · '.$classe->label(),
                    'institution' => self::INSTITUTION,
                    'source' => RecordSource::Manual,
                    'external_asset_id' => $this->key($onilId, $moeda),
                    'is_private' => false,
                    'created_by_user_id' => $consultor->id,
                ]);
            }

            DashboardService::forgetProfile($membro->profile_id);
        });
    }
}
