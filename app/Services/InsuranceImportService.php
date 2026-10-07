<?php

namespace App\Services;

use App\Enums\DocumentCategory;
use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\ProcessingStatus;
use App\Models\Document;
use App\Models\DocumentUpload;
use App\Models\InsurancePolicy;
use App\Models\Insurer;
use App\Models\ProfileMember;
use App\Support\PersonName;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Importação de apólice por PDF: transforma o que a IA leu (DocumentUpload.extraction_summary) em linhas
 * editáveis para a revisão e, depois que a pessoa confere, grava a apólice e guarda o PDF em Documentos.
 *
 * Nada aqui roda sem revisão: `rowsFor()` só prepara a tela e `commit()` só recebe o que a pessoa confirmou.
 */
class InsuranceImportService
{
    /** Nome do campo na resposta da IA → como aparece para a pessoa. */
    private const ROTULOS = [
        'tipo' => 'tipo de seguro',
        'seguradora' => 'seguradora',
        'numero_apolice' => 'número da apólice',
        'segurado' => 'pessoa segurada',
        'objeto_segurado' => 'item segurado',
        'valor_segurado' => 'valor segurado',
        'coberturas' => 'proteções',
        'premio' => 'custo',
        'periodicidade' => 'forma de pagamento',
        'premio_total_anual' => 'prêmio anual',
        'inicio_vigencia' => 'início de vigência',
        'fim_vigencia' => 'vencimento',
        'beneficiarios' => 'beneficiários',
        'observacoes_item' => 'observações',
    ];

    public function __construct(private DocumentService $documentos) {}

    /**
     * Uma linha por apólice lida que ainda não teve destino, indexada pela posição na extração.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowsFor(DocumentUpload $documento): array
    {
        $membros = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->get();
        $existentes = InsurancePolicy::query()->get();
        $resolvidos = $documento->resolvedItemIndices();
        $linhas = [];

        foreach ($documento->extractedItems() as $i => $item) {
            if (in_array($i, $resolvidos, true)) {
                continue;
            }

            $segurado = trim((string) ($item['segurado'] ?? ''));
            $mesmaPessoa = $segurado === '' ? collect() : $membros->filter(fn (ProfileMember $m) => PersonName::sameAs($m->name, $segurado));
            $membro = $mesmaPessoa->count() === 1 ? $mesmaPessoa->first() : null;
            $existente = $this->apolicePorNumero($existentes, $item['numero_apolice'] ?? null);

            $linhas[$i] = [
                'import' => true,
                'target' => $existente?->id ?? 'new',
                'tipo' => in_array($item['tipo'] ?? null, array_column(InsuranceType::cases(), 'value'), true) ? $item['tipo'] : InsuranceType::Outro->value,
                'seguradora' => trim((string) ($item['seguradora'] ?? '')),
                'numero' => trim((string) ($item['numero_apolice'] ?? '')),
                'member_id' => $membro?->id ?? '',
                'pessoa' => $membro === null ? $segurado : '',
                'objeto' => trim((string) ($item['objeto_segurado'] ?? '')),
                'valor_segurado' => $this->numero($item['valor_segurado'] ?? null),
                'premio' => $this->numero($item['premio'] ?? null),
                'periodicidade' => in_array($item['periodicidade'] ?? null, array_column(PaymentFrequency::cases(), 'value'), true) ? $item['periodicidade'] : PaymentFrequency::Monthly->value,
                'premio_anual' => $this->numero($item['premio_total_anual'] ?? null),
                'inicio' => $this->data($item['inicio_vigencia'] ?? null),
                'fim' => $this->data($item['fim_vigencia'] ?? null),
                'notas' => trim((string) ($item['observacoes_item'] ?? '')),
                'privado' => false,
                'coberturas' => array_values(array_map(fn (array $c) => [
                    'nome' => (string) ($c['nome'] ?? ''),
                    'valor' => $this->numero($c['valor'] ?? null),
                    'franquia' => trim((string) ($c['franquia'] ?? '')),
                ], $item['coberturas'] ?? [])),
                'beneficiarios' => array_values(array_map(fn (array $b) => [
                    'nome' => (string) ($b['nome'] ?? ''),
                    'percentual' => (string) ($b['percentual'] ?? ''),
                    'parentesco' => trim((string) ($b['parentesco'] ?? '')),
                ], $item['beneficiarios'] ?? [])),
                'incertos' => array_values(array_unique(array_filter(array_map(
                    fn ($campo) => self::ROTULOS[$campo] ?? null,
                    $item['campos_incertos'] ?? [],
                )))),
            ];
        }

        return $linhas;
    }

    /**
     * Grava o que foi confirmado. Linhas com `import` falso são descartadas de vez (não voltam à revisão).
     * Tudo numa transação: metade de uma importação é pior que nenhuma.
     *
     * @param  array<int, array<string, mixed>>  $linhas  já validadas pela tela
     * @return array{criadas: int, atualizadas: int}
     */
    public function commit(DocumentUpload $documento, array $linhas, ?ProfileMember $autor): array
    {
        $resultado = DB::transaction(function () use ($documento, $linhas, $autor): array {
            $criadas = $atualizadas = 0;
            $importados = $documento->imported_item_indices ?? [];
            $excluidos = $documento->excluded_item_indices ?? [];

            foreach ($linhas as $i => $linha) {
                if (! $linha['import']) {
                    $excluidos[] = (int) $i;

                    continue;
                }

                [$apolice, $nova] = $this->gravar($linha);
                $nova ? $criadas++ : $atualizadas++;
                $this->anexarPdf($documento, $apolice, $autor);
                $importados[] = (int) $i;
            }

            $importados = array_values(array_unique($importados));
            $excluidos = array_values(array_unique($excluidos));
            $finalizado = count(array_unique(array_merge($importados, $excluidos))) >= count($documento->extractedItems());

            $documento->update([
                'imported_item_indices' => $importados,
                'excluded_item_indices' => $excluidos,
                'records_extracted' => count($importados),
                'processing_status' => $finalizado ? ProcessingStatus::Committed : ProcessingStatus::Completed,
                'committed_at' => $finalizado ? now() : null,
            ]);

            return ['criadas' => $criadas, 'atualizadas' => $atualizadas, 'finalizado' => $finalizado];
        });

        // Já está em Documentos: a cópia da pasta de importação não serve mais. Fora da transação porque
        // apagar arquivo não dá para desfazer.
        if ($resultado['finalizado']) {
            $documento->deleteFile();
        }

        return ['criadas' => $resultado['criadas'], 'atualizadas' => $resultado['atualizadas']];
    }

    /** @return array{0: InsurancePolicy, 1: bool} a apólice e se foi criada agora */
    private function gravar(array $linha): array
    {
        // Nome novo vira sugestão para o admin aprovar; nunca bloqueia (ver Insurer).
        Insurer::resolveOrSuggest(trim($linha['seguradora']));

        $coberturas = collect($linha['coberturas'] ?? [])
            ->filter(fn (array $c) => trim((string) $c['nome']) !== '')
            ->map(fn (array $c) => [
                'name' => trim($c['nome']),
                'value' => ($c['valor'] ?? '') !== '' ? (string) $c['valor'] : null,
                'deductible' => trim((string) ($c['franquia'] ?? '')) !== '' ? trim($c['franquia']) : null,
            ])->values()->all();

        $beneficiarios = collect($linha['beneficiarios'] ?? [])
            ->filter(fn (array $b) => trim((string) $b['nome']) !== '')
            ->map(fn (array $b) => [
                'name' => trim($b['nome']),
                'percentage' => (float) $b['percentual'],
                'relationship' => trim((string) ($b['parentesco'] ?? '')) !== '' ? trim($b['parentesco']) : null,
            ])->values()->all();

        $dados = [
            'insurance_type' => $linha['tipo'],
            'insurer_name' => trim($linha['seguradora']),
            'policy_number' => trim((string) $linha['numero']) !== '' ? trim($linha['numero']) : null,
            'insured_item' => trim((string) $linha['objeto']) !== '' ? trim($linha['objeto']) : null,
            'coverage_amount' => ($linha['valor_segurado'] ?? '') !== '' ? $linha['valor_segurado'] : null,
            'monthly_premium' => $linha['premio'],
            'annual_premium' => ($linha['premio_anual'] ?? '') !== '' ? $linha['premio_anual'] : null,
            'payment_frequency' => $linha['periodicidade'],
            'start_date' => $linha['inicio'],
            'expiry_date' => ($linha['fim'] ?? '') !== '' ? $linha['fim'] : null,
        ];

        if ($linha['target'] === 'new') {
            $membroId = ($linha['member_id'] ?? '') !== '' ? $linha['member_id'] : null;

            $apolice = InsurancePolicy::create($dados + [
                'member_id' => $membroId,
                'insured_person_name' => $membroId === null && trim((string) $linha['pessoa']) !== '' ? trim($linha['pessoa']) : null,
                // Corretor importando já compartilha consigo (mesma regra do formulário de apólice).
                'broker_id' => auth()->user()->isBroker() ? auth()->id() : null,
                'coverages' => $coberturas,
                'beneficiaries' => $beneficiarios,
                'notes' => trim((string) $linha['notas']) !== '' ? trim($linha['notas']) : null,
                'is_private' => (bool) $linha['privado'],
                'is_active' => true,
                'created_by_user_id' => auth()->id(),
            ]);

            return [$apolice, true];
        }

        $apolice = InsurancePolicy::query()->findOrFail($linha['target']);

        // Renovação: o que veio no PDF vale, mas listas vazias e anotações da pessoa não são apagadas.
        $apolice->update($dados
            + ($coberturas !== [] ? ['coverages' => $coberturas] : [])
            + ($beneficiarios !== [] ? ['beneficiaries' => $beneficiarios] : [])
            + (blank($apolice->notes) && trim((string) $linha['notas']) !== '' ? ['notes' => trim($linha['notas'])] : []));

        return [$apolice, false];
    }

    /** O PDF vai para Documentos ligado à apólice; o mesmo PDF já anexado a ela não é guardado de novo. */
    private function anexarPdf(DocumentUpload $documento, InsurancePolicy $apolice, ?ProfileMember $autor): void
    {
        $disco = config('cerne.documents.disk');

        if (! Storage::disk($disco)->exists($documento->storage_path)) {
            return;
        }

        $jaAnexado = Document::query()
            ->where('insurance_policy_id', $apolice->id)
            ->where('original_filename', $documento->original_filename)
            ->where('size_bytes', $documento->size_bytes)
            ->exists();

        if ($jaAnexado) {
            return;
        }

        $this->documentos->adopt($disco, $documento->storage_path, $documento->original_filename, [
            'category' => DocumentCategory::InsurancePolicy->value,
            'title' => trim('Apólice '.$apolice->insurer_name.($apolice->policy_number ? ' '.$apolice->policy_number : '')),
            'member_id' => $apolice->member_id,
            'insurance_policy_id' => $apolice->id,
        ], $autor);
    }

    private function apolicePorNumero($existentes, ?string $numero): ?InsurancePolicy
    {
        $alvo = preg_replace('/\D/', '', (string) $numero);

        if ($alvo === '') {
            return null;
        }

        return $existentes->first(fn (InsurancePolicy $p) => preg_replace('/\D/', '', (string) $p->policy_number) === $alvo);
    }

    private function numero(mixed $valor): string
    {
        return is_numeric($valor) ? (string) $valor : '';
    }

    private function data(mixed $valor): string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($valor)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
