<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PolicyCertificateImporter;
use Illuminate\Console\Command;

/**
 * Carga de certificados de seguro de vida lidos dos PDFs (ver DEPLOY.md). O JSON traz um item por
 * certificado: arquivo, nome, cpf, nascimento, certificado, apolice, produto, emissao, premio_total,
 * periodicidade, forma_pagamento, indice, vigencia_apolice, coberturas[], beneficiarios[].
 *
 * Sem --apply nada é gravado: o relatório mostra quem seria criado, atualizado ou deixado para
 * revisão, para uma pessoa conferir antes (dado extraído de PDF não entra sem olho humano).
 */
class ImportPolicyCertificates extends Command
{
    protected $signature = 'cerne:import-policies
        {json : Caminho do JSON com os certificados lidos}
        {--consultant= : E-mail do consultor dono da carteira}
        {--pdf-dir= : Pasta com os PDFs (nomes iguais ao campo "arquivo" do JSON)}
        {--apply : Grava de verdade (sem isto é só simulação)}';

    protected $description = 'Cria ou atualiza apólices de vida a partir de certificados e guarda o PDF em Documentos';

    public function handle(PolicyCertificateImporter $importador): int
    {
        $consultor = User::query()->where('email', $this->option('consultant'))->first();
        if ($consultor === null) {
            $this->error('Consultor não encontrado: informe --consultant=email.');

            return self::FAILURE;
        }

        $certificados = json_decode((string) @file_get_contents($this->argument('json')), true);
        if (! is_array($certificados) || $certificados === []) {
            $this->error('JSON ilegível ou vazio.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $linhas = $importador->run($consultor, $certificados, $this->option('pdf-dir'), $apply);

        $this->table(
            ['Status', 'Nome no PDF', 'Apólice', 'Cliente / pessoa', 'Ação', 'PDF', 'Observação'],
            array_map(fn (array $l) => [
                $l['status'],
                mb_substr($l['nome'], 0, 32),
                $l['apolice'],
                $l['cliente'] ? $l['cliente'].' / '.$l['membro'] : '-',
                $l['acao'] ?? '-',
                $l['documento'] ?? '-',
                $l['motivo'] ?? $this->resumirMudancas($l['mudancas']),
            ], $linhas),
        );

        $contagem = array_count_values(array_column($linhas, 'status'));
        $this->line('Resumo: '.implode(', ', array_map(fn ($s, $n) => "$n $s", array_keys($contagem), $contagem)).'.');
        $this->line($apply ? 'Gravado.' : 'Simulação: nada foi gravado. Rode de novo com --apply para aplicar.');

        return self::SUCCESS;
    }

    /** @param  array<string, array{de: mixed, para: mixed}>  $mudancas */
    private function resumirMudancas(array $mudancas): string
    {
        return implode('; ', array_map(
            fn ($campo, $m) => "$campo: ".($m['de'] ?? 'vazio').' → '.($m['para'] ?? 'vazio'),
            array_keys($mudancas),
            $mudancas,
        ));
    }
}
