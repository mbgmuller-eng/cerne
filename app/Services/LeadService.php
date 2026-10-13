<?php

namespace App\Services;

use App\Enums\CommissionType;
use App\Enums\LeadActivityType;
use App\Enums\LeadKind;
use App\Enums\LeadLostReason;
use App\Enums\LeadQuoteStatus;
use App\Enums\LeadStage;
use App\Enums\PaymentFrequency;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAttachment;
use App\Models\LeadQuote;
use App\Models\User;
use App\Support\LifeCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lead é a etapa antes de qualquer coisa existir de verdade: sem perfil,
 * sem convite, às vezes só um nome e um telefone. Converter não duplica a
 * lógica de convite — reaproveita ClientInviteService::send(), que já
 * lida com token, e-mail e o vínculo consultor↔cliente.
 *
 * No funil de seguros o lead também guarda cotações (LeadQuote) e os arquivos delas (LeadAttachment).
 */
class LeadService
{
    public function __construct(
        private readonly ClientInviteService $invites,
    ) {}

    /**
     * @param  array<string, mixed>  $dados
     *
     * Estágio default explícito aqui em vez de confiar no DEFAULT da
     * coluna: o valor de volta de create() é o que o resto do código (e a
     * tela) usa na hora — um DEFAULT só de banco fica invisível no model
     * recém-criado até um refresh().
     */
    public function create(array $dados): Lead
    {
        $dados['stage'] ??= LeadStage::NewContact->value;
        $dados['kind'] ??= LeadKind::Consulting->value;

        return Lead::create($dados);
    }

    /** @param  array<string, mixed>  $dados */
    public function update(Lead $lead, array $dados): Lead
    {
        $lead->update($dados);

        return $lead;
    }

    public function logActivity(
        Lead $lead,
        LeadActivityType $type,
        ?string $description,
        CarbonImmutable $occurredAt,
        ?string $userId,
    ): LeadActivity {
        return LeadActivity::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'description' => $description,
            'occurred_at' => $occurredAt,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Anda uma etapa no funil do próprio lead (consultoria e seguros têm etapas diferentes). Nas pontas não faz
     * nada; lead encerrado não anda.
     *
     * @param  int  $passo  +1 avança, -1 volta
     */
    public function moveStage(Lead $lead, int $passo): Lead
    {
        $etapas = LeadStage::openFor($lead->kind);
        $indice = array_search($lead->stage, $etapas, true);

        if ($indice === false) {
            return $lead;
        }

        $novo = $etapas[$indice + $passo] ?? null;

        if ($novo !== null) {
            $lead->update(['stage' => $novo]);
        }

        return $lead;
    }

    public function markLost(Lead $lead, ?string $reason, ?LeadLostReason $category = null): Lead
    {
        $lead->update([
            'stage' => LeadStage::Lost,
            'lost_reason' => $reason,
            'lost_category' => $category,
            // É daqui que conta o prazo para apagar os anexos.
            'lost_at' => now(),
        ]);

        return $lead;
    }

    /**
     * Emite o convite de cliente de verdade e marca o lead como convertido.
     * A partir daqui quem manda é o fluxo de sempre (ConsultantInvite →
     * ClientOnboardingService::acceptInvite()) — o lead só guarda o
     * histórico de como o contato começou.
     *
     * @return string o link do convite, mesmo retorno de ClientInviteService::send()
     */
    public function convert(Lead $lead, User $consultant): string
    {
        if (blank($lead->email)) {
            throw new InvalidArgumentException('Lead sem e-mail não pode ser convertido — o convite precisa de um e-mail para enviar.');
        }

        $link = $this->invites->send($consultant, $lead->name, $lead->email);

        $lead->update(['stage' => LeadStage::Converted, 'converted_at' => now()]);

        return $link;
    }

    /**
     * A cotação aceita virou apólice emitida: o lead passa a "Emitida" (reabrindo, se estava perdido) e o fato entra
     * na linha do tempo dele. Idempotente: chamar de novo não repete a anotação.
     */
    public function markIssued(Lead $lead, ?LeadQuote $quote = null, ?string $userId = null): void
    {
        if ($lead->stage === LeadStage::Converted) {
            return;
        }

        $lead->update(['stage' => LeadStage::Converted, 'lost_category' => null, 'lost_reason' => null, 'lost_at' => null, 'converted_at' => now()]);

        $this->logActivity(
            $lead,
            LeadActivityType::Note,
            $quote !== null ? 'Apólice emitida a partir da cotação '.$quote->insurer_name.'.' : 'Negócio fechado.',
            CarbonImmutable::now(),
            $userId,
        );
    }

    /** Exclui o lead de vez, com os arquivos dele: o registro cai em cascata, o arquivo no disco não. */
    public function delete(Lead $lead): void
    {
        DB::transaction(function () use ($lead): void {
            $this->removeFiles($lead->attachments()->get());
            $lead->delete();
        });
    }

    // -----------------------------------------------------------------
    // Cotações
    // -----------------------------------------------------------------

    /**
     * Cria ou atualiza uma cotação do lead. Proteções de seguro de vida seguem o padrão do Cerne (mesma lista, mesmos
     * nomes e mesma ordem das apólices), como na importação de apólice.
     *
     * @param  array<string, mixed>  $dados
     */
    public function saveQuote(Lead $lead, array $dados, ?LeadQuote $quote = null, ?string $userId = null): LeadQuote
    {
        $coberturas = collect($dados['coverages'] ?? [])
            ->filter(fn (array $c) => trim((string) ($c['name'] ?? '')) !== '')
            ->map(fn (array $c) => [
                'name' => trim($c['name']),
                'value' => ($c['value'] ?? '') !== '' && $c['value'] !== null ? (string) $c['value'] : null,
                'deductible' => trim((string) ($c['deductible'] ?? '')) !== '' ? trim($c['deductible']) : null,
            ])->values()->all();

        if (($dados['insurance_type'] ?? null) === 'vida') {
            $coberturas = LifeCoverage::standardize($coberturas);
        }

        $tipoComissao = CommissionType::tryFrom((string) ($dados['commission_type'] ?? '')) ?? CommissionType::Percent;

        $campos = [
            'insurance_type' => $dados['insurance_type'],
            'insurer_name' => trim($dados['insurer_name']),
            'product' => $this->blankToNull($dados['product'] ?? null),
            'reference' => $this->blankToNull($dados['reference'] ?? null),
            'premium' => $this->blankToNull($dados['premium'] ?? null),
            'payment_frequency' => $dados['payment_frequency'] ?? PaymentFrequency::Monthly->value,
            'coverage_amount' => $this->blankToNull($dados['coverage_amount'] ?? null)
                ?? ($dados['insurance_type'] === 'vida' ? LifeCoverage::deathCapital($coberturas, null) : null),
            'coverages' => $coberturas === [] ? null : $coberturas,
            // Só o campo do tipo escolhido fica gravado: trocar de percentual para valor fixo não deixa o outro para trás.
            'commission_type' => $tipoComissao->value,
            'commission_percent' => $tipoComissao === CommissionType::Percent ? $this->blankToNull($dados['commission_percent'] ?? null) : null,
            'commission_amount' => $tipoComissao === CommissionType::Amount ? $this->blankToNull($dados['commission_amount'] ?? null) : null,
            'valid_until' => $this->blankToNull($dados['valid_until'] ?? null),
            'status' => $dados['status'] ?? LeadQuoteStatus::Draft->value,
            'notes' => $this->blankToNull($dados['notes'] ?? null),
        ];

        if ($quote === null) {
            return $lead->quotes()->create($campos + ['created_by_user_id' => $userId]);
        }

        $quote->update($campos);

        return $quote;
    }

    public function setQuoteStatus(LeadQuote $quote, LeadQuoteStatus $status): LeadQuote
    {
        $quote->update(['status' => $status]);

        return $quote;
    }

    /** Exclui a cotação e as simulações anexadas a ela (arquivo e registro). */
    public function deleteQuote(LeadQuote $quote): void
    {
        DB::transaction(function () use ($quote): void {
            $this->removeFiles($quote->attachments()->get());
            $quote->delete();
        });
    }

    // -----------------------------------------------------------------
    // Anexos
    // -----------------------------------------------------------------

    /**
     * Guarda um arquivo no disco privado e o liga ao lead (ou a uma cotação dele). O nome no disco é gerado: o que a pessoa
     * deu ao arquivo (com CPF, nome do cliente) só fica no registro.
     *
     * @throws InvalidArgumentException passou do limite de anexos do lead
     */
    public function attachFile(Lead $lead, UploadedFile $arquivo, ?LeadQuote $quote = null, ?string $userId = null): LeadAttachment
    {
        if ($lead->attachments()->count() >= (int) config('cerne.lead_files.max_per_lead')) {
            throw new InvalidArgumentException('Este contato já tem o máximo de arquivos anexados.');
        }

        if ($quote !== null && $quote->lead_id !== $lead->id) {
            throw new InvalidArgumentException('A cotação não é deste contato.');
        }

        $disco = Storage::disk(config('cerne.lead_files.disk'));
        $extensao = Str::lower($arquivo->getClientOriginalExtension() ?: $arquivo->guessExtension() ?: 'bin');
        $caminho = trim(config('cerne.lead_files.path'), '/').'/'.$lead->consultant_id.'/'.$lead->id.'/'.Str::uuid().'.'.$extensao;

        $disco->put($caminho, file_get_contents($arquivo->getRealPath()));

        return LeadAttachment::create([
            'lead_id' => $lead->id,
            'lead_quote_id' => $quote?->id,
            'original_filename' => Str::limit($arquivo->getClientOriginalName(), 240, ''),
            'storage_path' => $caminho,
            'mime_type' => $arquivo->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => (int) $arquivo->getSize(),
            'uploaded_by_user_id' => $userId,
        ]);
    }

    public function deleteAttachment(LeadAttachment $anexo): void
    {
        $this->removeFiles(collect([$anexo]));
    }

    /**
     * Apaga os anexos de leads perdidos há mais de `retention_months` meses (arquivo e registro). Idempotente: o que já
     * foi apagado não aparece de novo. O lead e as cotações continuam (só os arquivos saem).
     *
     * @return int quantos anexos foram apagados
     */
    public function purgeExpiredAttachments(?CarbonImmutable $hoje = null): int
    {
        $limite = ($hoje ?? CarbonImmutable::now())->subMonths((int) config('cerne.lead_files.retention_months'));

        $anexos = LeadAttachment::query()
            ->whereIn('lead_id', Lead::query()
                ->where('stage', LeadStage::Lost->value)
                ->whereNotNull('lost_at')
                ->where('lost_at', '<=', $limite)
                ->select('id'))
            ->get();

        $this->removeFiles($anexos);

        return $anexos->count();
    }

    /**
     * Quando os anexos de um lead perdido saem do servidor. Nulo para lead que não foi perdido ou sem anexos.
     */
    public function attachmentsExpireOn(Lead $lead): ?CarbonImmutable
    {
        if ($lead->stage !== LeadStage::Lost || $lead->lost_at === null) {
            return null;
        }

        return CarbonImmutable::instance($lead->lost_at)->addMonths((int) config('cerne.lead_files.retention_months'))->startOfDay();
    }

    /**
     * Valor do funil de seguros: soma, por lead em andamento, o prêmio anual e a comissão da cotação que o representa
     * (ver Lead::estimatedQuote()). Lead sem cotação com prêmio entra só na contagem de "sem cotação".
     *
     * @param  iterable<Lead>  $leads  em andamento, com `quotes` carregadas
     * @return array{premio_anual: string, comissao: string, com_cotacao: int, sem_cotacao: int}
     */
    public function funnelValue(iterable $leads, ?CarbonImmutable $hoje = null): array
    {
        $premio = $comissao = '0.00';
        $com = $sem = 0;

        foreach ($leads as $lead) {
            $quote = $lead->estimatedQuote($hoje);

            if ($quote === null) {
                $sem++;

                continue;
            }

            $com++;
            $premio = bcadd($premio, (string) $quote->annualPremium(), 2);
            $comissao = bcadd($comissao, (string) ($quote->expectedCommission() ?? '0.00'), 2);
        }

        return ['premio_anual' => $premio, 'comissao' => $comissao, 'com_cotacao' => $com, 'sem_cotacao' => $sem];
    }

    /** @param  iterable<LeadAttachment>  $anexos */
    private function removeFiles(iterable $anexos): void
    {
        $disco = Storage::disk(config('cerne.lead_files.disk'));

        foreach ($anexos as $anexo) {
            $disco->delete($anexo->storage_path);
            $anexo->delete();
        }
    }

    private function blankToNull(mixed $valor): mixed
    {
        return $valor === null || (is_string($valor) && trim($valor) === '') ? null : (is_string($valor) ? trim($valor) : $valor);
    }
}
