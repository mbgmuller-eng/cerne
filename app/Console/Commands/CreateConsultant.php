<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstrap de consultor.
 *
 * Não existe autocadastro nem convite por e-mail pro papel de consultor —
 * só cliente aceita convite (ver AcceptInviteController). Quem entra o
 * primeiro consultor, e qualquer um depois dele, é quem tem acesso ao
 * servidor. A senha é digitada aqui, nunca gerada e exibida em texto puro,
 * porque ainda não há e-mail de boas-vindas nem tela de troca de senha.
 */
class CreateConsultant extends Command
{
    protected $signature = 'cerne:criar-consultor {--name=} {--email=}';

    protected $description = 'Cria uma conta de consultor (não há autocadastro para esse papel ainda)';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Nome do consultor', required: true);
        $email = $this->option('email') ?: text('E-mail do consultor', required: true);

        $validator = Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
        ], attributes: ['name' => 'nome', 'email' => 'e-mail']);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $erro) {
                $this->components->error($erro);
            }

            return self::FAILURE;
        }

        // Mesma política de senha do aceite de convite de cliente
        // (AcceptInviteController) — um consultor não é menos sensível.
        $regraSenha = Password::min(8)->letters()->numbers();

        $senha = password(
            'Senha do consultor (mín. 8 caracteres, com letra e número)',
            validate: fn (string $value) => Validator::make(['password' => $value], ['password' => $regraSenha])
                ->errors()->first('password'),
        );

        $confirmacao = password('Confirme a senha');

        if ($senha !== $confirmacao) {
            $this->components->error('As senhas não conferem.');

            return self::FAILURE;
        }

        $consultor = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $senha,
            'role' => UserRole::Consultant,
            'is_active' => true,
        ]);
        $consultor->forceFill(['email_verified_at' => now()])->save();

        $this->newLine();
        $this->components->info("Consultor criado: {$consultor->name} <{$consultor->email}>");

        return self::SUCCESS;
    }
}
