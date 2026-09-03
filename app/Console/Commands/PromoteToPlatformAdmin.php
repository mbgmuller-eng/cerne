<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * Dá acesso ao painel /admin a uma conta JÁ EXISTENTE — não cria conta
 * nova (isso já existe via cerne:criar-consultor ou convite de cliente).
 * is_platform_admin é independente de `role`: um consultor continua
 * consultor, só ganha a tela de gestão da plataforma por cima.
 */
class PromoteToPlatformAdmin extends Command
{
    protected $signature = 'cerne:tornar-admin {--email=}';

    protected $description = 'Dá acesso ao painel /admin para uma conta já existente';

    public function handle(): int
    {
        $email = $this->option('email') ?: text('E-mail da conta', required: true);

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Nenhuma conta encontrada com o e-mail {$email}.");

            return self::FAILURE;
        }

        $user->update(['is_platform_admin' => true]);

        $this->components->info("{$user->name} <{$user->email}> agora tem acesso ao painel /admin.");

        return self::SUCCESS;
    }
}
