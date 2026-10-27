<?php

declare(strict_types=1);

namespace App\Portale\Area;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * I riquadri dell'area personale (tabella area_riquadri): quelli di sistema (chiave del catalogo) e quelli scritti dall'amministratore (chiave vuota).
 * Gli orari arrivano dal chiamante, mai da NOW().
 */
final class RiquadriRepository
{
    public const MAX_TITOLO = 120;
    public const MAX_TESTO = 1000;

    public function __construct(private Database $db)
    {
    }

    /** Aggiunge i riquadri di sistema che mancano (quelli già presenti, anche spenti o cambiati, non si toccano). @return int quanti ne ha aggiunti */
    public function semina(string $quando): int
    {
        $nuovi = 0;
        foreach (CatalogoRiquadri::sistema() as $chiave => $v) {
            if ((int) $this->db->valore('SELECT COUNT(*) FROM area_riquadri WHERE chiave = ?', [$chiave]) > 0) {
                continue;
            }
            $scheda = $v['tipo'] === CatalogoRiquadri::SCHEDA;
            $this->db->esegui(
                'INSERT IGNORE INTO area_riquadri (chiave, titolo, testo, icona, colore, link, etichetta_link, link2, etichetta_link2, profili, ordine, attivo, creato_il)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$chiave, $scheda ? $v['titolo'] : '', $scheda ? $v['testo'] : '', $v['icona'], $v['colore'], $scheda ? $v['link'] : '', $scheda ? $v['etichetta_link'] : '',
                    $scheda ? $v['link2'] : '', $scheda ? $v['etichetta_link2'] : '', Profili::inCsv($v['profili']), (int) $v['ordine'], (int) ($v['attivo'] ?? 1), $quando]
            );
            ++$nuovi;
        }

        return $nuovi;
    }

    /**
     * Tutti i riquadri, in ordine.
     *
     * @return list<array<string, mixed>>
     */
    public function tutti(): array
    {
        return array_map(self::risolvi(...), Righe::testo($this->db->righe('SELECT * FROM area_riquadri ORDER BY ordine, id')));
    }

    /**
     * I riquadri accesi che almeno uno di questi profili deve vedere, in ordine (senza ripetizioni: ogni riquadro è una riga sola).
     *
     * @param list<string> $profili
     * @return list<array<string, mixed>>
     */
    public function perProfili(array $profili): array
    {
        return array_values(array_filter($this->tutti(), static fn (array $r): bool => $r['attivo'] && array_intersect($r['profili'], $profili) !== []));
    }

    /**
     * I riquadri di un profilo (anche spenti), per il pannello.
     *
     * @return list<array<string, mixed>>
     */
    public function perProfilo(string $profilo): array
    {
        return array_values(array_filter($this->tutti(), static fn (array $r): bool => in_array($profilo, $r['profili'], true)));
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        $r = Righe::riga($this->db->riga('SELECT * FROM area_riquadri WHERE id = ?', [$id]));

        return $r ? self::risolvi($r) : null;
    }

    /**
     * Una riga con i valori pronti: il titolo e le altre cose di serie al posto dei campi vuoti dei riquadri di sistema.
     *
     * @param array<string, string|null> $r
     * @return array<string, mixed>
     */
    public static function risolvi(array $r): array
    {
        $chiave = (string) ($r['chiave'] ?? '');
        $cat = $chiave !== '' ? CatalogoRiquadri::voce($chiave) : null;

        return [
            'id' => (int) $r['id'], 'chiave' => $chiave, 'sistema' => $chiave !== '',
            'tipo' => $chiave === '' ? 'personalizzato' : ($cat['tipo'] ?? CatalogoRiquadri::SPECIALE),
            'titolo' => trim((string) $r['titolo']) !== '' ? (string) $r['titolo'] : (string) ($cat['titolo'] ?? 'Riquadro'),
            'descrizione' => (string) ($cat['descrizione'] ?? ''),
            'testo' => (string) ($r['testo'] ?? ''),
            'icona' => self::iconaValida((string) $r['icona']) ? (string) $r['icona'] : 'fa-circle-info',
            'colore' => self::coloreValido((string) $r['colore']) ? (string) $r['colore'] : '#0056B3',
            'link' => (string) $r['link'], 'etichetta_link' => (string) $r['etichetta_link'], 'link2' => (string) $r['link2'], 'etichetta_link2' => (string) $r['etichetta_link2'],
            'profili' => Profili::da((string) $r['profili']), 'ordine' => (int) $r['ordine'], 'attivo' => (int) $r['attivo'] === 1,
        ];
    }

    /**
     * Crea (id 0) o aggiorna un riquadro. Dei riquadri di sistema di tipo «speciale» cambiano solo profili, ordine e interruttore (e un titolo diverso);
     * delle schede anche testo, icona, colore e collegamenti; dei personalizzati tutto.
     *
     * @param array<string, mixed> $dati titolo, testo, icona, colore, link, etichetta_link, link2, etichetta_link2, profili (lista), ordine, attivo
     * @return array{0: int, 1: string|null} id ed errore
     */
    public function salva(int $id, array $dati, string $quando): array
    {
        $attuale = $id > 0 ? $this->perId($id) : null;
        if ($id > 0 && !$attuale) {
            return [0, 'Riquadro non trovato.'];
        }
        $speciale = $attuale && $attuale['tipo'] === CatalogoRiquadri::SPECIALE;
        $titolo = trim((string) preg_replace('/\s+/u', ' ', (string) ($dati['titolo'] ?? '')));
        if ($titolo === '' && !$speciale) {
            return [0, 'Scrivi il titolo del riquadro.'];
        }
        if (mb_strlen($titolo) > self::MAX_TITOLO) {
            return [0, 'Il titolo è troppo lungo (al massimo ' . self::MAX_TITOLO . ' caratteri).'];
        }
        $testo = trim(str_replace("\r\n", "\n", (string) ($dati['testo'] ?? '')));
        if (mb_strlen($testo) > self::MAX_TESTO) {
            return [0, 'Il testo è troppo lungo (al massimo ' . self::MAX_TESTO . ' caratteri).'];
        }
        $link = [];
        foreach (['link' => 'etichetta_link', 'link2' => 'etichetta_link2'] as $campo => $etichetta) {
            $u = trim((string) ($dati[$campo] ?? ''));
            if ($u !== '' && !self::urlValido($u)) {
                return [0, 'Un collegamento non è valido: usa https://… oppure una pagina del portale (per esempio pratiche.php).'];
            }
            $link[$campo] = $u;
            $link[$etichetta] = $u === '' ? '' : mb_substr(trim((string) ($dati[$etichetta] ?? '')), 0, 80);
        }
        $icona = self::iconaValida((string) ($dati['icona'] ?? '')) ? (string) $dati['icona'] : 'fa-circle-info';
        $colore = self::coloreValido((string) ($dati['colore'] ?? '')) ? (string) $dati['colore'] : '#0056B3';
        $profili = Profili::inCsv(array_map('strval', (array) ($dati['profili'] ?? [])));
        $attivo = !empty($dati['attivo']) ? 1 : 0;

        if ($id === 0) {
            $ordine = isset($dati['ordine']) && (string) $dati['ordine'] !== '' ? (int) $dati['ordine'] : (int) $this->db->valore('SELECT COALESCE(MAX(ordine), 0) FROM area_riquadri') + 10;
            $nuovo = $this->db->inserisci(
                'INSERT INTO area_riquadri (chiave, titolo, testo, icona, colore, link, etichetta_link, link2, etichetta_link2, profili, ordine, attivo, creato_il) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$titolo, $testo, $icona, $colore, $link['link'], $link['etichetta_link'], $link['link2'], $link['etichetta_link2'], $profili, $ordine, $attivo, $quando]
            );

            return [$nuovo, null];
        }
        $ordine = isset($dati['ordine']) && (string) $dati['ordine'] !== '' ? (int) $dati['ordine'] : (int) $attuale['ordine'];
        if ($speciale) {
            $this->db->esegui('UPDATE area_riquadri SET titolo = ?, profili = ?, ordine = ?, attivo = ?, aggiornato_il = ? WHERE id = ?', [$titolo, $profili, $ordine, $attivo, $quando, $id]);
        } else {
            $this->db->esegui(
                'UPDATE area_riquadri SET titolo = ?, testo = ?, icona = ?, colore = ?, link = ?, etichetta_link = ?, link2 = ?, etichetta_link2 = ?, profili = ?, ordine = ?, attivo = ?, aggiornato_il = ? WHERE id = ?',
                [$titolo, $testo, $icona, $colore, $link['link'], $link['etichetta_link'], $link['link2'], $link['etichetta_link2'], $profili, $ordine, $attivo, $quando, $id]
            );
        }

        return [$id, null];
    }

    public function imposta(int $id, bool $attivo, string $quando): void
    {
        $this->db->esegui('UPDATE area_riquadri SET attivo = ?, aggiornato_il = ? WHERE id = ?', [$attivo ? 1 : 0, $quando, $id]);
    }

    /** Sposta un riquadro su (-1) o giù (+1) nell'elenco di un profilo, scambiandolo con il vicino. */
    public function sposta(int $id, int $verso, string $profilo, string $quando): void
    {
        $elenco = $this->perProfilo($profilo);
        $pos = array_search($id, array_column($elenco, 'id'), true);
        $altro = $pos === false ? null : $pos + ($verso < 0 ? -1 : 1);
        if ($pos === false || $altro < 0 || $altro >= count($elenco)) {
            return;
        }
        // Posizioni già occupate, senza ripetizioni (se due riquadri avevano lo stesso numero si distanziano)
        $slot = array_column($elenco, 'ordine');
        sort($slot);
        foreach ($slot as $i => $s) {
            if ($i > 0 && $s <= $slot[$i - 1]) {
                $slot[$i] = $slot[$i - 1] + 1;
            }
        }
        [$elenco[$pos], $elenco[$altro]] = [$elenco[$altro], $elenco[$pos]];
        foreach ($elenco as $i => $r) {
            $this->db->esegui('UPDATE area_riquadri SET ordine = ?, aggiornato_il = ? WHERE id = ?', [$slot[$i], $quando, $r['id']]);
        }
    }

    /** Elimina un riquadro scritto dall'amministratore (quelli di sistema si spengono, non si eliminano). */
    public function elimina(int $id): bool
    {
        $r = $this->perId($id);
        if (!$r || $r['sistema']) {
            return false;
        }
        $this->db->esegui('DELETE FROM area_riquadri WHERE id = ?', [$id]);

        return true;
    }

    public static function iconaValida(string $icona): bool
    {
        return preg_match('/^fa-[a-z0-9-]{2,40}$/', $icona) === 1;
    }

    public static function coloreValido(string $colore): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $colore) === 1;
    }

    /** Un indirizzo che si può mettere in un collegamento: https://…, http://… o una pagina del portale (percorso relativo, anche con ?parametri). */
    public static function urlValido(string $u): bool
    {
        $u = trim($u);

        return $u !== '' && strlen($u) <= 500 && !preg_match('/[\s<>"\']/', $u) && (preg_match('#^https?://[^/\s]+#i', $u) === 1 || preg_match('#^[a-z0-9_][a-z0-9_\-./]*(\?[^\s]*)?(\#[^\s]*)?$#i', $u) === 1);
    }
}
