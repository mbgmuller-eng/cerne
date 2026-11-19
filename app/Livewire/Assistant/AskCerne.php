<?php

namespace App\Livewire\Assistant;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Services\Assistant\AssistantService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * "Pergunte ao Cerne": conversa sobre as finanças do perfil aberto. O recurso fica desligado até o dono ligar (`cerne.assistant.enabled`) e a
 * conversa só existe aqui na tela (nada é gravado). O histórico é `#[Locked]` para o navegador não conseguir forjar uma fala do assistente.
 */
#[Layout('components.layouts.app')]
class AskCerne extends Component
{
    use RequiresActiveProfile;

    private const MAX_QUESTION = 500;

    /** @var list<array{role: string, text: string}> */
    #[Locked]
    public array $conversa = [];

    public string $pergunta = '';

    public ?string $erro = null;

    public function mount(): void
    {
        abort_unless(AssistantService::enabled(), 404);
        $this->redirectOrAbortWithoutProfile();
        // Corretor só entra em Seguros: nenhuma outra tela financeira.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function enviar(AssistantService $assistente): void
    {
        abort_unless(AssistantService::enabled(), 404);
        $this->erro = null;

        $this->validate(['pergunta' => ['required', 'string', 'max:'.self::MAX_QUESTION]], [
            'pergunta.required' => 'Escreva a sua pergunta.',
            'pergunta.max' => 'Pergunte em até '.self::MAX_QUESTION.' caracteres.',
        ], ['pergunta' => 'pergunta']);

        $pergunta = trim($this->pergunta);
        $this->pergunta = '';

        try {
            $texto = $assistente->ask(auth()->id(), $pergunta, $this->conversa);
        } catch (RuntimeException $e) {
            // A mensagem do serviço já é para a pessoa ler; a pergunta fica na conversa para ela ver o que foi respondido.
            $this->conversa[] = ['role' => 'user', 'text' => $pergunta];
            $this->erro = $e->getMessage();

            return;
        }

        $this->conversa[] = ['role' => 'user', 'text' => $pergunta];
        $this->conversa[] = ['role' => 'assistant', 'text' => $texto];
    }

    public function limpar(): void
    {
        $this->conversa = [];
        $this->erro = null;
    }

    public function render(AssistantService $assistente)
    {
        return view('livewire.assistant.ask-cerne', ['restantes' => $assistente->remaining(auth()->id())]);
    }
}
