<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Macroaree, tipi di area e ambiti degli eventi (costanti SEZIONI_PORTALE, TIPI_AREA, AMBITI_EVENTO di inc/sezioni.php,
 * che restano costanti globali perché le usano anche le pagine). Un'area è una riga di pagine_eventi.
 */
final class Sezioni
{
    /** @param array<string, mixed>|null $pagina tipo dell'area ('' se non assegnato) */
    public static function tipoArea(?array $pagina): string
    {
        $t = (string) ($pagina['tipo_area'] ?? '');

        return isset(TIPI_AREA[$t]) ? $t : '';
    }

    /** @param array<string, mixed>|null $pagina macroarea dell'area ('' se il tipo non è assegnato) */
    public static function sezioneArea(?array $pagina): string
    {
        $t = self::tipoArea($pagina);

        return $t !== '' ? TIPI_AREA[$t]['sezione'] : '';
    }

    /**
     * Aree divise per macroarea, nell'ordine di SEZIONI_PORTALE; in fondo ('') quelle non assegnate.
     * L'ordine delle aree dentro ogni macroarea resta quello ricevuto.
     *
     * @param array<int|string, array<string, mixed>> $aree
     * @return array<string, list<array<string, mixed>>>
     */
    public static function raggruppaAreePerSezione(array $aree): array
    {
        $gruppi = array_fill_keys(array_keys(SEZIONI_PORTALE), []);
        $gruppi[''] = [];
        foreach ($aree as $a) {
            $gruppi[self::sezioneArea($a)][] = $a;
        }

        return array_filter($gruppi);
    }

    /** @param array<string, mixed>|null $pagina modulo dell'area: quello della macroarea; senza tipo, Orientamento */
    public static function moduloDiArea(?array $pagina): string
    {
        $s = self::sezioneArea($pagina);

        return $s !== '' ? $s : 'orientamento';
    }

    /** @param array<string, mixed>|null $pagina ambito predefinito: quello scelto nelle impostazioni, altrimenti dal tipo */
    public static function ambitoArea(?array $pagina): string
    {
        $a = (string) ($pagina['ambito'] ?? '');
        if (isset(AMBITI_EVENTO[$a])) {
            return $a;
        }

        return ['fsl' => 'orientamento', 'eventi' => 'orientamento', 'gruppi' => 'didattica', 'calendario' => 'didattica'][self::tipoArea($pagina)] ?? 'orientamento';
    }

    /**
     * Ambiti di un evento (chiavi di AMBITI_EVENTO, almeno uno).
     *
     * @param array<string, mixed> $evento
     * @param array<string, mixed>|null $pagina
     * @return list<string>
     */
    public static function ambitiEvento(array $evento, ?array $pagina = null): array
    {
        $a = array_values(array_intersect(array_keys(AMBITI_EVENTO), array_map('trim', explode(',', (string) ($evento['ambiti'] ?? '')))));

        return $a ?: [self::ambitoArea($pagina ?? $evento)];
    }

    /**
     * Valore da salvare in eventi.ambiti dalle caselle del modulo ($post['ambiti'][]); '' = come l'area.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $pagina
     */
    public static function ambitiDaPost(array $post, ?array $pagina = null): string
    {
        $a = array_values(array_intersect(array_keys(AMBITI_EVENTO), array_map('strval', (array) ($post['ambiti'] ?? []))));

        return $a === [self::ambitoArea($pagina)] ? '' : implode(',', $a);
    }
}
