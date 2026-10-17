<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * «Cerca ovunque» nel pannello: una sola casella per trovare pratiche, persone, scuole, verbali, aziende con convenzione di tesi e risorse.
 * Ogni gruppo compare solo se chi cerca ha i permessi per quella parte del pannello (il chiamante li indica in $permessi).
 */
final class RicercaPannello
{
    public const MINIMO = 2;
    private const PER_GRUPPO = 8;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array{pratiche?: bool, persone?: bool, scuole?: bool, verbali?: bool, convenzioni_tesi?: bool, risorse?: list<int>|bool} $permessi
     *   risorse: true = tutte, oppure l'elenco delle aree (pagine) di cui si è gestori
     * @return list<array{id: string, titolo: string, icona: string, totale: int, voci: list<array{titolo: string, sotto: string, url: string}>}>
     */
    public function cerca(string $testo, array $permessi, int $areaCorrente = 0): array
    {
        $parole = self::parole($testo);
        if ($parole === []) {
            return [];
        }
        $gruppi = [];
        $aggiungi = function (string $id, string $titolo, string $icona, array $r) use (&$gruppi): void {
            if ($r['totale'] > 0) {
                $gruppi[] = ['id' => $id, 'titolo' => $titolo, 'icona' => $icona] + $r;
            }
        };
        if (!empty($permessi['pratiche'])) {
            $aggiungi('pratiche', 'Pratiche degli studenti', 'fa-inbox', $this->pratiche($parole, $areaCorrente));
        }
        if (!empty($permessi['persone'])) {
            $aggiungi('persone', 'Persone (anagrafe di Ateneo)', 'fa-user', $this->persone($parole));
        }
        if (!empty($permessi['verbali'])) {
            $aggiungi('verbali', 'Sedute e verbali', 'fa-file-signature', $this->verbali($parole, $areaCorrente));
        }
        if (!empty($permessi['convenzioni_tesi'])) {
            $aggiungi('convenzioni_tesi', 'Aziende con convenzione di tesi', 'fa-handshake', $this->convenzioniTesi($parole, $areaCorrente));
        }
        if (!empty($permessi['scuole'])) {
            $aggiungi('scuole', 'Scuole', 'fa-school', $this->scuole($parole, $areaCorrente));
        }
        if (!empty($permessi['risorse'])) {
            $aggiungi('risorse', 'Risorse prenotabili', 'fa-door-open', $this->risorse($parole, $permessi['risorse'], $areaCorrente));
        }

        return $gruppi;
    }

    /** @return list<string> le parole (almeno due lettere), al massimo cinque */
    public static function parole(string $testo): array
    {
        $testo = mb_substr(trim($testo), 0, 100);
        $out = [];
        foreach (preg_split('/\s+/u', $testo) ?: [] as $p) {
            if (mb_strlen($p) >= self::MINIMO) {
                $out[] = $p;
            }
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Condizione «ogni parola compare in almeno una delle colonne» con i relativi parametri.
     *
     * @param list<string> $parole
     * @param list<string> $colonne
     * @return array{0: string, 1: list<string>}
     */
    private function condizione(array $parole, array $colonne): array
    {
        $parti = [];
        $par = [];
        foreach ($parole as $p) {
            $uno = [];
            foreach ($colonne as $c) {
                $uno[] = "$c LIKE ?";
                $par[] = '%' . addcslashes($p, '%_\\') . '%';
            }
            $parti[] = '(' . implode(' OR ', $uno) . ')';
        }

        return [implode(' AND ', $parti), $par];
    }

    /** @return array{totale: int, voci: list<array{titolo: string, sotto: string, url: string}>} */
    private function eseguiGruppo(string $da, string $cond, array $par, string $colonne, string $ordine, callable $voce): array
    {
        $totale = (int) $this->db->valore("SELECT COUNT(*) FROM $da WHERE $cond", $par);
        $voci = [];
        foreach ($this->db->righe("SELECT $colonne FROM $da WHERE $cond ORDER BY $ordine LIMIT " . self::PER_GRUPPO, $par) as $r) {
            $voci[] = $voce($r);
        }

        return ['totale' => $totale, 'voci' => $voci];
    }

    private static function dataIt(?string $d): string
    {
        return $d !== null && $d !== '' && strtotime($d) ? date('d/m/Y', (int) strtotime($d)) : '';
    }

    /** @param list<string> $parole */
    private function pratiche(array $parole, int $area): array
    {
        [$cond, $par] = $this->condizione($parole, ['p.nome', 'p.cognome', 'p.email', 'p.codice', 'p.matricola', "CONCAT(p.nome, ' ', p.cognome)", "CONCAT(p.cognome, ' ', p.nome)"]);

        return $this->eseguiGruppo(
            'pratiche p LEFT JOIN didattica_moduli m ON m.id = p.modulo_id',
            "p.stato <> 'bozza' AND ($cond)",
            $par,
            'p.id, p.nome, p.cognome, p.codice, p.matricola, p.stato, p.creata_il, m.titolo AS modulo',
            'p.id DESC',
            static fn (array $r): array => [
                'titolo' => trim($r['cognome'] . ' ' . $r['nome']) . ' · ' . $r['codice'],
                'sotto' => trim(($r['modulo'] ?? '') . ' · ' . $r['stato'] . ($r['matricola'] ? ' · matr. ' . $r['matricola'] : '') . ' · ' . self::dataIt($r['creata_il']), ' ·'),
                'url' => 'didattica.php?p_id=' . $area . '&tab=pratiche&id=' . (int) $r['id'],
            ],
        );
    }

    /** @param list<string> $parole */
    private function persone(array $parole): array
    {
        [$cond, $par] = $this->condizione($parole, ['cognome', 'nome', 'email', "CONCAT(nome, ' ', cognome)", 'ufficio', 'struttura']);

        return $this->eseguiGruppo(
            'personale_ateneo',
            "attivo = 1 AND ($cond)",
            $par,
            'id, cognome, nome, email, ruolo, struttura, gruppo',
            'cognome, nome',
            static fn (array $r): array => [
                'titolo' => trim($r['cognome'] . ' ' . $r['nome']),
                'sotto' => trim($r['ruolo'] . ' · ' . $r['email'] . ' · ' . $r['struttura'], ' ·'),
                'url' => 'anagrafe_personale.php?vista=' . rawurlencode((string) $r['gruppo']) . '&q=' . rawurlencode(trim($r['cognome'] . ' ' . $r['nome'])),
            ],
        );
    }

    /** @param list<string> $parole */
    private function verbali(array $parole, int $area): array
    {
        [$cond, $par] = $this->condizione($parole, ['organo', 'odg', 'testo_ricerca', 'numero_verbale', 'segretario', 'coordinatore']);

        return $this->eseguiGruppo(
            'didattica_sedute',
            $cond,
            $par,
            'id, organo, data, numero_verbale, origine',
            'data DESC, id DESC',
            static fn (array $r): array => [
                'titolo' => mb_strimwidth((string) $r['organo'], 0, 110, '…'),
                'sotto' => trim(self::dataIt($r['data']) . ((string) $r['numero_verbale'] !== '' ? ' · verbale n. ' . $r['numero_verbale'] : '') . ($r['origine'] === 'importato' ? ' · verbale importato' : ''), ' ·'),
                'url' => 'didattica.php?p_id=' . $area . '&tab=sedute&id=' . (int) $r['id'],
            ],
        );
    }

    /** @param list<string> $parole */
    private function convenzioniTesi(array $parole, int $area): array
    {
        [$cond, $par] = $this->condizione($parole, ['azienda', 'email', 'protocollo', 'dati_json']);

        return $this->eseguiGruppo(
            'convenzioni_tesi',
            $cond,
            $par,
            'id, azienda, stato, protocollo, valida_al',
            'id DESC',
            static fn (array $r): array => [
                'titolo' => (string) $r['azienda'],
                'sotto' => trim($r['stato'] . ((string) $r['protocollo'] !== '' ? ' · prot. ' . $r['protocollo'] : '') . ($r['valida_al'] ? ' · valida fino al ' . self::dataIt($r['valida_al']) : ''), ' ·'),
                'url' => 'convenzioni_tesi.php?p_id=' . $area . '&tab=archivio&q=' . rawurlencode((string) $r['azienda']),
            ],
        );
    }

    /** @param list<string> $parole */
    private function scuole(array $parole, int $area): array
    {
        [$cond, $par] = $this->condizione($parole, ['denominazione', 'codice', 'comune', 'istituto_denominazione', 'email']);

        return $this->eseguiGruppo(
            'scuole',
            $cond,
            $par,
            'codice, denominazione, comune, provincia',
            'denominazione',
            static fn (array $r): array => [
                'titolo' => (string) $r['denominazione'],
                'sotto' => trim($r['codice'] . ' · ' . $r['comune'] . ((string) $r['provincia'] !== '' ? ' (' . $r['provincia'] . ')' : ''), ' ·'),
                'url' => 'scuole.php?p_id=' . $area,
            ],
        );
    }

    /**
     * @param list<string> $parole
     * @param list<int>|bool $aree
     */
    private function risorse(array $parole, array|bool $aree, int $area): array
    {
        [$cond, $par] = $this->condizione($parole, ['nome', 'luogo', 'descrizione', 'referente']);
        if (is_array($aree)) {
            if ($aree === []) {
                return ['totale' => 0, 'voci' => []];
            }
            $cond = '(' . $cond . ') AND pagina_id IN (' . implode(',', array_map('intval', $aree)) . ')';
        }

        return $this->eseguiGruppo(
            'risorse',
            $cond,
            $par,
            'id, pagina_id, nome, tipo, luogo, attiva',
            'nome',
            static fn (array $r): array => [
                'titolo' => (string) $r['nome'] . ((int) $r['attiva'] === 0 ? ' (non attiva)' : ''),
                'sotto' => trim($r['tipo'] . ' · ' . $r['luogo'], ' ·'),
                'url' => 'risorse.php?p_id=' . (int) $r['pagina_id'],
            ],
        );
    }
}
