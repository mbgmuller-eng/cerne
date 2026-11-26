<?php

namespace App\Support\Health;

use Illuminate\Support\Str;

/**
 * Lista dos exames mais comuns por tipo, para a pessoa MARCAR o que o médico pediu (nada vem marcado: o Cerne não sugere exame), e os
 * marcadores que vêm dentro dos resultados, com os nomes alternativos. É código, não tabela: o que não está aqui a pessoa digita, e o que ela
 * já digitou volta a aparecer porque sai dos pedidos antigos dela.
 *
 * O nome do marcador é o que junta os anos na comparação: "Colesterol LDL" num laboratório e "LDL" no outro são a mesma linha. A
 * correspondência é pelo texto INTEIRO normalizado, nunca por pedaço: "colesterol não HDL" não pode cair em HDL.
 */
final class ExamCatalog
{
    /** tipo => [rótulo, nome sugerido para o pedido] */
    public const CATEGORIES = [
        'blood' => ['Sangue', 'Exames de sangue'],
        'urine' => ['Urina e fezes', 'Exames de urina e fezes'],
        'cardio' => ['Cardiologia', 'Avaliação cardiológica'],
        'imaging' => ['Imagem', 'Exames de imagem'],
        'other' => ['Outros', 'Outros exames'],
    ];

    /** tipo => lista de [nome, nomes alternativos para a busca, dica] */
    private const EXAMS = [
        'blood' => [
            ['Hemograma completo', ['hemograma'], null],
            ['Glicemia de jejum', ['glicose', 'glicemia'], null],
            ['Hemoglobina glicada (HbA1c)', ['hba1c', 'a1c', 'hemoglobina glicada', 'hemoglobina glicosilada'], null],
            ['Insulina', ['insulina basal'], null],
            ['Perfil lipídico', ['colesterol', 'lipidograma', 'hdl', 'ldl', 'triglicerides'], 'Traz colesterol total, HDL, LDL e triglicerídeos'],
            ['TSH', ['tireoide', 'hormonio tireoestimulante'], null],
            ['T4 livre', ['tireoide', 'tiroxina livre'], null],
            ['T3', ['tireoide', 'triiodotironina'], null],
            ['Creatinina', ['rim'], null],
            ['Ureia', ['rim'], null],
            ['Ácido úrico', [], null],
            ['TGO (AST)', ['figado', 'transaminase', 'tgo', 'ast'], null],
            ['TGP (ALT)', ['figado', 'transaminase', 'tgp', 'alt'], null],
            ['Gama GT', ['figado', 'ggt'], null],
            ['Fosfatase alcalina', ['figado'], null],
            ['Bilirrubinas', ['figado'], null],
            ['Sódio', ['eletrolitos'], null],
            ['Potássio', ['eletrolitos'], null],
            ['Cálcio', [], null],
            ['Magnésio', [], null],
            ['Vitamina D (25-hidroxi)', ['vitamina d', '25 oh'], null],
            ['Vitamina B12', ['cobalamina'], null],
            ['Ácido fólico', ['folato'], null],
            ['Ferritina', ['ferro'], null],
            ['Ferro sérico', ['ferro'], null],
            ['PCR ultrassensível', ['proteina c reativa', 'pcr'], null],
            ['VHS', ['velocidade de hemossedimentacao'], null],
            ['Coagulograma', ['coagulacao', 'inr', 'tap'], null],
            ['PSA total', ['prostata', 'psa'], null],
            ['Testosterona total', ['hormonio'], null],
            ['Estradiol', ['hormonio'], null],
        ],
        'urine' => [
            ['Urina tipo 1 (EAS)', ['eas', 'sumario de urina', 'urina 1'], null],
            ['Urocultura', ['cultura de urina'], null],
            ['Microalbuminúria', ['albumina na urina'], null],
            ['Sangue oculto nas fezes', ['fit'], null],
            ['Parasitológico de fezes', ['protoparasitologico'], null],
        ],
        'cardio' => [
            ['Eletrocardiograma (ECG)', ['ecg', 'eletro'], null],
            ['Ecodopplercardiograma transtorácico', ['eco', 'ecocardiograma', 'ecodoppler'], null],
            ['Teste ergométrico computadorizado', ['ergometrico', 'esteira', 'teste de esforco'], 'Inclui ECG basal convencional'],
            ['Doppler de carótidas e vertebrais', ['carotidas', 'ultrassom do pescoco'], null],
            ['Holter 24 horas', ['holter'], null],
            ['MAPA 24 horas', ['mapa', 'pressao'], null],
            ['Escore de cálcio coronariano', ['calcio coronario'], null],
            ['Angiotomografia de coronárias', ['angiotomografia'], null],
            ['Consulta com cardiologista', ['cardiologista', 'retorno'], null],
        ],
        'imaging' => [
            ['Ultrassom de abdome total', ['ultrassonografia', 'abdome'], null],
            ['Ultrassom de tireoide', ['ultrassonografia', 'tireoide'], null],
            ['Ultrassom das mamas', ['ultrassonografia', 'mama'], null],
            ['Ultrassom pélvico ou transvaginal', ['ultrassonografia', 'transvaginal'], null],
            ['Ultrassom de próstata', ['ultrassonografia', 'prostata'], null],
            ['Ressonância magnética', ['rm', 'ressonancia'], 'Diga a região no detalhe'],
            ['Tomografia', ['tc'], 'Diga a região no detalhe'],
            ['Raio X', ['rx', 'radiografia'], 'Diga a região no detalhe'],
            ['Densitometria óssea', ['densitometria'], null],
            ['Mamografia', ['mama'], null],
        ],
        'other' => [
            ['Colonoscopia', [], null],
            ['Endoscopia digestiva alta', ['endoscopia'], null],
            ['Mapeamento de retina', ['fundo de olho'], null],
            ['Papanicolau', ['preventivo', 'citologia'], null],
            ['Audiometria', ['audicao'], null],
            ['Espirometria', ['pulmao'], null],
            ['Polissonografia', ['sono'], null],
            ['Eletroneuromiografia', ['nervo', 'enmg'], null],
        ],
    ];

    /** Marcadores: nome na comparação => [exame do catálogo de onde costuma vir, nomes alternativos]. */
    private const MARKERS = [
        'Hemoglobina' => ['Hemograma completo', ['hemoglobina', 'hb']],
        'Hematócrito' => ['Hemograma completo', ['hematocrito', 'ht']],
        'Eritrócitos' => ['Hemograma completo', ['eritrocitos', 'hemacias']],
        'VCM' => ['Hemograma completo', ['vcm', 'v c m', 'volume corpuscular medio']],
        'HCM' => ['Hemograma completo', ['hcm', 'h c m']],
        'CHCM' => ['Hemograma completo', ['chcm', 'c h c m']],
        'RDW' => ['Hemograma completo', ['rdw', 'rdw cv']],
        'Leucócitos' => ['Hemograma completo', ['leucocitos', 'leucocitos totais', 'globulos brancos']],
        'Plaquetas' => ['Hemograma completo', ['plaquetas', 'contagem de plaquetas']],
        'Glicemia de jejum' => ['Glicemia de jejum', ['glicemia de jejum', 'glicemia', 'glicose', 'glicose em jejum', 'glicose de jejum', 'glicemia em jejum', 'glicemia jejum']],
        'Hemoglobina glicada' => ['Hemoglobina glicada (HbA1c)', ['hemoglobina glicada', 'hemoglobina glicada hba1c', 'hba1c', 'a1c', 'hemoglobina glicosilada', 'hemoglobina glicada a1c']],
        'Insulina' => ['Insulina', ['insulina', 'insulina basal']],
        'Colesterol total' => ['Perfil lipídico', ['colesterol total', 'colesterol']],
        'HDL' => ['Perfil lipídico', ['hdl', 'colesterol hdl', 'hdl colesterol', 'hdl c']],
        'LDL' => ['Perfil lipídico', ['ldl', 'colesterol ldl', 'ldl colesterol', 'ldl c', 'ldl calculado', 'colesterol ldl calculado']],
        'VLDL' => ['Perfil lipídico', ['vldl', 'colesterol vldl', 'vldl colesterol']],
        'Colesterol não HDL' => ['Perfil lipídico', ['colesterol nao hdl', 'nao hdl', 'nao hdl colesterol']],
        'Triglicerídeos' => ['Perfil lipídico', ['triglicerideos', 'triglicerides', 'triglicerideo', 'triglicerides sericos']],
        'TSH' => ['TSH', ['tsh', 'tsh ultrassensivel', 'hormonio tireoestimulante', 'hormonio tireoestimulante tsh']],
        'T4 livre' => ['T4 livre', ['t4 livre', 'tiroxina livre', 't4l']],
        'T3' => ['T3', ['t3', 't3 total', 'triiodotironina']],
        'Creatinina' => ['Creatinina', ['creatinina', 'creatinina serica']],
        'Ureia' => ['Ureia', ['ureia', 'ureia serica']],
        'Ácido úrico' => ['Ácido úrico', ['acido urico']],
        'TGO (AST)' => ['TGO (AST)', ['tgo', 'ast', 'tgo ast', 'ast tgo', 'aspartato aminotransferase', 'transaminase oxalacetica tgo']],
        'TGP (ALT)' => ['TGP (ALT)', ['tgp', 'alt', 'tgp alt', 'alt tgp', 'alanina aminotransferase', 'transaminase piruvica tgp']],
        'Gama GT' => ['Gama GT', ['gama gt', 'ggt', 'gama glutamil transferase', 'gama glutamiltransferase']],
        'Fosfatase alcalina' => ['Fosfatase alcalina', ['fosfatase alcalina']],
        'Sódio' => ['Sódio', ['sodio']],
        'Potássio' => ['Potássio', ['potassio']],
        'Cálcio' => ['Cálcio', ['calcio', 'calcio total']],
        'Magnésio' => ['Magnésio', ['magnesio']],
        'Vitamina D' => ['Vitamina D (25-hidroxi)', ['vitamina d', '25 hidroxivitamina d', 'vitamina d 25 hidroxi', 'vitamina d 25 oh', '25 oh vitamina d', '25 hidroxi vitamina d']],
        'Vitamina B12' => ['Vitamina B12', ['vitamina b12', 'b12']],
        'Ácido fólico' => ['Ácido fólico', ['acido folico', 'folato']],
        'Ferritina' => ['Ferritina', ['ferritina']],
        'Ferro sérico' => ['Ferro sérico', ['ferro', 'ferro serico']],
        'PCR ultrassensível' => ['PCR ultrassensível', ['pcr ultrassensivel', 'proteina c reativa ultrassensivel', 'pcr us']],
        'VHS' => ['VHS', ['vhs']],
        'PSA total' => ['PSA total', ['psa', 'psa total']],
        'PSA livre' => ['PSA total', ['psa livre']],
        'Testosterona total' => ['Testosterona total', ['testosterona', 'testosterona total']],
        'Estradiol' => ['Estradiol', ['estradiol']],
    ];

    /** Sem acento, sem pontuação, em minúsculas e com um espaço só: a forma em que os nomes são comparados. */
    public static function normalize(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(Str::ascii($texto))));
    }

    /**
     * Os exames de um tipo (ou de todos, quando há busca), já filtrados.
     *
     * @return list<array{name: string, hint: ?string, category: string}>
     */
    public static function search(string $categoria, string $busca = ''): array
    {
        $q = self::normalize($busca);
        $tipos = $q === '' ? [isset(self::EXAMS[$categoria]) ? $categoria : 'blood'] : array_keys(self::EXAMS);
        $achados = [];

        foreach ($tipos as $tipo) {
            foreach (self::EXAMS[$tipo] as [$nome, $apelidos, $dica]) {
                if ($q === '' || str_contains(self::normalize($nome.' '.implode(' ', $apelidos)), $q)) {
                    $achados[] = ['name' => $nome, 'hint' => $dica, 'category' => $tipo];
                }
            }
        }

        return $achados;
    }

    /** O nome do catálogo para um texto, pelo nome ou por um nome alternativo que só pertença a um exame. */
    public static function examFor(string $texto): ?string
    {
        static $indice = null;

        if ($indice === null) {
            $indice = [];
            $repetidos = [];

            foreach (self::EXAMS as $lista) {
                foreach ($lista as [$nome, $apelidos]) {
                    $indice[self::normalize($nome)] = $nome;

                    foreach ($apelidos as $apelido) {
                        $chave = self::normalize($apelido);

                        if (isset($indice[$chave]) && $indice[$chave] !== $nome) {
                            $repetidos[$chave] = true;
                        }

                        $indice[$chave] ??= $nome;
                    }
                }
            }

            // "tireoide" vale para vários exames: não serve para dizer qual deles é.
            $indice = array_diff_key($indice, $repetidos);
        }

        return $indice[self::normalize($texto)] ?? null;
    }

    /** A área de um exame do catálogo (pelo nome ou por um nome alternativo); nulo para o que a pessoa digitou. */
    public static function categoryOf(string $nome): ?string
    {
        $oficial = self::examFor($nome);

        if ($oficial === null) {
            return null;
        }

        foreach (self::EXAMS as $tipo => $lista) {
            foreach ($lista as [$exame]) {
                if ($exame === $oficial) {
                    return $tipo;
                }
            }
        }

        return null;
    }

    public static function isConsultation(string $nome): bool
    {
        return str_starts_with(self::normalize($nome), 'consulta') || str_starts_with(self::normalize($nome), 'retorno');
    }

    /** @return array{name: string, exam: string}|null o marcador do catálogo para o nome impresso no laudo */
    public static function markerFor(string $texto): ?array
    {
        static $indice = null;

        if ($indice === null) {
            $indice = [];

            foreach (self::MARKERS as $nome => [$exame, $apelidos]) {
                foreach ([$nome, ...$apelidos] as $apelido) {
                    $indice[self::normalize($apelido)] = ['name' => $nome, 'exam' => $exame];
                }
            }
        }

        return $indice[self::normalize($texto)] ?? null;
    }

    /** O que junta o mesmo marcador entre laudos diferentes. */
    public static function markerKey(string $nome): string
    {
        return self::normalize(self::markerFor($nome)['name'] ?? $nome);
    }

    /** Ordem em que os marcadores conhecidos aparecem na comparação (os outros vão para o fim, em ordem alfabética). */
    public static function markerOrder(string $nome): int
    {
        $posicao = array_search(self::markerFor($nome)['name'] ?? null, array_keys(self::MARKERS), true);

        return $posicao === false ? PHP_INT_MAX : $posicao;
    }
}
