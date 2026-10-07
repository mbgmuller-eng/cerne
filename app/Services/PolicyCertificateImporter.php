<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\Insurer;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carga em lote de certificados de seguro de vida (hoje Icatu): acha o cliente do consultor
 * dono de cada certificado, cria ou atualiza a apólice e guarda o PDF em Documentos, já ligado
 * à apólice.
 *
 * Só age sobre clientes ATIVOS do consultor informado, com o mesmo contexto que ele teria na
 * tela (ProfileContext de consultor), então privacidade do casal e escopos valem como no app.
 * Casamento ambíguo ou fraco nunca é aplicado: vira "revisar" e fica para uma pessoa decidir.
 */
class PolicyCertificateImporter
{
    public const INSURER = 'Icatu Seguros';

    /** Nota mínima para aplicar sozinho: nome igual, ou nome contido com primeiro e último iguais. */
    private const MIN_SCORE = 80;

    /** Abaixo disto nem entra como sugestão. */
    private const MIN_SUGGESTION = 60;

    /** Apólices já "tomadas" por um certificado desta rodada, para dois certificados não disputarem a mesma apólice sem número. */
    private array $reivindicadas = [];

    public function __construct(private DocumentService $documentos) {}

    /**
     * @param  list<array<string, mixed>>  $certificados  saída do leitor de PDFs (ver comando)
     * @param  list<string>  $aceitar  números de apólice cujo casamento por nome parcial uma pessoa já conferiu
     * @return list<array<string, mixed>> uma linha por certificado
     */
    public function run(User $consultor, array $certificados, ?string $pdfDir, bool $apply, array $aceitar = []): array
    {
        $this->reivindicadas = [];
        $aceitar = array_map(fn (string $n) => $this->digitos($n), $aceitar);
        $candidatos = $this->candidatos($consultor);
        $vistos = [];
        $linhas = [];

        foreach ($certificados as $cert) {
            $linha = [
                'arquivo' => $cert['arquivo'],
                'nome' => $cert['nome'],
                'apolice' => $cert['apolice'],
                'status' => 'ok',
                'cliente' => null,
                'membro' => null,
                'acao' => null,
                'mudancas' => [],
                'documento' => null,
                'motivo' => null,
            ];

            $chave = $this->digitos($cert['apolice']);
            if (isset($vistos[$chave])) {
                $linhas[] = ['status' => 'duplicada', 'motivo' => 'mesma apólice de '.$vistos[$chave]] + $linha;

                continue;
            }
            $vistos[$chave] = $cert['arquivo'];

            $achado = $this->casar($cert, $candidatos, in_array($chave, $aceitar, true));

            if ($achado['status'] !== 'ok') {
                $linhas[] = ['status' => $achado['status'], 'motivo' => $achado['motivo']] + $linha;

                continue;
            }

            /** @var array{perfil: FinancialProfile, membro: ProfileMember} $dono */
            $dono = $achado['candidato'];
            $linha['cliente'] = $dono['perfil']->owner->name;
            $linha['membro'] = $dono['membro']->name;

            $this->entrarNoPerfil($consultor, $dono['perfil']);

            try {
                $linha = $this->processar($cert, $dono, $consultor, $pdfDir, $apply, $linha);
            } finally {
                app(ProfileContext::class)->clear();
            }

            $linhas[] = $linha;
        }

        return $linhas;
    }

    /** @return array<string, mixed> */
    private function processar(array $cert, array $dono, User $consultor, ?string $pdfDir, bool $apply, array $linha): array
    {
        $membro = $dono['membro'];

        // Mesma apólice cadastrada para OUTRO cliente: não mexe, alguém precisa olhar.
        $emOutroPerfil = InsurancePolicy::withoutProfileScope()
            ->where('profile_id', '!=', $dono['perfil']->id)
            ->whereIn('policy_number', array_unique([$cert['apolice'], $this->digitos($cert['apolice'])]))
            ->exists();

        if ($emOutroPerfil) {
            return ['status' => 'revisar', 'motivo' => 'apólice já cadastrada para outro cliente'] + $linha;
        }

        $existente = $this->apoliceExistente($cert, $membro);
        $dados = $this->dadosDaApolice($cert, $consultor, $membro);

        if ($existente === null) {
            $linha['acao'] = 'criar';
        } else {
            $linha['acao'] = 'atualizar';
            $linha['mudancas'] = $this->diferencas($existente, $dados);
        }

        $arquivo = $pdfDir !== null ? rtrim($pdfDir, '/\\').DIRECTORY_SEPARATOR.$cert['arquivo'] : null;
        if ($arquivo !== null && ! is_file($arquivo)) {
            return ['status' => 'erro', 'motivo' => 'PDF não encontrado na pasta'] + $linha;
        }

        $nomeAmigavel = 'Apólice Icatu '.$cert['apolice'].'.pdf';

        if ($existente !== null) {
            $linha['documento'] = Document::query()
                ->where('insurance_policy_id', $existente->id)
                ->where('original_filename', $nomeAmigavel)
                ->exists() ? 'já existe' : 'anexar';
        } else {
            $linha['documento'] = 'anexar';
        }

        if (! $apply) {
            return $linha;
        }

        DB::transaction(function () use ($existente, $dados, $cert, $membro, $arquivo, $nomeAmigavel, $linha): void {
            if ($existente === null) {
                Insurer::resolveOrSuggest(self::INSURER);
                $apolice = InsurancePolicy::create($dados + [
                    'insurer_name' => self::INSURER,
                    'is_active' => true,
                    'created_by_user_id' => auth()->id(),
                ]);
            } else {
                $existente->update($dados);
                $apolice = $existente;
            }

            if ($arquivo !== null && $linha['documento'] === 'anexar') {
                $this->documentos->upload(
                    new UploadedFile($arquivo, $nomeAmigavel, 'application/pdf', null, true),
                    [
                        'category' => DocumentCategory::InsurancePolicy->value,
                        'title' => 'Apólice Icatu '.$cert['apolice'],
                        'member_id' => $membro->id,
                        'insurance_policy_id' => $apolice->id,
                    ],
                    $membro,
                );
            }
        });

        return $linha;
    }

    /** @return array<string, mixed> */
    private function dadosDaApolice(array $cert, User $consultor, ProfileMember $membro): array
    {
        $anual = ($cert['periodicidade'] ?? 'MENSAL') === 'ANUAL';
        $premio = (string) $cert['premio_total'];

        $coberturas = [];
        $morte = null;
        $maior = '0';
        foreach ($cert['coberturas'] as $cobertura) {
            $valor = (string) $cobertura['titular'];
            $coberturas[] = ['name' => $cobertura['name'], 'value' => $valor];

            if (str_starts_with($cobertura['name'], 'Morte')) {
                $morte = $valor;
            }
            if (bccomp($valor, $maior, 2) > 0) {
                $maior = $valor;
            }
        }

        $notas = array_filter([
            'Certificado Icatu nº '.$cert['certificado'].' ('.$cert['produto'].'), emitido em '.$cert['emissao'].'.',
            ! empty($cert['forma_pagamento']) ? 'Pagamento: '.$cert['forma_pagamento'].'.' : null,
            ! empty($cert['indice']) ? 'Atualização anual: '.$cert['indice'].'.' : null,
            'Seguro vitalício enquanto o prêmio estiver em dia.',
        ]);

        return [
            'member_id' => $membro->id,
            // Corretor cadastrando já compartilha consigo (mesma regra do formulário de apólice).
            'broker_id' => $consultor->isBroker() ? $consultor->id : null,
            'insurance_type' => InsuranceType::Vida,
            'policy_number' => $cert['apolice'],
            'coverage_amount' => $morte ?? $maior,
            'coverages' => $coberturas,
            'beneficiaries' => array_map(fn (array $b) => [
                'name' => $b['name'],
                'percentage' => $b['percentage'],
                'relationship' => $b['relationship'],
            ], $cert['beneficiarios']),
            'monthly_premium' => $anual ? bcdiv($premio, '12', 2) : $premio,
            'annual_premium' => $anual ? $premio : null,
            'payment_frequency' => $anual ? PaymentFrequency::Annual : PaymentFrequency::Monthly,
            'start_date' => CarbonImmutable::createFromFormat('d/m/Y', $cert['vigencia_apolice'])->toDateString(),
            'expiry_date' => null,
            'notes' => implode(' ', $notas),
        ];
    }

    /**
     * Só o que muda de verdade, para o relatório mostrar antes de aplicar.
     *
     * @return array<string, array{de: mixed, para: mixed}>
     */
    private function diferencas(InsurancePolicy $atual, array $novos): array
    {
        $campos = ['coverage_amount', 'monthly_premium', 'annual_premium', 'start_date', 'policy_number', 'member_id'];
        $mudou = [];

        foreach ($campos as $campo) {
            $antes = $atual->{$campo};
            $antes = $antes instanceof \DateTimeInterface ? $antes->format('Y-m-d') : $antes;

            if ((string) $antes !== (string) $novos[$campo]) {
                $mudou[$campo] = ['de' => $antes, 'para' => $novos[$campo]];
            }
        }

        return $mudou;
    }

    /** Por número da apólice; sem número, só se houver UMA apólice de vida da Icatu sem número para a pessoa. */
    private function apoliceExistente(array $cert, ProfileMember $membro): ?InsurancePolicy
    {
        $alvo = $this->digitos($cert['apolice']);
        $todas = InsurancePolicy::query()->get();

        $porNumero = $todas->first(fn (InsurancePolicy $p) => $p->policy_number !== null && $this->digitos($p->policy_number) === $alvo);
        if ($porNumero !== null) {
            $this->reivindicadas[] = $porNumero->id;

            return $porNumero;
        }

        $semNumero = $todas->filter(fn (InsurancePolicy $p) => blank($p->policy_number)
            && ! in_array($p->id, $this->reivindicadas, true)
            && $p->member_id === $membro->id
            && $p->insurance_type === InsuranceType::Vida
            && str_contains(Str::lower(Str::ascii($p->insurer_name)), 'icatu'));

        if ($semNumero->count() !== 1) {
            return null;
        }

        $this->reivindicadas[] = $semNumero->first()->id;

        return $semNumero->first();
    }

    /**
     * @return list<array{perfil: FinancialProfile, membro: ProfileMember, tokens: list<string>, nascimento: ?string, cpf: ?string}>
     */
    private function candidatos(User $consultor): array
    {
        $clientes = ConsultantClient::query()
            ->where('consultant_id', $consultor->id)
            ->active()
            ->pluck('client_id');

        $lista = [];
        $perfis = FinancialProfile::query()
            ->whereIn('owner_user_id', $clientes)
            ->with(['owner', 'activeMembers.user'])
            ->get();

        foreach ($perfis as $perfil) {
            foreach ($perfil->activeMembers as $membro) {
                $usuario = $membro->user;
                $lista[] = [
                    'perfil' => $perfil,
                    'membro' => $membro,
                    'tokens' => $this->tokens($membro->name),
                    'nascimento' => ($membro->birthdate ?? $usuario?->birthdate)?->toDateString(),
                    'cpf' => $usuario?->cpf_cnpj ? $this->digitos($usuario->cpf_cnpj) : null,
                ];
            }
        }

        return $lista;
    }

    /**
     * @param  list<array<string, mixed>>  $candidatos
     * @return array{status: string, motivo?: string, candidato?: array<string, mixed>}
     */
    private function casar(array $cert, array $candidatos, bool $conferido = false): array
    {
        $pontuados = [];
        foreach ($candidatos as $candidato) {
            $nota = $this->nota($cert, $candidato);
            if ($nota >= self::MIN_SUGGESTION) {
                $pontuados[] = ['nota' => $nota, 'candidato' => $candidato];
            }
        }

        if ($pontuados === []) {
            return ['status' => 'sem_cliente', 'motivo' => 'nenhum cliente vinculado com esse nome'];
        }

        usort($pontuados, fn ($a, $b) => $b['nota'] <=> $a['nota']);
        $melhor = $pontuados[0];
        $empatados = array_filter($pontuados, fn ($p) => $p['nota'] === $melhor['nota']);

        if (count($empatados) > 1) {
            return ['status' => 'revisar', 'motivo' => 'mais de um cliente combina: '.implode(' / ', array_map(
                fn ($p) => $p['candidato']['membro']->name.' ('.$p['candidato']['perfil']->owner->name.')',
                $empatados,
            ))];
        }

        if ($melhor['nota'] < self::MIN_SCORE && ! $conferido) {
            $c = $melhor['candidato'];

            return ['status' => 'revisar', 'motivo' => 'parece ser '.$c['membro']->name.' ('.$c['perfil']->owner->name.'), mas o nome não bate por inteiro'];
        }

        return ['status' => 'ok', 'candidato' => $melhor['candidato']];
    }

    /** 0 = não é a pessoa; 100 = CPF igual ou nome e nascimento iguais. */
    private function nota(array $cert, array $candidato): int
    {
        $cpf = ! empty($cert['cpf']) ? $this->digitos($cert['cpf']) : null;
        if ($cpf !== null && $candidato['cpf'] !== null) {
            return $cpf === $candidato['cpf'] ? 100 : 0;
        }

        $nascimento = ! empty($cert['nascimento'])
            ? CarbonImmutable::createFromFormat('d/m/Y', $cert['nascimento'])->toDateString()
            : null;
        $mesmoNascimento = $nascimento !== null && $candidato['nascimento'] !== null && $nascimento === $candidato['nascimento'];
        if ($nascimento !== null && $candidato['nascimento'] !== null && ! $mesmoNascimento) {
            return 0;
        }

        $a = $this->tokens($cert['nome']);
        $b = $candidato['tokens'];
        if ($a === [] || $b === []) {
            return 0;
        }

        if ($a === $b) {
            $nota = 90;
        } else {
            [$curto, $longo] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
            if (count($curto) < 2 || array_diff($curto, $longo) !== []) {
                return 0;
            }
            $nota = ($curto[0] === $longo[0] && end($curto) === end($longo)) ? 80 : (($curto[0] === $longo[0]) ? 60 : 0);
        }

        return $mesmoNascimento && $nota >= self::MIN_SUGGESTION ? 100 : $nota;
    }

    /** @return list<string> palavras do nome sem acento, sem "de/da/do..." e sem letras soltas (nome truncado no PDF) */
    private function tokens(string $nome): array
    {
        $palavras = preg_split('/[^a-z]+/', Str::lower(Str::ascii($nome)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($palavras, fn (string $p) => strlen($p) > 1 && ! in_array($p, ['de', 'da', 'do', 'das', 'dos', 'e'], true)));
    }

    private function digitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor);
    }

    /** Mesmo contexto do consultor na tela do cliente (ver SetProfileContext): sem membro próprio e `asConsultant`. */
    private function entrarNoPerfil(User $consultor, FinancialProfile $perfil): void
    {
        Auth::setUser($consultor);
        app(ProfileContext::class)->set($perfil, null, true);
    }
}
