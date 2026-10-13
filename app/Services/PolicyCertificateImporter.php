<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\PolicyRevisionSource;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\Insurer;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\LifeCoverage;
use App\Support\PersonName;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Carga em lote de certificados de seguro de vida (Icatu e Azos): acha o cliente do consultor
 * dono de cada certificado, cria ou atualiza a apólice e guarda o PDF em Documentos, já ligado
 * à apólice.
 *
 * Só age sobre clientes ATIVOS do consultor informado, com o mesmo contexto que ele teria na
 * tela (ProfileContext de consultor), então privacidade do casal e escopos valem como no app.
 * Casamento ambíguo ou fraco nunca é aplicado: vira "revisar" e fica para uma pessoa decidir.
 *
 * Sem nenhuma chave extra o certificado é tratado como Icatu (o primeiro uso). Chaves opcionais
 * por certificado para outras seguradoras: `seguradora` e `seguradora_curta` (nome gravado e nome nos
 * Documentos), `notas` (texto da observação, no lugar do texto padrão da Icatu), `vencimento` (d/m/Y),
 * `capital_principal` (o valor da apólice; nulo quando não há "morte qualquer causa"),
 * `padronizar_coberturas` (aplica o padrão do Cerne, ver LifeCoverage), `coberturas[].franquia` e
 * `email_pdf` (o e-mail que consta no PDF: se for o de alguém da carteira, casa por ele; senão cai no nome).
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

    public function __construct(private DocumentService $documentos, private InsurancePolicyHistoryService $historico) {}

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

        $nomeAmigavel = 'Apólice '.$this->seguradoraCurta($cert).' '.$cert['apolice'].'.pdf';

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
            // A versão vale desde a data que o certificado diz ("informações vigentes a partir de").
            $vigenteDesde = CarbonImmutable::createFromFormat('d/m/Y', $cert['informacoes_vigentes'] ?? $cert['vigencia_apolice'])->toDateString();

            if ($existente === null) {
                Insurer::resolveOrSuggest($this->seguradora($cert));
                $apolice = InsurancePolicy::create($dados + [
                    'insurer_name' => $this->seguradora($cert),
                    'is_active' => true,
                    'created_by_user_id' => auth()->id(),
                ]);
                $versao = $this->historico->recordCreated($apolice, PolicyRevisionSource::Import, $vigenteDesde);
            } else {
                $versao = $this->historico->apply($existente, $dados, PolicyRevisionSource::Import, $vigenteDesde, always: true);
                $apolice = $existente;
            }

            $pdf = Document::query()->where('insurance_policy_id', $apolice->id)->where('original_filename', $nomeAmigavel)->first();

            if ($arquivo !== null && $linha['documento'] === 'anexar') {
                $pdf = $this->documentos->upload(
                    new UploadedFile($arquivo, $nomeAmigavel, 'application/pdf', null, true),
                    [
                        'category' => DocumentCategory::InsurancePolicy->value,
                        'title' => 'Apólice '.$this->seguradoraCurta($cert).' '.$cert['apolice'],
                        'member_id' => $membro->id,
                        'insurance_policy_id' => $apolice->id,
                    ],
                    $membro,
                );
            }

            $this->historico->attachDocument($versao, $pdf?->id);
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
            $coberturas[] = ['name' => $cobertura['name'], 'value' => $valor]
                + (! empty($cobertura['franquia']) ? ['deductible' => $cobertura['franquia']] : []);

            if (str_starts_with($cobertura['name'], 'Morte')) {
                $morte = $valor;
            }
            if (bccomp($valor, $maior, 2) > 0) {
                $maior = $valor;
            }
        }

        if (! empty($cert['padronizar_coberturas'])) {
            $coberturas = LifeCoverage::standardize($coberturas);
        }

        $notas = isset($cert['notas'])
            ? [(string) $cert['notas']]
            : array_filter([
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
            // Sem "morte qualquer causa" (ex.: só doenças graves) não há capital principal a inventar:
            // quem informa `capital_principal` (mesmo nulo) assume essa decisão.
            'coverage_amount' => array_key_exists('capital_principal', $cert) ? $cert['capital_principal'] : ($morte ?? $maior),
            'coverages' => $coberturas,
            'beneficiaries' => array_map(fn (array $b) => [
                'name' => $b['name'],
                'percentage' => $b['percentage'],
                'relationship' => $b['relationship'],
            ], $cert['beneficiarios'] ?? []),
            'monthly_premium' => $anual ? bcdiv($premio, '12', 2) : $premio,
            'annual_premium' => $anual ? $premio : null,
            'payment_frequency' => $anual ? PaymentFrequency::Annual : PaymentFrequency::Monthly,
            'start_date' => CarbonImmutable::createFromFormat('d/m/Y', $cert['vigencia_apolice'])->toDateString(),
            'expiry_date' => ! empty($cert['vencimento']) ? CarbonImmutable::createFromFormat('d/m/Y', $cert['vencimento'])->toDateString() : null,
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
        // O vencimento só entra no relatório quando o certificado informa um (Icatu é vitalícia: sempre nulo).
        if ($novos['expiry_date'] !== null) {
            $campos[] = 'expiry_date';
        }
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

        $marca = Str::lower(Str::ascii($this->seguradoraCurta($cert)));

        $semNumero = $todas->filter(fn (InsurancePolicy $p) => blank($p->policy_number)
            && ! in_array($p->id, $this->reivindicadas, true)
            && $p->member_id === $membro->id
            && $p->insurance_type === InsuranceType::Vida
            && str_contains(Str::lower(Str::ascii($p->insurer_name)), $marca));

        if ($semNumero->count() !== 1) {
            return null;
        }

        $this->reivindicadas[] = $semNumero->first()->id;

        return $semNumero->first();
    }

    /**
     * @return list<array{perfil: FinancialProfile, membro: ProfileMember, tokens: list<string>, nascimento: ?string, cpf: ?string, email: ?string}>
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
                    'email' => $usuario?->email ? Str::lower($usuario->email) : null,
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

    /** 0 = não é a pessoa; 100 = e-mail ou CPF igual, ou nome e nascimento iguais. */
    private function nota(array $cert, array $candidato): int
    {
        // E-mail informado na carga (o PDF não traz; vem de quem conferiu): quem tem login com esse e-mail, e só ele.
        if (! empty($cert['email'])) {
            return $candidato['email'] === Str::lower(trim($cert['email'])) ? 100 : 0;
        }

        // E-mail que consta no PDF: se for o de alguém da carteira, é a pessoa. Se não for, não conta contra:
        // o cadastro pode ter outro e-mail, e o casamento segue por CPF ou nome.
        if (! empty($cert['email_pdf']) && $candidato['email'] === Str::lower(trim($cert['email_pdf']))) {
            return 100;
        }

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

        // O certificado corta o nome em 40 letras ("…DE OLIVEIR"): a última palavra cortada vale
        // como a palavra inteira do cadastro que começa com ela.
        if (mb_strlen(trim($cert['nome'])) >= 39 && strlen(end($a)) >= 4) {
            $corte = end($a);
            foreach ($b as $palavra) {
                if ($palavra !== $corte && str_starts_with($palavra, $corte)) {
                    $a[array_key_last($a)] = $palavra;
                    break;
                }
            }
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
        return PersonName::tokens($nome);
    }

    /** Nome gravado na apólice. */
    private function seguradora(array $cert): string
    {
        return $cert['seguradora'] ?? self::INSURER;
    }

    /** Nome curto, usado nos Documentos ("Apólice Azos 0569...") e para achar a apólice-casca da carga em planilha. */
    private function seguradoraCurta(array $cert): string
    {
        return $cert['seguradora_curta'] ?? 'Icatu';
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
