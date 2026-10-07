<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Subscription\SubscriptionIndex;
use App\Models\BillingDetail;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Dados fiscais de quem paga (nome completo, CPF/CNPJ, nascimento e endereço):
 * pedidos na tela de pagamento, guardados do lado do Cerne e enviados ao cliente
 * da Asaas, de onde sai o tomador da nota fiscal.
 */
class BillingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function teste(User $usuario): Subscription
    {
        return Subscription::create([
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays(3),
            'started_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function dados(array $extra = []): array
    {
        return $extra + [
            'fiscalNome' => 'Maria da Silva',
            'cpfCnpj' => '529.982.247-25',
            'fiscalNascimento' => '1990-05-12',
            'cep' => '80230-010',
            'rua' => 'Avenida Sete de Setembro',
            'numero' => '2775',
            'complemento' => 'Sala 1',
            'bairro' => 'Rebouças',
            'cidade' => 'Curitiba',
            'uf' => 'PR',
            'metodoPagamento' => 'pix',
        ];
    }

    public function test_pagar_guarda_os_dados_fiscais_normalizados_e_sincroniza_o_cpf_da_conta(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->once()
            ->withArgs(fn ($u, $fiscal) => $fiscal instanceof BillingDetail && $fiscal->postal_code === '80230010' && $fiscal->document === '52998224725')
            ->andReturn('cus_1');
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null);
        $asaas->shouldReceive('createSubscription')->andReturn(['id' => 'sub_1']);
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados())
            ->call('iniciarPagamento')
            ->assertHasNoErrors();

        $fiscal = BillingDetail::query()->sole();
        self::assertSame($usuario->id, $fiscal->user_id);
        self::assertSame('Maria da Silva', $fiscal->full_name);
        self::assertSame('52998224725', $fiscal->document);
        self::assertSame('1990-05-12', $fiscal->birth_date->toDateString());
        self::assertSame('80230010', $fiscal->postal_code);
        self::assertSame('PR', $fiscal->state);
        self::assertSame('Sala 1', $fiscal->complement);
        self::assertSame('52998224725', $usuario->fresh()->cpf_cnpj);
    }

    public function test_pagar_de_novo_atualiza_o_mesmo_registro(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn('sub_1');
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        $tela = Livewire::actingAs($usuario)->test(SubscriptionIndex::class);
        $tela->set($this->dados())->call('iniciarPagamento');
        $tela->set('numero', '9999')->call('trocarFormaDePagamento')->set($this->dados(['numero' => '9999']))->call('iniciarPagamento');

        self::assertSame(1, BillingDetail::query()->count());
        self::assertSame('9999', BillingDetail::query()->sole()->number);
    }

    public function test_a_tela_vem_preenchida_com_o_que_ja_sabemos(): void
    {
        $usuario = User::factory()->create(['name' => 'Maria Souza', 'birthdate' => '1985-03-20']);
        $this->teste($usuario);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSet('fiscalNome', 'Maria Souza')
            ->assertSet('fiscalNascimento', '1985-03-20')
            ->assertSet('cep', '');

        BillingDetail::create([
            'user_id' => $usuario->id, 'full_name' => 'Maria de Souza Lima', 'document' => '52998224725', 'birth_date' => '1985-03-20',
            'postal_code' => '01310100', 'street' => 'Avenida Paulista', 'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP',
        ]);

        Livewire::actingAs($usuario->fresh())->test(SubscriptionIndex::class)
            ->assertSet('fiscalNome', 'Maria de Souza Lima')
            ->assertSet('cpfCnpj', '52998224725')
            ->assertSet('cep', '01310100')
            ->assertSet('uf', 'SP');
    }

    public function test_endereco_e_obrigatorio_e_nada_vai_para_a_asaas(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);
        $this->asaas()->shouldNotReceive('findOrCreateCustomer');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['cep' => '', 'rua' => '', 'numero' => '', 'bairro' => '', 'cidade' => '', 'uf' => '']))
            ->call('iniciarPagamento')
            ->assertHasErrors(['cep', 'rua', 'numero', 'bairro', 'cidade', 'uf']);

        self::assertSame(0, BillingDetail::query()->count());
    }

    /** Sem lang/pt_BR/validation.php os erros saíam em inglês ("The CEP field is required") em todo o app. */
    public function test_erros_de_validacao_saem_em_portugues(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);
        $this->asaas();

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['cep' => '', 'cpfCnpj' => '']))
            ->call('iniciarPagamento')
            ->assertSee('O campo CEP é obrigatório.')
            ->assertSee('O campo CPF ou CNPJ é obrigatório.')
            ->assertDontSee('field is required');
    }

    public function test_cep_e_uf_invalidos_sao_barrados(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);
        $this->asaas()->shouldNotReceive('findOrCreateCustomer');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['cep' => '1234', 'uf' => 'XX']))
            ->call('iniciarPagamento')
            ->assertHasErrors(['cep', 'uf']);
    }

    public function test_pessoa_fisica_precisa_de_nome_completo_e_nascimento(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);
        $this->asaas()->shouldNotReceive('findOrCreateCustomer');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['fiscalNome' => 'Maria', 'fiscalNascimento' => '']))
            ->call('iniciarPagamento')
            ->assertHasErrors(['fiscalNome', 'fiscalNascimento']);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['fiscalNascimento' => now()->addDay()->toDateString()]))
            ->call('iniciarPagamento')
            ->assertHasErrors('fiscalNascimento');
    }

    public function test_empresa_nao_precisa_de_nascimento_nem_de_sobrenome(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn('sub_1');
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn(null);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados(['fiscalNome' => 'Cerne Ltda', 'cpfCnpj' => '11.222.333/0001-81', 'fiscalNascimento' => '']))
            ->call('iniciarPagamento')
            ->assertHasNoErrors();

        $fiscal = BillingDetail::query()->sole();
        self::assertTrue($fiscal->isCompany());
        self::assertNull($fiscal->birth_date);
    }

    public function test_recusa_da_asaas_aparece_na_tela_com_o_motivo_e_nao_cria_assinatura(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);

        $asaas = $this->asaas();
        $resposta = new ClientResponse(new \GuzzleHttp\Psr7\Response(400, ['Content-Type' => 'application/json'], json_encode(['errors' => [['description' => 'O CEP informado é inválido.']]])));
        $asaas->shouldReceive('findOrCreateCustomer')->andThrow(new RequestException($resposta));
        $asaas->shouldNotReceive('createSubscription');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dados())
            ->call('iniciarPagamento')
            ->assertHasErrors('cpfCnpj')
            ->assertSee('O CEP informado é inválido.');
    }

    public function test_formulario_fiscal_aparece_na_tela_de_pagamento(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario);
        $this->asaas();

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSee('Dados para a nota fiscal')
            ->assertSee('Nome completo ou razão social')
            ->assertSee('Data de nascimento')
            ->assertSee('CEP')
            ->assertSee('Bairro');
    }

    // ---- cliente da Asaas (cliente HTTP)

    public function test_cliente_novo_na_asaas_nasce_com_nome_documento_e_endereco(): void
    {
        config(['services.asaas.base_url' => 'https://asaas.test/v3', 'services.asaas.api_key' => 'k']);
        Http::fake(['asaas.test/v3/customers' => Http::response(['id' => 'cus_9'])]);

        $usuario = User::factory()->create(['asaas_customer_id' => null]);
        $fiscal = BillingDetail::create([
            'user_id' => $usuario->id, 'full_name' => 'Maria da Silva', 'document' => '52998224725', 'postal_code' => '80230010',
            'street' => 'Avenida Sete de Setembro', 'number' => '2775', 'complement' => null, 'neighborhood' => 'Rebouças', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        self::assertSame('cus_9', app(AsaasClient::class)->findOrCreateCustomer($usuario, $fiscal));
        self::assertSame('cus_9', $usuario->fresh()->asaas_customer_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers')
            && $r['name'] === 'Maria da Silva' && $r['cpfCnpj'] === '52998224725' && $r['postalCode'] === '80230010'
            && $r['address'] === 'Avenida Sete de Setembro' && $r['addressNumber'] === '2775' && $r['province'] === 'Rebouças'
            && ! isset($r['complement']));
    }

    public function test_cliente_que_ja_existe_na_asaas_e_atualizado_com_os_dados_fiscais(): void
    {
        config(['services.asaas.base_url' => 'https://asaas.test/v3', 'services.asaas.api_key' => 'k']);
        Http::fake(['asaas.test/v3/customers/cus_1' => Http::response(['id' => 'cus_1'])]);

        $usuario = User::factory()->create(['asaas_customer_id' => 'cus_1']);
        $fiscal = BillingDetail::create([
            'user_id' => $usuario->id, 'full_name' => 'Maria da Silva', 'document' => '52998224725', 'postal_code' => '80230010',
            'street' => 'Rua A', 'number' => '10', 'complement' => 'Apto 2', 'neighborhood' => 'Centro', 'city' => 'Curitiba', 'state' => 'PR',
        ]);

        self::assertSame('cus_1', app(AsaasClient::class)->findOrCreateCustomer($usuario, $fiscal));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customers/cus_1') && $r['complement'] === 'Apto 2' && $r['address'] === 'Rua A');
    }

    public function test_sem_dados_fiscais_o_cliente_existente_nao_gera_chamada(): void
    {
        Http::fake();
        $usuario = User::factory()->create(['asaas_customer_id' => 'cus_1']);

        self::assertSame('cus_1', app(AsaasClient::class)->findOrCreateCustomer($usuario));
        Http::assertNothingSent();
    }
}
