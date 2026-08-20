<?php

namespace Database\Seeders;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\UserRole;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileAccessSettings;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados de desenvolvimento — nunca rode em produção.
 *
 * Monta o cenário que os testes descrevem: um consultor com um cliente
 * casal, para dar o que olhar nas telas 1 e 9.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        $consultor = User::create([
            'name' => 'Marina Alencar',
            'email' => 'consultor@cerne.test',
            'password' => 'password',
            'role' => UserRole::Consultant,
            'is_active' => true,
        ]);
        $consultor->forceFill(['email_verified_at' => now()])->save();

        $ana = User::create([
            'name' => 'Ana Ribeiro',
            'email' => 'ana@cerne.test',
            'password' => 'password',
            'role' => UserRole::Client,
            'is_active' => true,
        ]);
        $ana->forceFill(['email_verified_at' => now()])->save();

        $bruno = User::create([
            'name' => 'Bruno Ribeiro',
            'email' => 'bruno@cerne.test',
            'password' => 'password',
            'role' => UserRole::Client,
            'is_active' => true,
        ]);
        $bruno->forceFill(['email_verified_at' => now()])->save();

        $profile = FinancialProfile::create([
            'owner_user_id' => $ana->id,
            'profile_name' => 'Família Ribeiro',
            'profile_type' => ProfileType::Couple,
            'base_currency' => 'BRL',
            'reference_month' => 1,
        ]);

        ProfileMember::create([
            'profile_id' => $profile->id,
            'user_id' => $ana->id,
            'name' => 'Ana',
            'role' => MemberRole::Primary,
            'color_hex' => '#0F766E',
            'is_active' => true,
        ]);

        ProfileMember::create([
            'profile_id' => $profile->id,
            'user_id' => $bruno->id,
            'name' => 'Bruno',
            'role' => MemberRole::Secondary,
            'color_hex' => '#B45309',
            'is_active' => true,
        ]);

        ProfileAccessSettings::create(array_merge(
            ProfileAccessSettings::transparentPreset(),
            ['profile_id' => $profile->id, 'updated_by_user_id' => $ana->id],
        ));

        ConsultantClient::create([
            'consultant_id' => $consultor->id,
            'client_id' => $ana->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now()->subMonth(),
            'accepted_at' => now()->subMonth(),
        ]);

        $this->command->newLine();
        $this->command->info('Acessos de desenvolvimento (senha: password)');
        $this->command->line('  consultor@cerne.test  — consultor');
        $this->command->line('  ana@cerne.test        — titular do casal');
        $this->command->line('  bruno@cerne.test      — cônjuge');
    }
}
