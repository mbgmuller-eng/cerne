<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\Lead;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Segunda leva de campos cifrados (revisão de segurança 2026-11): telefone, conta bancária, notas e a lista lida por IA. O banco guarda o
 * texto cifrado; o model devolve o original; o comando cifra o que sobrou em texto puro e não faz nada na segunda rodada.
 */
class ExtendedEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(): array
    {
        $usuario = User::factory()->create(['phone' => '11999998888']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$usuario, $perfil];
    }

    public function test_telefone_e_dados_de_conta_ficam_cifrados_no_banco_e_o_model_devolve_o_original(): void
    {
        [$usuario, $perfil] = $this->entrar();

        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['agency' => '1234', 'account_number' => '98765-4', 'notes' => 'conta do salário']);

        $this->assertStringNotContainsString('11999998888', (string) DB::table('users')->where('id', $usuario->id)->value('phone'));
        $linha = DB::table('bank_accounts')->where('id', $conta->id)->first();
        foreach (['agency' => '1234', 'account_number' => '98765-4', 'notes' => 'conta do salário'] as $coluna => $claro) {
            $this->assertStringNotContainsString($claro, (string) $linha->{$coluna});
        }

        $this->assertSame('11999998888', $usuario->fresh()->phone);
        $lida = BankAccount::query()->findOrFail($conta->id);
        $this->assertSame('1234', $lida->agency);
        $this->assertSame('98765-4', $lida->account_number);
        $this->assertSame('conta do salário', $lida->notes);
    }

    public function test_notas_e_nome_do_segurado_ficam_cifrados(): void
    {
        [, $perfil] = $this->entrar();

        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->create(['insured_person_name' => 'Dona Maria Aparecida', 'notes' => 'doença preexistente']);

        $linha = DB::table('insurance_policies')->where('id', $apolice->id)->first();
        $this->assertStringNotContainsString('Dona Maria', (string) $linha->insured_person_name);
        $this->assertStringNotContainsString('preexistente', (string) $linha->notes);
        $this->assertSame('Dona Maria Aparecida', InsurancePolicy::query()->findOrFail($apolice->id)->insured_person_name);
    }

    public function test_lead_e_vinculo_do_consultor_guardam_telefone_e_notas_cifrados(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();

        $lead = Lead::factory()->create(['consultant_id' => $consultor->id, 'phone' => '21988887777', 'notes' => 'quer resgatar tudo']);
        $vinculo = ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'notes' => 'cliente difícil']);

        $this->assertStringNotContainsString('21988887777', (string) DB::table('leads')->where('id', $lead->id)->value('phone'));
        $this->assertStringNotContainsString('quer resgatar', (string) DB::table('leads')->where('id', $lead->id)->value('notes'));
        $this->assertStringNotContainsString('cliente difícil', (string) DB::table('consultant_clients')->where('id', $vinculo->id)->value('notes'));
        $this->assertSame('21988887777', $lead->fresh()->phone);
        $this->assertSame('cliente difícil', $vinculo->fresh()->notes);
    }

    public function test_a_lista_lida_por_ia_fica_cifrada_e_volta_como_lista(): void
    {
        [$usuario, $perfil] = $this->entrar();
        $itens = [['description' => 'PIX para Fulano da Silva', 'amount' => '1500.00', 'date' => '2026-11-03']];

        $envio = DocumentUpload::create([
            'uploaded_by_user_id' => $usuario->id, 'document_type' => 'bank_statement', 'original_filename' => 'extrato.pdf',
            'storage_path' => 'x/extrato.pdf', 'size_bytes' => 10, 'processing_status' => ProcessingStatus::Completed,
            'records_extracted' => 1, 'extraction_summary' => ['itens' => $itens, 'observacoes' => null],
        ]);

        $cru = (string) DB::table('document_uploads')->where('id', $envio->id)->value('extraction_summary');
        $this->assertStringNotContainsString('Fulano', $cru);
        $this->assertStringNotContainsString('1500', $cru);

        $this->assertSame($itens, DocumentUpload::query()->findOrFail($envio->id)->extractedItems());
    }

    public function test_texto_cifrado_com_outra_chave_nao_aparece_na_tela_e_o_comando_nao_cifra_por_cima(): void
    {
        [, $perfil] = $this->entrar();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create();
        $deOutraChave = (new \Illuminate\Encryption\Encrypter(str_repeat('k', 32), config('app.cipher')))->encryptString('55555-1');
        DB::table('bank_accounts')->where('id', $conta->id)->update(['account_number' => $deOutraChave]);

        $this->assertSame('[ilegível]', BankAccount::query()->findOrFail($conta->id)->account_number);

        Artisan::call('cerne:encrypt-sensitive');

        $this->assertSame($deOutraChave, DB::table('bank_accounts')->where('id', $conta->id)->value('account_number'), 'não cifra o que já estava cifrado com outra chave');
    }

    public function test_o_comando_cifra_o_que_ficou_em_texto_puro_e_a_segunda_rodada_nao_faz_nada(): void
    {
        [$usuario, $perfil] = $this->entrar();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create();

        // Dado de antes da cifragem: texto puro escrito direto na tabela.
        DB::table('bank_accounts')->where('id', $conta->id)->update(['agency' => '4321', 'account_number' => '55555-1', 'notes' => 'antiga']);
        DB::table('users')->where('id', $usuario->id)->update(['phone' => '11977776666']);

        // Ainda legível pelo model (a troca não derruba tela).
        $this->assertSame('55555-1', BankAccount::query()->findOrFail($conta->id)->account_number);

        Artisan::call('cerne:encrypt-sensitive', ['--dry-run' => true]);
        $this->assertStringContainsString('bank_accounts: 1 a cifrar', Artisan::output());
        $this->assertSame('55555-1', DB::table('bank_accounts')->where('id', $conta->id)->value('account_number'), 'a simulação não grava');

        Artisan::call('cerne:encrypt-sensitive');

        $this->assertNotSame('55555-1', DB::table('bank_accounts')->where('id', $conta->id)->value('account_number'));
        $this->assertNotSame('11977776666', DB::table('users')->where('id', $usuario->id)->value('phone'));
        $this->assertSame('55555-1', BankAccount::query()->findOrFail($conta->id)->account_number);
        $this->assertSame('11977776666', $usuario->fresh()->phone);

        Artisan::call('cerne:encrypt-sensitive');
        $this->assertStringContainsString('Linhas com campo sensível em texto puro: 0', Artisan::output());
    }
}
