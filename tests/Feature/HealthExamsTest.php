<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\HealthAppointmentKind;
use App\Enums\HealthAppointmentStatus;
use App\Enums\HealthExamItemStatus;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Jobs\ReadExamReportJob;
use App\Livewire\Health\Exams\HealthExamBuilder;
use App\Livewire\Health\Exams\HealthExamIndex;
use App\Livewire\Health\Exams\HealthExamResultForm;
use App\Livewire\Health\Exams\HealthExamResults;
use App\Livewire\Health\Exams\HealthExamShow;
use App\Livewire\Health\Exams\HealthExamUpload;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\HealthExamItem;
use App\Models\HealthExamReading;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Models\HealthExamValue;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthExamReadingFinished;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Services\HealthExamReadingService;
use App\Services\HealthExamResultService;
use App\Services\HealthExamService;
use App\Services\HealthFollowupService;
use App\Services\HealthRoutineService;
use App\Services\Privacy\AccountDeletionService;
use App\Services\Privacy\DataRetentionService;
use App\Support\Health\ExamCatalog;
use App\Support\Health\ReferenceRange;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cerne Saúde: pedido de exames com lista (datas separadas), resultados comparáveis e leitura do laudo em PDF por IA (aqui, simulada).
 * Visibilidade igual à da Agenda de Saúde.
 */
class HealthExamsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $joao;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroJoao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00'));
        config(['cerne.care.enabled' => true, 'cerne.ai.api_key' => 'chave-de-teste']);
        Storage::fake(config('cerne.documents.disk'));
        Storage::fake(config('cerne.document_vault.disk'));
        Queue::fake();

        $this->ana = User::factory()->create(['name' => 'Ana Teste']);
        $this->joao = User::factory()->create(['name' => 'João Teste']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'name' => 'Ana Teste', 'role' => MemberRole::Primary]);
        $this->membroJoao = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->joao->id, 'name' => 'João Teste', 'role' => MemberRole::Secondary]);

        Subscription::create([
            'user_id' => $this->ana->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->entrarComoAna();
    }

    // --- Referência e catálogo -----------------------------------------------------------------

    public function test_destaque_so_quando_a_referencia_e_uma_faixa_simples(): void
    {
        foreach ([
            ['142', 'menor que 130', 'above'], ['130', 'menor que 130', 'above'], ['130', 'até 130', ''], ['118', '< 130 mg/dL', ''],
            ['38', 'maior que 40', 'below'], ['52', '> 40', ''], ['40', '≥ 40', ''],
            ['5,10', '0,40 a 4,50', 'above'], ['0,10', '0,40 - 4,50 µUI/mL', 'below'], ['95', '70 a 99', ''], ['13,8', 'de 12,0 a 16,0 g/dL', ''],
            ['250.000', '150.000 a 450.000 /mm3', ''], ['100.000', '150.000 a 450.000', 'below'], ['5.1', '4 a 5', 'above'],
            ['196', 'Desejável: menor que 190', 'above'], ['1.015', '1.005 a 1.030', ''],
            // Nada de destaque quando não dá para saber.
            ['142', 'Ótimo: < 100; Limítrofe: 130 a 159', ''], ['15', '12 a 16 anos: 11 a 14', ''], ['Reagente', 'Não reagente', ''],
            ['< 0,01', '0,40 a 4,50', ''], ['142', '', ''], ['142', 'ver laudo', ''],
        ] as [$valor, $referencia, $esperado]) {
            $this->assertSame($esperado, ReferenceRange::flag($valor, $referencia), "{$valor} em [{$referencia}]");
        }
    }

    public function test_nomes_diferentes_do_mesmo_marcador_se_juntam_e_o_nao_hdl_nao_vira_hdl(): void
    {
        $this->assertSame(ExamCatalog::markerKey('LDL'), ExamCatalog::markerKey('Colesterol LDL'));
        $this->assertSame(ExamCatalog::markerKey('Glicose em jejum'), ExamCatalog::markerKey('GLICEMIA DE JEJUM'));
        $this->assertSame('Colesterol não HDL', ExamCatalog::markerFor('Colesterol não-HDL')['name']);
        $this->assertSame('HDL', ExamCatalog::markerFor('HDL - Colesterol')['name'] ?? null);
        $this->assertNull(ExamCatalog::markerFor('Marcador desconhecido'));

        $this->assertSame('Ecodopplercardiograma transtorácico', ExamCatalog::examFor('ECOCARDIOGRAMA'));
        $this->assertNull(ExamCatalog::examFor('tireoide'), 'apelido que serve a vários exames não decide');
        $this->assertSame('Teste ergométrico computadorizado', ExamCatalog::search('blood', 'esteira')[0]['name']);
    }

    // --- Pedido e datas ------------------------------------------------------------------------------

    public function test_pedido_com_lista_tira_repetidos_e_lembra_os_digitados_e_o_anterior(): void
    {
        $servico = app(HealthExamService::class);
        $pedido = $this->pedido(['Hemograma completo', 'hemograma completo ', 'Dosagem especial', 'TSH']);

        $this->assertSame(['Hemograma completo', 'Dosagem especial', 'TSH'], $pedido->items->pluck('name')->all());
        $this->assertSame(HealthAppointmentKind::Exam, $pedido->kind);
        $this->assertSame(['Dosagem especial'], $servico->customNames($this->membroAna->id));
        $this->assertSame($pedido->id, $servico->previousOrder($this->membroAna->id, 'blood')->id);
        $this->assertNull($servico->previousOrder($this->membroAna->id, 'cardio'));
        $this->assertNull($servico->previousOrder($this->membroJoao->id, 'blood'));

        foreach ([['title' => 'X', 'items' => []], ['title' => ' ', 'items' => [['name' => 'TSH']]]] as $ruim) {
            try {
                $servico->createOrder($this->membroAna, $ruim, null, null, null);
                $this->fail('deveria recusar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $rotinaDoJoao = app(HealthRoutineService::class)->create($this->membroJoao, ['name' => 'Dele', 'interval_months' => 12], null);
        $this->expectException(InvalidArgumentException::class);
        $servico->createOrder($this->membroAna, ['title' => 'X', 'items' => [['name' => 'TSH']]], $rotinaDoJoao, null, null);
    }

    public function test_marca_juntos_so_os_escolhidos_e_cancelar_devolve_para_falta_agendar(): void
    {
        $rotina = app(HealthRoutineService::class)->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12], null);
        $acompanhamento = app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Coração', 'status' => 'investigating', 'started_on' => '2026-11-01'], null);
        $servico = app(HealthExamService::class);
        $pedido = $servico->createOrder($this->membroAna, ['title' => 'Avaliação cardiológica', 'category' => 'cardio', 'notes' => 'Levar exames antigos', 'items' => [
            ['name' => 'Ecodopplercardiograma transtorácico'], ['name' => 'Teste ergométrico computadorizado', 'detail' => 'Inclui ECG basal'], ['name' => 'Doppler de carótidas e vertebrais'],
        ]], $rotina, $acompanhamento, null);
        [$eco, $esteira, $doppler] = $pedido->items()->get()->all();

        $consulta = $servico->schedule($pedido, [$eco->id, $esteira->id], '2026-12-03 08:00', 'Clínica Exemplo', null);

        $this->assertSame('Avaliação cardiológica', $consulta->title);
        $this->assertSame($rotina->id, $consulta->fresh()->routine_id);
        $this->assertTrue($acompanhamento->appointments()->whereKey($consulta->id)->exists());
        $this->assertStringContainsString('Teste ergométrico computadorizado (Inclui ECG basal)', $consulta->notes);
        $this->assertStringContainsString('Levar exames antigos', $consulta->notes);
        $this->assertSame([HealthExamItemStatus::Scheduled, HealthExamItemStatus::Scheduled, HealthExamItemStatus::Pending], $this->situacoes($pedido));
        $this->assertSame(1, HealthExamRequest::query()->open()->count());

        $sozinho = $servico->schedule($pedido, [$doppler->id], '2026-12-10 10:00', null, null);
        $this->assertSame('Doppler de carótidas e vertebrais', $sozinho->title);
        $this->assertSame(0, HealthExamRequest::query()->open()->count());

        app(HealthAppointmentService::class)->setStatus($consulta->fresh(), HealthAppointmentStatus::Cancelled, $this->ana);
        $this->assertSame([HealthExamItemStatus::Pending, HealthExamItemStatus::Pending, HealthExamItemStatus::Scheduled], $this->situacoes($pedido));
        $this->assertSame(1, HealthExamRequest::query()->open()->count());

        // Só dá para confirmar que foi realizado depois da data.
        $this->travelTo(CarbonImmutable::parse('2026-12-11 09:00'));
        app(HealthAppointmentService::class)->setStatus($sozinho->fresh(), HealthAppointmentStatus::Done, $this->ana);
        $this->assertSame(HealthExamItemStatus::Done, $this->situacoes($pedido)[2]);

        // Apagar o compromisso também devolve.
        $sozinho->fresh()->delete();
        $this->assertSame(HealthExamItemStatus::Pending, $this->situacoes($pedido)[2]);

        $this->expectException(InvalidArgumentException::class);
        $servico->schedule($pedido, [$this->pedido(['TSH'])->items()->value('id')], '2026-12-11 10:00', null, null);
    }

    public function test_pedido_antigo_de_um_exame_so_vira_pedido_com_um_item(): void
    {
        $consulta = app(HealthAppointmentService::class)->create($this->membroAna, ['kind' => HealthAppointmentKind::Exam, 'title' => 'Ressonância', 'scheduled_at' => '2026-12-01 10:00'], null);
        $id = (string) Str::orderedUuid();
        DB::table('health_exam_requests')->insert([
            'id' => $id, 'profile_id' => $this->perfil->id, 'member_id' => $this->membroAna->id, 'kind' => 'exam', 'title' => Crypt::encryptString('Ressonância'),
            'appointment_id' => $consulta->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migracao = require database_path('migrations/2026_12_11_100000_create_health_exam_orders_tables.php');
        $migracao->backfill();
        $migracao->backfill();

        $item = HealthExamItem::query()->where('request_id', $id)->sole();
        $this->assertSame('Ressonância', $item->name);
        $this->assertSame($consulta->id, $item->appointment_id);
        $this->assertSame(HealthExamItemStatus::Scheduled, $item->status());
    }

    // --- Resultados e comparação ----------------------------------------------------------------------

    public function test_comparacao_junta_por_marcador_destaca_pela_referencia_de_cada_laudo_e_separa_unidade_diferente(): void
    {
        $servico = app(HealthExamResultService::class);
        $this->laudo('2024-11-10', [['LDL', '151', 'mg/dL', 'menor que 160'], ['Vitamina D', '55', 'nmol/L', '50 a 125'], ['Urina cor', 'Amarela', '', '']]);
        $this->laudo('2025-11-10', [['Colesterol LDL', '142', 'mg/dL', 'menor que 130'], ['25 hidroxivitamina D', '24', 'ng/mL', '30 a 60']]);

        $comparacao = $servico->comparison($this->membroAna->id);

        $this->assertSame(['2024-11-10', '2025-11-10'], $comparacao['columns']);
        $ldl = $comparacao['groups']['Perfil lipídico'][0];
        $this->assertSame('LDL', $ldl['name']);
        $this->assertSame('menor que 130', $ldl['reference']);
        $this->assertSame(['', 'above'], array_column(array_values($ldl['cells']), 'flag'), 'cada valor contra a referência do próprio laudo');
        $this->assertArrayHasKey('Outros resultados', $comparacao['groups']);

        $d = $comparacao['groups']['Vitamina D (25-hidroxi)'][0];
        $evolucao = $servico->trend($d);
        $this->assertSame([24.0], array_column($evolucao['points'], 'value'));
        $this->assertSame('unidade diferente', $evolucao['excluded'][0]['reason']);
        $this->assertSame([30.0, 60.0], [$evolucao['min'], $evolucao['max']]);

        $this->assertSame([], $servico->comparison($this->membroJoao->id)['columns']);
    }

    public function test_resultado_resolve_os_exames_escolhidos_e_excluir_os_devolve(): void
    {
        $servico = app(HealthExamResultService::class);
        $pedido = $this->pedido(['TSH', 'T4 livre']);
        $tsh = $pedido->items()->get()->first();

        $laudo = $servico->save($this->membroAna, $pedido, ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'TSH', 'value' => '2,1'], ['name' => '', 'value' => '9']]], [$tsh->id], null);

        $this->assertSame(1, $laudo->values()->count(), 'linha sem nome não entra');
        $this->assertSame([HealthExamItemStatus::Result, HealthExamItemStatus::Pending], $this->situacoes($pedido));

        try {
            app(HealthExamService::class)->removeItem($tsh->fresh());
            $this->fail('exame com resultado não sai');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            ['kind' => 'values', 'performed_on' => '2026-12-30', 'values' => [['name' => 'TSH', 'value' => '1']]],
            ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'TSH', 'value' => '']]],
            ['kind' => 'report', 'performed_on' => '2026-11-20', 'exam_name' => 'Eco', 'conclusion' => ' '],
        ] as $ruim) {
            try {
                $servico->save($this->membroAna, $pedido, $ruim, [], null);
                $this->fail('deveria recusar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // Item de outro pedido (id vindo do navegador) é recusado e nada é gravado.
        $alheio = $this->pedido(['Ureia'])->items()->value('id');
        try {
            $servico->save($this->membroAna, $pedido, ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'TSH', 'value' => '1']]], [$alheio], null);
            $this->fail('item alheio');
        } catch (InvalidArgumentException) {
            $this->assertSame(1, HealthExamReport::query()->count());
        }

        $servico->delete($laudo);
        $this->assertSame(0, HealthExamValue::query()->count());
        $this->assertSame([HealthExamItemStatus::Pending, HealthExamItemStatus::Pending], $this->situacoes($pedido));
    }

    // --- Leitura por IA ---------------------------------------------------------------------------------

    public function test_leitura_nao_grava_nada_antes_da_revisao_e_salvar_guarda_o_pdf_nos_documentos(): void
    {
        Notification::fake();
        $this->simularLeitura($this->respostaDeSangue());
        $acompanhamento = app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Cansaço', 'status' => 'investigating', 'started_on' => '2026-11-01'], null);
        $pedido = app(HealthExamService::class)->createOrder($this->membroAna, ['title' => 'Exames de sangue', 'category' => 'blood', 'items' => [
            ['name' => 'Perfil lipídico'], ['name' => 'TSH'], ['name' => 'T4 livre'],
        ]], null, $acompanhamento, null);

        Livewire::test(HealthExamUpload::class, ['pedidoId' => $pedido->id])
            ->set('open', true)->assertSee('enviado à Anthropic')
            ->set('arquivo', UploadedFile::fake()->create('laudo.pdf', 30, 'application/pdf'))
            ->call('send')->assertHasNoErrors();

        $leitura = HealthExamReading::query()->sole();
        Queue::assertPushed(ReadExamReportJob::class, fn ($job) => $job->readingId === $leitura->id);
        Storage::disk(config('cerne.documents.disk'))->assertExists($leitura->source_path);

        $this->lerNaFila($leitura);

        $leitura = $leitura->fresh();
        $this->assertSame(ProcessingStatus::Completed, $leitura->status);
        $this->assertStringNotContainsString('LDL', (string) DB::table('health_exam_readings')->value('summary'));
        $this->assertSame(0, HealthExamReport::query()->count(), 'nada vira resultado antes da revisão');
        $this->assertSame(0, Document::query()->count());
        Notification::assertSentTo($this->ana, HealthExamReadingFinished::class, fn ($n) => $n->success);

        $tela = Livewire::test(HealthExamResultForm::class, ['leitura' => $leitura->id])
            ->assertSet('performedOn', '2026-11-20')
            ->assertSet('values.0.name', 'LDL')
            ->assertSee('▲ acima da referência')
            ->assertSee('Confira:');

        $marcados = HealthExamItem::query()->whereIn('id', $tela->get('itemIds'))->get()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Perfil lipídico', 'TSH'], $marcados, 'o T4 não veio no laudo');

        // A pessoa corrige um valor e desmarca o que não quer guardar.
        $tela->set('values.0.value', '128')->set('values.2.on', false)->call('save')->assertHasNoErrors()
            ->assertRedirect(route('health.exams.show', $pedido->id));

        $laudo = HealthExamReport::query()->sole();
        $this->assertSame(['LDL', 'TSH'], $laudo->values->pluck('name')->all());
        $this->assertSame('128', $laudo->values->first()->value);
        $this->assertSame([HealthExamItemStatus::Result, HealthExamItemStatus::Result, HealthExamItemStatus::Pending], $this->situacoes($pedido));

        $documento = Document::query()->sole();
        $this->assertSame($documento->id, $laudo->document_id);
        $this->assertSame('health_exam', $documento->category->value);
        $this->assertSame($this->membroAna->id, $documento->member_id);
        $this->assertSame($acompanhamento->id, $documento->health_followup_id);
        $this->assertFalse((bool) $documento->visible_to_professional);
        Storage::disk(config('cerne.document_vault.disk'))->assertExists($documento->storage_path);

        $leitura = $leitura->fresh();
        $this->assertSame([ProcessingStatus::Committed, '', null], [$leitura->status, $leitura->source_path, $leitura->summary]);
        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')), 'o PDF sai da pasta de importação');

        // Salvar de novo não cria um segundo laudo.
        $this->expectException(InvalidArgumentException::class);
        app(HealthExamReadingService::class)->confirm($leitura, ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'TSH', 'value' => '1']]], [], null);
    }

    public function test_laudo_de_conclusao_e_historico_por_exame(): void
    {
        app(HealthExamResultService::class)->save($this->membroAna, null, ['kind' => 'report', 'performed_on' => '2025-11-14', 'exam_name' => 'Ecocardiograma', 'conclusion' => 'Primeiro.'], [], null);
        $this->simularLeitura(['tipo' => 'laudo', 'data' => '2026-11-20', 'exame' => 'Ecodopplercardiograma transtorácico', 'conclusao' => 'Segundo, sem mudança.', 'destaque' => 'Fração de ejeção: 63%', 'valores' => [], 'local' => 'Clínica', 'medico' => '', 'observacoes' => '']);
        $pedido = $this->pedido(['Ecodopplercardiograma transtorácico', 'Teste ergométrico computadorizado'], 'cardio');
        $leitura = $this->enviar($pedido);

        Livewire::test(HealthExamResultForm::class, ['leitura' => $leitura->id])
            ->assertSet('kind', 'report')->assertCount('itemIds', 1)
            ->call('save')->assertHasNoErrors();

        $historico = app(HealthExamResultService::class)->textHistory($this->membroAna->id);
        $this->assertCount(1, $historico, 'nomes diferentes do mesmo exame ficam juntos');
        $this->assertSame(['Primeiro.', 'Segundo, sem mudança.'], array_values($historico)[0]['reports']->pluck('conclusion')->all());

        Livewire::test(HealthExamResults::class)->set('view', 'laudos')->assertSee('Segundo, sem mudança.')->assertSee('Fração de ejeção: 63%');
    }

    public function test_falha_na_leitura_orienta_e_deixa_digitar_com_o_pdf_junto(): void
    {
        Notification::fake();
        $this->simularLeitura(new RuntimeException('erro técnico da API'));
        $pedido = $this->pedido(['Perfil lipídico']);
        $leitura = $this->enviar($pedido);

        $this->assertSame(ProcessingStatus::Failed, $leitura->status);
        $this->assertStringNotContainsString('erro técnico', (string) $leitura->error);
        Notification::assertSentTo($this->ana, HealthExamReadingFinished::class, fn ($n) => ! $n->success);

        Livewire::test(HealthExamResultForm::class, ['leitura' => $leitura->id])
            ->assertSee('Não foi possível ler este laudo')
            ->assertSet('values.2.name', 'LDL')
            ->set('performedOn', '2026-11-20')->set('values.2.value', '118')
            ->call('save')->assertHasNoErrors();

        $this->assertNotNull(HealthExamReport::query()->sole()->document_id);
    }

    public function test_descartar_apaga_o_arquivo_e_a_leitura_continua_contando_no_teto_do_dia(): void
    {
        config(['cerne.ai.exam_reads_per_day' => 1]);
        $this->simularLeitura($this->respostaDeSangue());
        $leitura = $this->enviar(null);

        Livewire::test(HealthExamResultForm::class, ['leitura' => $leitura->id])->call('discard')->assertRedirect(route('health.exams.index'));

        $this->assertSame('', $leitura->fresh()->source_path);
        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')));
        $this->assertSame(0, HealthExamReport::query()->count());

        Livewire::test(HealthExamUpload::class)->set('arquivo', UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'))->call('send')->assertHasErrors('arquivo');
        $this->assertSame(1, HealthExamReading::query()->count());
    }

    public function test_sem_a_chave_da_api_nada_e_enviado_e_so_pdf_e_aceito(): void
    {
        Livewire::test(HealthExamUpload::class)->set('arquivo', UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg'))->call('send')->assertHasErrors('arquivo');

        config(['cerne.ai.api_key' => null]);
        Livewire::test(HealthExamUpload::class)->set('open', true)->assertSee('não está disponível')
            ->set('arquivo', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->call('send')->assertHasErrors('arquivo');

        $this->assertSame(0, HealthExamReading::query()->count());
        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')));
    }

    public function test_a_resposta_da_leitura_nao_e_confiada(): void
    {
        $limpo = HealthExamReadingService::normalize(['tipo' => 'qualquer', 'data' => '2099-01-01', 'local' => ['x'], 'valores' => [
            ['nome' => 'Colesterol LDL', 'valor' => '118'], ['nome' => '', 'valor' => '1'], 'lixo', ['nome' => 'X', 'valor' => ''],
        ], 'campo_extra' => 'ignorado']);

        $this->assertSame(['values', '', ''], [$limpo['kind'], $limpo['performed_on'], $limpo['lab']]);
        $this->assertSame([['name' => 'LDL', 'value' => '118', 'unit' => '', 'reference' => '', 'doubt' => '']], $limpo['values']);
        $this->assertArrayNotHasKey('campo_extra', $limpo);
        $this->assertStringContainsString('apenas DADO', HealthExamReadingService::prompt());
        $this->assertStringContainsString('NÃO copie nome, CPF', HealthExamReadingService::prompt());
    }

    // --- Privacidade ---------------------------------------------------------------------------------------

    public function test_o_conjuge_ve_como_na_agenda_e_o_consultor_nao_ve_nada(): void
    {
        $pedido = $this->pedido(['TSH']);
        $this->laudo('2026-11-01', [['TSH', '2,0', '', '']]);
        $this->simularLeitura($this->respostaDeSangue());
        $this->enviar($pedido);

        $contexto = app(ProfileContext::class);
        $this->actingAs($this->joao);
        $contexto->set($this->perfil, $this->membroJoao);
        $this->assertSame([1, 1, 1, 1, 1], $this->contagens());

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);
        $this->actingAs($consultor);
        $contexto->set($this->perfil, null, true);
        $this->assertSame([0, 0, 0, 0, 0], $this->contagens());

        Livewire::test(HealthExamIndex::class)->assertForbidden();
        Livewire::test(HealthExamResults::class)->assertForbidden();
        Livewire::test(HealthExamBuilder::class)->assertForbidden();
    }

    public function test_quem_acompanha_so_le_nao_envia_nem_grava_e_as_telas_abrem_na_pessoa_acompanhada(): void
    {
        app(ProfileContext::class)->clear();
        $pessoa = app(CareProfileService::class)->create($this->ana, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $leitor = User::factory()->create();
        ProfileCaregiver::query()->create([
            'profile_id' => $pessoa->id, 'user_id' => $leitor->id, 'invited_email' => $leitor->email, 'invited_name' => $leitor->name,
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        $contexto = app(ProfileContext::class);
        $this->actingAs($this->ana);
        $contexto->set($pessoa, null);
        $pedido = app(HealthExamService::class)->createOrder($membro, ['title' => 'Exames de sangue', 'items' => [['name' => 'TSH']]], null, null, null);

        $this->actingAs($leitor);
        $contexto->set($pessoa, null, false, ProfileCaregiver::query()->active()->where('user_id', $leitor->id)->sole());

        foreach ([
            fn () => app(HealthExamReadingService::class)->request($membro, null, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), null),
            fn () => app(HealthExamService::class)->schedule($pedido, [$pedido->items()->value('id')], '2026-12-01 10:00', null, null),
            fn () => app(HealthExamResultService::class)->save($membro, $pedido, ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'TSH', 'value' => '1']]], [], null),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('quem só lê não grava');
            } catch (HttpException|\Illuminate\Auth\Access\AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')));
        $this->assertSame(0, HealthExamReport::query()->count());
        $this->assertSame(0, HealthAppointment::query()->count());

        $contexto->clear();
        $this->actingAs($leitor)->post(route('care.open', $pessoa->id))->assertRedirect();
        $this->get(route('health.exams.index'))->assertOk()->assertSee('Exames de sangue');
        $this->get(route('health.exams.show', $pedido->id))->assertOk();
        $this->get(route('health.exams.results'))->assertOk();
        $this->get(route('health.exams.manual', ['pedido' => $pedido->id]))->assertForbidden();
        $this->get(route('care.goto', ['profile' => $pessoa->id, 'destino' => 'exames']))->assertRedirect(route('health.exams.index'));
    }

    // --- Telas ------------------------------------------------------------------------------------------------

    public function test_montar_o_pedido_pela_tela_sem_nada_marcado_de_partida(): void
    {
        Livewire::test(HealthExamBuilder::class)
            ->assertSet('selected', [])->assertSet('title', 'Exames de sangue')->assertSee('Hemograma completo')
            ->call('save')->assertHasErrors('selected')
            ->call('chooseCategory', 'cardio')->assertSet('title', 'Avaliação cardiológica')
            ->call('toggle', 'Ecodopplercardiograma transtorácico')
            ->set('search', 'esteira')->assertSee('Teste ergométrico computadorizado')->call('toggle', 'Teste ergométrico computadorizado')
            ->set('search', 'Exame raro')->call('addTyped')
            ->set('selected.1.detail', 'Inclui ECG basal')
            ->assertSee('Os 3 exames ficam')
            ->call('save')->assertHasNoErrors();

        $pedido = HealthExamRequest::query()->sole();
        $this->assertSame('cardio', $pedido->category);
        $this->assertSame(['Ecodopplercardiograma transtorácico', 'Teste ergométrico computadorizado', 'Exame raro'], $pedido->items->pluck('name')->all());
        $this->assertSame('Inclui ECG basal', $pedido->items[1]->detail);

        Livewire::test(HealthExamBuilder::class)->call('chooseCategory', 'cardio')->assertSee('Repetir o pedido de 11/2026 (3 exames)')
            ->call('repeatPrevious')->assertCount('selected', 3);
        Livewire::test(HealthExamBuilder::class)->call('chooseCategory', 'other')->assertSee('Exame raro');
    }

    public function test_tela_do_pedido_marca_datas_e_recusa_item_de_outro_pedido(): void
    {
        $pedido = $this->pedido(['TSH', 'T4 livre']);
        $outro = $this->pedido(['Ureia']);
        $primeiro = $pedido->items()->get()->first();

        Livewire::test(HealthExamShow::class, ['pedido' => $pedido->id])
            ->call('startScheduling')->assertHasErrors('selected')
            ->set('selected', [$primeiro->id])->call('startScheduling')
            ->assertSee('com este exame: TSH')
            ->set('date', '2026-12-03')->set('time', '08:00')->set('place', 'Laboratório')->call('schedule')->assertHasNoErrors()
            ->assertSee('Marcado')->assertSee('Laboratório')
            ->call('open', 'add')->set('newName', 'Vitamina B12')->call('addItem')->assertHasNoErrors()->assertSee('Vitamina B12');

        $this->assertSame(1, HealthAppointment::query()->count());

        Livewire::test(HealthExamShow::class, ['pedido' => $pedido->id])->call('removeItem', $outro->items()->value('id'))->assertNotFound();
        $this->assertSame(1, $outro->items()->count());
    }

    public function test_digitar_a_mao_e_comparar_pela_tela(): void
    {
        $this->laudo('2025-11-10', [['LDL', '142', 'mg/dL', 'menor que 130']]);

        Livewire::test(HealthExamResultForm::class)
            ->set('performedOn', '2026-11-20')
            ->set('values.0.name', 'Colesterol LDL')->set('values.0.value', '118')->set('values.0.unit', 'mg/dL')->set('values.0.reference', 'menor que 130')
            ->assertSee('1 resultado entra na comparação')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('health.exams.results'));

        Livewire::test(HealthExamResults::class)
            ->assertSee('10/11/2025')->assertSee('20/11/2026')->assertSee('▲ acima')->assertSee('Evolução de LDL', false)
            // Pessoa que não existe para quem olha cai de volta na própria.
            ->set('memberId', (string) Str::uuid())->assertSet('memberId', $this->membroAna->id);
    }

    public function test_acompanhamento_mostra_pedido_e_resultado_e_o_pdf_leva_a_comparacao(): void
    {
        $acompanhamento = app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Colesterol', 'status' => 'watching', 'started_on' => '2026-11-01'], null);
        $this->laudo('2025-11-10', [['LDL', '142', 'mg/dL', 'menor que 130'], ['TSH', '2,0', '', '']]);
        $pedido = app(HealthExamService::class)->createOrder($this->membroAna, ['title' => 'Exames de sangue', 'items' => [['name' => 'Perfil lipídico'], ['name' => 'Ureia']]], null, $acompanhamento, null);
        app(HealthExamResultService::class)->save($this->membroAna, $pedido, ['kind' => 'values', 'performed_on' => '2026-11-20', 'values' => [['name' => 'LDL', 'value' => '118', 'unit' => 'mg/dL', 'reference' => 'menor que 130']]], [$pedido->items()->get()->first()->id], null);

        $linhas = app(HealthFollowupService::class)->timeline($acompanhamento);
        $this->assertSame(['result', 'request'], array_column($linhas, 'type'));
        $this->assertStringContainsString('Falta agendar: Ureia', $linhas[1]['text']);

        app(ProfileContext::class)->clear();
        $this->actingAs($this->ana)->get(route('health.followups.pdf', $acompanhamento->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('health.followups.show', $acompanhamento->id))->assertOk()->assertSee('Ver o resultado no pedido');
    }

    // --- Retenção e exclusão -------------------------------------------------------------------------------

    public function test_laudo_nunca_revisado_sai_depois_do_prazo(): void
    {
        $this->simularLeitura($this->respostaDeSangue());
        $leitura = $this->enviar(null);

        $this->travel(91)->days();
        app(DataRetentionService::class)->purge();

        $this->assertSame(['', null], [$leitura->fresh()->source_path, $leitura->fresh()->summary]);
        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')));
    }

    public function test_excluir_a_conta_leva_os_registros_e_o_pdf_esperando_revisao(): void
    {
        $this->simularLeitura($this->respostaDeSangue());
        $this->laudo('2026-11-01', [['TSH', '2,0', '', '']]);
        $this->enviar($this->pedido(['TSH']));
        app(ProfileContext::class)->clear();

        app(AccountDeletionService::class)->delete($this->ana);

        $this->assertSame([], Storage::disk(config('cerne.documents.disk'))->allFiles(config('cerne.documents.path')));
        foreach (['health_exam_requests', 'health_exam_items', 'health_exam_reports', 'health_exam_values', 'health_exam_readings'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
    }

    // -------------------------------------------------------------------------------------------------------------

    private function entrarComoAna(): void
    {
        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
    }

    /** @param  list<string>  $nomes */
    private function pedido(array $nomes, string $tipo = 'blood'): HealthExamRequest
    {
        return app(HealthExamService::class)->createOrder($this->membroAna, ['title' => 'Pedido', 'category' => $tipo, 'items' => array_map(fn (string $n) => ['name' => $n], $nomes)], null, null, $this->membroAna);
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $valores */
    private function laudo(string $data, array $valores): HealthExamReport
    {
        return app(HealthExamResultService::class)->save($this->membroAna, null, [
            'kind' => 'values', 'performed_on' => $data,
            'values' => array_map(fn (array $v) => ['name' => $v[0], 'value' => $v[1], 'unit' => $v[2], 'reference' => $v[3]], $valores),
        ], [], null);
    }

    /** @return list<HealthExamItemStatus> */
    private function situacoes(HealthExamRequest $pedido): array
    {
        return $pedido->items()->with('appointment')->get()->map(fn (HealthExamItem $i) => $i->status())->all();
    }

    /** @return list<int> pedidos, itens, laudos, valores e leituras que o contexto atual enxerga */
    private function contagens(): array
    {
        return [HealthExamRequest::query()->count(), HealthExamItem::query()->count(), HealthExamReport::query()->count(), HealthExamValue::query()->count(), HealthExamReading::query()->count()];
    }

    private function simularLeitura(array|\Throwable $resposta): void
    {
        $this->app->bind(HealthExamReadingService::class, fn () => new class($resposta) extends HealthExamReadingService
        {
            public function __construct(private readonly array|\Throwable $resposta) {}

            protected function ask(string $conteudo): array
            {
                if ($this->resposta instanceof \Throwable) {
                    throw $this->resposta;
                }

                return $this->resposta;
            }
        });
    }

    /** Roda a leitura como a fila rodaria (sem contexto de requisição) e devolve o contexto da Ana. */
    private function lerNaFila(HealthExamReading $leitura): void
    {
        app(ProfileContext::class)->clear();
        (new ReadExamReportJob($leitura->id))->handle(app(HealthExamReadingService::class), app(ProfileContext::class));
        $this->entrarComoAna();
    }

    private function enviar(?HealthExamRequest $pedido): HealthExamReading
    {
        $leitura = app(HealthExamReadingService::class)->request($this->membroAna, $pedido, UploadedFile::fake()->create('laudo.pdf', 20, 'application/pdf'), $this->membroAna);
        $this->lerNaFila($leitura);

        return $leitura->fresh();
    }

    /** @return array<string, mixed> */
    private function respostaDeSangue(): array
    {
        return ['tipo' => 'valores', 'data' => '2026-11-20', 'local' => 'Laboratório Exemplo', 'medico' => '', 'exame' => '', 'conclusao' => '', 'destaque' => '', 'observacoes' => '', 'valores' => [
            ['nome' => 'Colesterol LDL', 'valor' => '142', 'unidade' => 'mg/dL', 'referencia' => 'menor que 130', 'duvida' => ''],
            ['nome' => 'TSH', 'valor' => '2,10', 'unidade' => 'µUI/mL', 'referencia' => '0,40 a 4,50', 'duvida' => 'unidade pouco legível'],
            ['nome' => 'Ferritina', 'valor' => '86', 'unidade' => 'ng/mL', 'referencia' => '15 a 150', 'duvida' => ''],
        ]];
    }
}
