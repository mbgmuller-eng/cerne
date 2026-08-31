<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\MemberRole;
use App\Enums\ProcessingStatus;
use App\Livewire\Notifications\NotificationCenter;
use App\Models\DocumentUpload;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DocumentProcessed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_a_contagem_de_nao_lidas(): void
    {
        $usuario = $this->criarUsuarioComPerfil();

        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Failed)));

        Livewire::test(NotificationCenter::class)
            ->assertViewHas('unreadCount', 2);
    }

    public function test_marcar_como_lida_reduz_a_contagem(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $id = $usuario->notifications()->first()->id;

        Livewire::test(NotificationCenter::class)
            ->call('markAsRead', $id)
            ->assertViewHas('unreadCount', 0);

        self::assertNotNull($usuario->notifications()->find($id)->read_at);
    }

    public function test_marcar_tudo_como_lido(): void
    {
        $usuario = $this->criarUsuarioComPerfil();
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Completed)));
        $usuario->notify(DocumentProcessed::forDocument($this->criarDocumento($usuario, ProcessingStatus::Failed)));

        Livewire::test(NotificationCenter::class)
            ->call('markAllAsRead')
            ->assertViewHas('unreadCount', 0);
    }

    private function criarUsuarioComPerfil(): User
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        return $usuario;
    }

    private function criarDocumento(User $usuario, ProcessingStatus $status): DocumentUpload
    {
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);

        return DocumentUpload::withoutProfileScope()->create([
            'profile_id' => $perfil->id,
            'uploaded_by_user_id' => $usuario->id,
            'member_id' => $membro->id,
            'document_type' => DocumentType::BankStatement,
            'original_filename' => 'documento.pdf',
            'storage_path' => 'documentos/'.$perfil->id.'/documento.pdf',
            'size_bytes' => 1024,
            'processing_status' => $status,
        ]);
    }
}
