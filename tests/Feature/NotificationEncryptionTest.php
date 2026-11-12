<?php

namespace Tests\Feature;

use App\Mail\ClientInviteMail;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Notifications\ResetPasswordLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Os textos das notificações do sino ficam cifrados no banco, e o conteúdo de e-mail e notificação que espera na fila também
 * (revisão de segurança 2026-11): a tabela `notifications`, `jobs` e `failed_jobs` entram no backup.
 */
class NotificationEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_texto_da_notificacao_fica_cifrado_no_banco_e_os_ids_seguem_consultaveis(): void
    {
        $usuario = User::factory()->create();

        $usuario->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => HealthAppointmentUpcoming::class,
            'data' => ['kind' => 'health_appointment_upcoming', 'health_appointment_id' => 'abc-123', 'title' => 'Consulta: oncologista', 'member_name' => 'Dona Maria', 'scheduled_at' => '2026-12-01 10:00'],
        ]);

        $cru = (string) DB::table('notifications')->value('data');
        $this->assertStringNotContainsString('oncologista', $cru);
        $this->assertStringNotContainsString('Dona Maria', $cru);
        $this->assertStringContainsString('abc-123', $cru);

        // Pelo model, o texto volta inteiro (o sino não percebe a cifragem).
        $lida = $usuario->notifications()->first();
        $this->assertSame('Consulta: oncologista', $lida->data['title']);
        $this->assertSame('Dona Maria', $lida->data['member_name']);

        // A deduplicação continua achando por identificador.
        $this->assertTrue($usuario->notifications()->whereJsonContains('data->health_appointment_id', 'abc-123')->exists());
    }

    public function test_linha_antiga_em_texto_puro_continua_legivel_e_o_comando_cifra(): void
    {
        $usuario = User::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $usuario->id,
            'data' => json_encode(['kind' => 'health_care_item_due', 'title' => 'Troca da prótese', 'health_care_item_id' => 'i-1']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('Troca da prótese', DatabaseNotification::query()->first()->data['title']);

        Artisan::call('cerne:encrypt-sensitive', ['--dry-run' => true]);
        $this->assertStringContainsString('notifications: 1', Artisan::output());
        $this->assertStringContainsString('Troca da prótese', (string) DB::table('notifications')->value('data'), 'simulação não grava');

        Artisan::call('cerne:encrypt-sensitive');

        $this->assertStringNotContainsString('Troca da prótese', (string) DB::table('notifications')->value('data'));
        $this->assertSame('Troca da prótese', DatabaseNotification::query()->first()->data['title']);

        Artisan::call('cerne:encrypt-sensitive');
        $this->assertStringContainsString('notifications: 0', Artisan::output(), 'rodar de novo não faz nada');
    }

    public function test_conteudo_que_espera_na_fila_vai_cifrado(): void
    {
        $this->assertContains(ShouldBeEncrypted::class, class_implements(ClientInviteMail::class));
        $this->assertContains(ShouldBeEncrypted::class, class_implements(ResetPasswordLink::class));
        $this->assertContains(ShouldBeEncrypted::class, class_implements(HealthAppointmentUpcoming::class));
    }
}
