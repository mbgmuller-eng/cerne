<?php

namespace App\Support;

/**
 * Links para abrir um endereço no Google Maps ou no Waze. São links comuns de
 * navegação: no celular abrem o aplicativo, no computador abrem o site.
 *
 * O endereço só sai do Cerne para o Google ou o Waze quando a pessoa toca no link;
 * a página em si não carrega mapa, imagem nem script de terceiros.
 */
final class MapLinks
{
    /** Junta estabelecimento e endereço no que se digitaria na busca do mapa. */
    public static function destination(?string $estabelecimento, ?string $endereco): ?string
    {
        $destino = implode(', ', array_filter([trim((string) $estabelecimento), trim((string) $endereco)], fn (string $parte) => $parte !== ''));

        return $destino === '' ? null : $destino;
    }

    public static function googleMaps(string $destino): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($destino);
    }

    public static function waze(string $destino): string
    {
        return 'https://waze.com/ul?q='.rawurlencode($destino).'&navigate=yes';
    }
}
