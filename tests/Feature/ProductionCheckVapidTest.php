<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * cerne:check precisa pegar a chave VAPID malformada: em produção, duas linhas
 * do .env coladas numa só deixaram a chave privada com 84 caracteres e o push
 * falhou em silêncio, enquanto o check só via "campo preenchido".
 */
class ProductionCheckVapidTest extends TestCase
{
    private const AVISO = 'Faltam chaves ou o assunto';

    /**
     * O validador só confere formato e tamanho (pública 65 bytes, privada 32),
     * então chaves sintéticas bastam — gerar um par EC de verdade exige o
     * OpenSSL configurado, o que o PHP do Windows de desenvolvimento não tem.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    private function chavesDeTamanhoCerto(): array
    {
        $base64url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'publicKey' => $base64url(chr(4).random_bytes(64)),
            'privateKey' => $base64url(random_bytes(32)),
        ];
    }

    private function configurar(string $subject, string $publica, string $privada): void
    {
        config([
            'webpush.vapid.subject' => $subject,
            'webpush.vapid.public_key' => $publica,
            'webpush.vapid.private_key' => $privada,
        ]);
    }

    public function test_par_valido_com_assunto_passa(): void
    {
        $chaves = $this->chavesDeTamanhoCerto();
        $this->configurar('mailto:noreply@cerne.app.br', $chaves['publicKey'], $chaves['privateKey']);

        $this->artisan('cerne:check')
            ->expectsOutputToContain('Chaves VAPID válidas')
            ->doesntExpectOutputToContain(self::AVISO)
            ->assertSuccessful();
    }

    public function test_chave_privada_com_a_linha_seguinte_colada_e_pega(): void
    {
        $chaves = $this->chavesDeTamanhoCerto();
        // O que aconteceu em produção: sem quebra de linha, o texto seguinte do .env virou parte da chave.
        $this->configurar('', $chaves['publicKey'], $chaves['privateKey'].'VAPID_SUBJECT=mailto:noreply@cerne.app.br');

        $this->artisan('cerne:check')
            ->expectsOutputToContain(self::AVISO)
            ->assertSuccessful();
    }

    public function test_assunto_ausente_e_pego(): void
    {
        $chaves = $this->chavesDeTamanhoCerto();
        $this->configurar('', $chaves['publicKey'], $chaves['privateKey']);

        $this->artisan('cerne:check')->expectsOutputToContain(self::AVISO)->assertSuccessful();
    }

    public function test_chaves_ausentes_sao_pegas(): void
    {
        $this->configurar('mailto:noreply@cerne.app.br', '', '');

        $this->artisan('cerne:check')->expectsOutputToContain(self::AVISO)->assertSuccessful();
    }
}
