<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * Sedute dei consigli: dati, presenze dei componenti (presente / assente giustificato / ingiustificato) e chi può vedere le pratiche
 * portate in seduta. Ogni componente è segnato una volta sola per seduta; nome e qualifica restano nella seduta anche se il componente cambia dopo.
 */
final class ServizioSedute
{
    public function __construct(private SedutaRepository $sedute, private ConsiglioRepository $consigli, private ServizioConsigli $servizioConsigli, private ServizioUffici $ufficio)
    {
    }

    /** @return array<string, mixed>|null */
    public function seduta(int $id): ?array
    {
        return $this->sedute->perId($id);
    }

    /**
     * «gg/mm/aaaa – Organo» per gli elenchi.
     *
     * @param array<string, mixed> $s
     */
    public static function etichetta(array $s): string
    {
        return ($s['data'] ? date('d/m/Y', strtotime($s['data'])) . ' – ' : '') . mb_strimwidth((string) $s['organo'], 0, 90, '…');
    }

    /**
     * Presenze della seduta: quelle salvate più i componenti del consiglio non ancora segnati (presenti). [componente_id => riga]
     *
     * @param array<string, mixed> $s
     * @return array<int, array<string, mixed>>
     */
    public function presenze(array $s): array
    {
        $out = [];
        foreach ($this->sedute->presenze((int) $s['id']) as $x) {
            $out[(int) $x['componente_id']] = $x + ['_salvata' => true];
        }
        if (!empty($s['consiglio_id'])) {
            foreach ($this->consigli->persone((int) $s['consiglio_id']) as $i => $c) {
                if (!isset($out[(int) $c['id']])) {
                    $out[(int) $c['id']] = ['seduta_id' => $s['id'], 'componente_id' => $c['id'], 'nominativo' => $c['nominativo'], 'qualifica' => $c['qualifica'], 'ordine' => 1000 + $i, 'stato' => 'P', '_salvata' => false];
                } else {
                    $out[(int) $c['id']]['ordine'] = $i;
                }
            }
        }
        $ord = array_flip(Costanti::QUALIFICHE_CONSIGLIO);
        uasort($out, fn ($a, $b) => ($ord[$a['qualifica']] ?? 50) <=> ($ord[$b['qualifica']] ?? 50) ?: strcmp((string) $a['qualifica'], (string) $b['qualifica']) ?: (int) $a['ordine'] <=> (int) $b['ordine']);

        return $out;
    }

    /**
     * Presenze per il verbale e il riepilogo: se ne è stata salvata almeno una (anche una giustificazione arrivata con la
     * convocazione), valgono tutti i componenti, con quelli non segnati proposti presenti; altrimenti nessuna.
     *
     * @param array<string, mixed> $s
     * @return array<int, array<string, mixed>>
     */
    public function registrate(array $s): array
    {
        $tutte = !empty($s['id']) ? $this->presenze($s) : [];

        return array_filter($tutte, fn ($r) => !empty($r['_salvata'])) ? $tutte : [];
    }

    /**
     * Salva gli stati (P / AG / AI) per componente. Ritorna quanti componenti ha salvato.
     *
     * @param array<string, mixed> $s
     * @param array<int|string, string> $stati
     */
    public function salvaPresenze(array $s, array $stati): int
    {
        $n = 0;
        $i = 0;
        foreach ($this->presenze($s) as $cid => $x) {
            $stato = isset(Costanti::STATI_PRESENZA[$stati[$cid] ?? '']) ? $stati[$cid] : $x['stato'];
            $ord = $i++;
            if ($this->sedute->segnaPresenza((int) $s['id'], $cid, $x['nominativo'], $x['qualifica'], $ord, $stato)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Quanti presenti, assenti giustificati e ingiustificati.
     *
     * @param array<int, array<string, mixed>> $presenze
     * @return array<string, int>
     */
    public static function riepilogo(array $presenze): array
    {
        $n = ['P' => 0, 'AG' => 0, 'AI' => 0];
        foreach ($presenze as $x) {
            $n[$x['stato']] = ($n[$x['stato']] ?? 0) + 1;
        }

        return $n;
    }

    /**
     * Chi gestisce la Didattica vede tutte le pratiche; il referente di un consiglio quelle portate nelle sedute del suo consiglio.
     *
     * @param array<string, mixed>|null $u
     * @param array<string, mixed> $p
     */
    public function utenteVedePratica(?array $u, array $p): bool
    {
        if ($this->ufficio->gestisce($u)) {
            return true;
        }
        if (empty($p['seduta_id']) || !($cons = $this->servizioConsigli->referenteDi($u))) {
            return false;
        }
        $s = $this->sedute->perId((int) $p['seduta_id']);

        return $s && in_array((int) $s['consiglio_id'], $cons, true);
    }

    /**
     * I campi della seduta dal modulo del pannello, ripuliti: organo, anno, data, ore, luogo, o.d.g., presenze scritte, segretario, coordinatore e consiglio
     * (null se non esiste tra $consigli).
     *
     * @param array<string, mixed> $post
     * @param array<int, mixed> $consigli i consigli noti (id => riga)
     * @return array<string, mixed>
     */
    public function campiDaModulo(array $post, array $consigli): array
    {
        $f = [];
        foreach (['organo' => 500, 'anno_accademico' => 20, 'luogo' => 255, 'segretario' => 200, 'coordinatore' => 200, 'odg' => 5000, 'presenze' => 20000] as $k => $max) {
            $f[$k] = mb_substr(trim((string) ($post[$k] ?? '')), 0, $max);
        }
        $f['data'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($post['data'] ?? '')) ? $post['data'] : null;
        $f['ora_inizio'] = preg_match('/^\d{2}:\d{2}$/', (string) ($post['ora_inizio'] ?? '')) ? $post['ora_inizio'] : '';
        $f['ora_fine'] = preg_match('/^\d{2}:\d{2}$/', (string) ($post['ora_fine'] ?? '')) ? $post['ora_fine'] : '';
        $cons = (int) ($post['consiglio_id'] ?? 0) ?: null;
        if ($cons && !isset($consigli[$cons])) {
            $cons = null;
        }
        $f['consiglio_id'] = $cons;

        return $f;
    }

    /**
     * Crea (id = 0) o aggiorna la seduta. Ritorna l'id.
     *
     * @param array<string, mixed> $campi
     */
    public function salva(int $id, array $campi): int
    {
        return $this->sedute->salva($id, $campi);
    }

    /** Elimina la seduta (le pratiche restano, senza seduta). */
    public function elimina(int $id): void
    {
        $this->sedute->elimina($id);
    }
}
