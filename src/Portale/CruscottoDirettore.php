<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;
use App\Uffici\Direzione;

/**
 * Il cruscotto del Direttore: una sola pagina con le cose che aspettano o che si sono fermate nei moduli del portale (firme, pratiche, conferme, verbali,
 * convenzioni, tutorato, risorse). Non scrive nulla e non ha tabelle sue: legge quello che c'è. Ogni gruppo è a sé: se una tabella manca (installazione
 * non ancora aggiornata) il gruppo resta vuoto e l'errore va nel log.
 *
 * Vi accedono gli amministratori, il Direttore e il vice direttore (persone della Direzione: Gestione del portale → Uffici).
 */
final class CruscottoDirettore
{
    /** Giorni dopo i quali una cosa che aspetta si considera ferma (pagamenti del tutorato, prenotazioni da approvare, conferme dei docenti). */
    public const GIORNI_FERMA = 7;

    /** Entro quanti giorni una convenzione o una scadenza di una risorsa si segnala come «in scadenza». */
    public const GIORNI_SCADENZA = 60;

    public function __construct(private Database $db, private Direzione $direzione)
    {
    }

    /**
     * Chi può vedere il cruscotto: i super amministratori, il Direttore e il vice direttore.
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoVedere(?array $utente): bool
    {
        if (!$utente) {
            return false;
        }
        if ((int) ($utente['ruolo_id'] ?? 0) === 1) {
            return true;
        }
        return $this->direzione->direttoreOVice($utente);
    }

    /**
     * Tutti i gruppi, nell'ordine in cui compaiono: prima ciò che aspetta il Direttore, poi ciò che si è fermato.
     *
     * @return list<array{chiave: string, titolo: string, icona: string, colore: string, n: int, righe: list<array{testo: string, dettaglio: string, link: string, giorni: int}>, vuoto: string, link: string}>
     */
    public function gruppi(): array
    {
        $out = [];
        foreach ([
            'firme' => 'firmeDelDirettore', 'convenzioni_firma' => 'convenzioniDaFirmare', 'tesi_firma' => 'convenzioniTesiDaFirmare', 'pratiche' => 'praticheFerme', 'conferme' => 'confermeFerme', 'verbali' => 'verbaliInFirma',
            'convenzioni' => 'convenzioniInScadenza', 'tutorato' => 'tutoratoFermo', 'risorse' => 'scadenzeRisorse', 'prenotazioni' => 'prenotazioniFerme',
        ] as $metodo) {
            try {
                $out[] = $this->$metodo();
            } catch (\Throwable $e) {
                error_log('Cruscotto del Direttore (' . $metodo . '): ' . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * @return array{totale: int, ferme: int}
     */
    public function riepilogo(): array
    {
        $tot = 0;
        $ferme = 0;
        foreach ($this->gruppi() as $g) {
            $tot += $g['n'];
            $ferme += count(array_filter($g['righe'], static fn (array $r): bool => $r['giorni'] >= self::GIORNI_FERMA));
        }

        return ['totale' => $tot, 'ferme' => $ferme];
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @param callable(array<string, mixed>): array{testo: string, dettaglio: string, link: string, giorni: int} $riga
     * @return array{chiave: string, titolo: string, icona: string, colore: string, n: int, righe: list<array{testo: string, dettaglio: string, link: string, giorni: int}>, vuoto: string, link: string}
     */
    private function gruppo(string $chiave, string $titolo, string $icona, string $colore, int $n, array $righe, callable $riga, string $vuoto, string $link = ''): array
    {
        return ['chiave' => $chiave, 'titolo' => $titolo, 'icona' => $icona, 'colore' => $colore, 'n' => $n, 'righe' => array_map($riga, $righe), 'vuoto' => $vuoto, 'link' => $link];
    }

    private static function nome(array $r): string
    {
        return trim((string) ($r['cognome'] ?? '') . ' ' . (string) ($r['nome'] ?? ''));
    }

    /** Documenti (pratiche) che aspettano la firma del Direttore: il link porta direttamente alla firma. */
    private function firmeDelDirettore(): array
    {
        $r = $this->db->righe("SELECT f.token, f.richiesta_il, DATEDIFF(NOW(), f.richiesta_il) AS gg, p.codice, p.nome, p.cognome, m.titolo
            FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id
            WHERE f.tipo = 'direttore' AND f.stato = 'attesa' ORDER BY f.richiesta_il LIMIT 20");

        return $this->gruppo('firme', 'Documenti da firmare', 'fa-signature', '#7c3aed', count($r), $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ', richiesta il ' . date('d/m/Y', (int) strtotime((string) $x['richiesta_il'])),
            'link' => 'firma_pratica.php?t=' . urlencode((string) $x['token']), 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessun documento aspetta la tua firma.');
    }

    /** Convenzioni FSL arrivate al passo della firma del Direttore. */
    private function convenzioniDaFirmare(): array
    {
        $r = $this->db->righe("SELECT c.id, c.scuola_codice, COALESCE(s.denominazione, c.scuola_codice) AS scuola, DATEDIFF(NOW(), COALESCE(c.aggiornata_il, c.creata_il)) AS gg
            FROM convenzioni_compilate c LEFT JOIN scuole s ON s.codice = c.scuola_codice WHERE c.stato_iter = 'da_firmare' ORDER BY COALESCE(c.aggiornata_il, c.creata_il) LIMIT 20");

        return $this->gruppo('convenzioni_firma', 'Convenzioni FSL da firmare', 'fa-file-signature', '#0f766e', count($r), $r, fn (array $x): array => [
            'testo' => (string) $x['scuola'], 'dettaglio' => 'convenzione con le scuole (Formazione Scuola Lavoro)', 'link' => 'admin/fsl.php', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna convenzione aspetta la firma.', 'admin/fsl.php');
    }

    /** Convenzioni di tesi con enti e aziende arrivate al passo della firma del Direttore: il link porta direttamente alla firma. */
    private function convenzioniTesiDaFirmare(): array
    {
        $r = $this->db->righe("SELECT azienda, token_direttore, DATEDIFF(NOW(), COALESCE(da_firmare_il, aggiornata_il)) AS gg FROM convenzioni_tesi WHERE stato = 'da_firmare' AND token_direttore IS NOT NULL ORDER BY da_firmare_il LIMIT 20");

        return $this->gruppo('tesi_firma', 'Convenzioni di tesi da firmare', 'fa-file-signature', '#0f766e', count($r), $r, fn (array $x): array => [
            'testo' => (string) $x['azienda'], 'dettaglio' => 'convenzione per la tesi con un ente o un\'azienda', 'link' => 'firma_convenzione_tesi.php?t=' . urlencode((string) $x['token_direttore']), 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna convenzione di tesi aspetta la firma.');
    }

    /** Pratiche aperte ferme da più dei giorni del loro modulo (promemoria). */
    private function praticheFerme(): array
    {
        $dove = "p.stato NOT IN ('bozza', 'accolta', 'respinta', 'chiusa')
            AND DATEDIFF(NOW(), COALESCE(p.aggiornata_il, p.creata_il)) >= GREATEST(m.giorni_promemoria, 1)";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove");
        $r = $this->db->righe("SELECT p.id, p.codice, p.nome, p.cognome, p.stato, m.titolo, u.nome AS ufficio, DATEDIFF(NOW(), COALESCE(p.aggiornata_il, p.creata_il)) AS gg
            FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id LEFT JOIN uffici u ON u.id = p.ufficio_id WHERE $dove
            ORDER BY COALESCE(p.aggiornata_il, p.creata_il) LIMIT 15");

        return $this->gruppo('pratiche', 'Pratiche degli studenti ferme', 'fa-inbox', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x),
            'dettaglio' => 'pratica ' . $x['codice'] . ($x['ufficio'] ? ', ferma in «' . $x['ufficio'] . '»' : ', senza ufficio'), 'link' => 'admin/didattica.php?tab=pratiche&id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna pratica è ferma oltre il tempo del suo modulo.', 'admin/didattica.php?tab=pratiche');
    }

    /** Conferme dei docenti (attestati, tesi) che aspettano da oltre una settimana. */
    private function confermeFerme(): array
    {
        $dove = "c.stato = 'attesa' AND DATEDIFF(NOW(), c.richiesta_il) >= " . self::GIORNI_FERMA;
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_conferme c WHERE $dove");
        $r = $this->db->righe("SELECT c.nome AS docente, DATEDIFF(NOW(), c.richiesta_il) AS gg, p.id, p.codice, p.nome, p.cognome, m.titolo
            FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY c.richiesta_il LIMIT 15");

        return $this->gruppo('conferme', 'Conferme dei docenti in ritardo', 'fa-user-check', '#0369a1', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['docente'] . ' non ha ancora confermato', 'dettaglio' => (string) $x['titolo'] . ' di ' . self::nome($x) . ' (pratica ' . $x['codice'] . ')',
            'link' => 'admin/didattica.php?tab=pratiche&id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg']),
        ], 'Tutti i docenti hanno risposto.');
    }

    /** Verbali inviati alla firma e non ancora firmati da tutti. */
    private function verbaliInFirma(): array
    {
        $r = $this->db->righe("SELECT id, organo, data, verbale_stato, DATEDIFF(NOW(), COALESCE(data, creata_il)) AS gg FROM didattica_sedute WHERE verbale_stato IN ('segretario', 'coordinatore') ORDER BY data LIMIT 15");

        return $this->gruppo('verbali', 'Verbali in attesa di firma', 'fa-gavel', '#475569', count($r), $r, fn (array $x): array => [
            'testo' => (string) $x['organo'], 'dettaglio' => 'seduta del ' . ($x['data'] ? date('d/m/Y', (int) strtotime((string) $x['data'])) : '—') . ', ora firma il ' . ($x['verbale_stato'] === 'segretario' ? 'segretario' : 'coordinatore'),
            'link' => 'admin/didattica.php?tab=sedute', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessun verbale aspetta una firma.', 'admin/didattica.php?tab=sedute');
    }

    /** Convenzioni con le scuole in scadenza (o scadute) senza una più recente. */
    private function convenzioniInScadenza(): array
    {
        $dove = "c.scadenza IS NOT NULL AND c.scadenza <= DATE_ADD(CURDATE(), INTERVAL " . self::GIORNI_SCADENZA . " DAY)
            AND NOT EXISTS (SELECT 1 FROM convenzioni_scuole n WHERE n.scuola_codice = c.scuola_codice AND n.scadenza > c.scadenza)";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM convenzioni_scuole c WHERE $dove");
        $r = $this->db->righe("SELECT c.scadenza, COALESCE(s.denominazione, c.scuola_codice) AS scuola, DATEDIFF(CURDATE(), c.scadenza) AS gg
            FROM convenzioni_scuole c LEFT JOIN scuole s ON s.codice = c.scuola_codice WHERE $dove ORDER BY c.scadenza LIMIT 15");

        return $this->gruppo('convenzioni', 'Convenzioni con le scuole in scadenza', 'fa-school', '#0f766e', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['scuola'], 'dettaglio' => ((int) $x['gg'] > 0 ? 'scaduta il ' : 'scade il ') . date('d/m/Y', (int) strtotime((string) $x['scadenza'])),
            'link' => 'admin/fsl.php', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna convenzione è in scadenza.', 'admin/fsl.php');
    }

    /** Pratiche di tutorato passate all'ufficio pagamenti e non ancora pagate (o rimandate). */
    private function tutoratoFermo(): array
    {
        $dove = "pagamento_stato IN ('in_ufficio', 'rimandata') AND DATEDIFF(NOW(), COALESCE(pagamento_passato_il, aggiornata_il, creata_il)) >= " . self::GIORNI_FERMA;
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi WHERE $dove");
        $r = $this->db->righe("SELECT id, codice, nome, cognome, pagamento_stato, DATEDIFF(NOW(), COALESCE(pagamento_passato_il, aggiornata_il, creata_il)) AS gg FROM tutorato_incarichi WHERE $dove ORDER BY COALESCE(pagamento_passato_il, aggiornata_il, creata_il) LIMIT 15");

        return $this->gruppo('tutorato', 'Pagamenti del tutorato fermi', 'fa-user-graduate', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => self::nome($x), 'dettaglio' => 'incarico ' . $x['codice'] . ($x['pagamento_stato'] === 'rimandata' ? ', rimandato dall\'ufficio pagamenti' : ', all\'ufficio pagamenti'),
            'link' => 'admin/tutorato.php', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessun pagamento del tutorato è fermo.', 'admin/tutorato.php');
    }

    /** Scadenze delle risorse (bollo, assicurazione, revisione…) entro il preavviso. */
    private function scadenzeRisorse(): array
    {
        $dove = "s.scade_il <= DATE_ADD(CURDATE(), INTERVAL GREATEST(s.preavviso_giorni, 1) DAY) AND r.attiva = 1";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM risorse_scadenze s JOIN risorse r ON r.id = s.risorsa_id WHERE $dove");
        $r = $this->db->righe("SELECT s.nome, s.scade_il, r.id AS risorsa, r.nome AS risorsa_nome, DATEDIFF(CURDATE(), s.scade_il) AS gg
            FROM risorse_scadenze s JOIN risorse r ON r.id = s.risorsa_id WHERE $dove ORDER BY s.scade_il LIMIT 15");

        return $this->gruppo('risorse', 'Scadenze delle risorse', 'fa-calendar-xmark', '#b91c1c', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['risorsa_nome'] . ' · ' . $x['nome'], 'dettaglio' => ((int) $x['gg'] > 0 ? 'scaduta il ' : 'scade il ') . date('d/m/Y', (int) strtotime((string) $x['scade_il'])),
            'link' => 'risorse_cruscotto.php?r=' . (int) $x['risorsa'] . '#scadenze', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna scadenza delle risorse è vicina.');
    }

    /** Prenotazioni di aule, laboratori e mezzi da approvare e ferme da oltre una settimana. */
    private function prenotazioniFerme(): array
    {
        $dove = "pr.stato = 'da_approvare' AND pr.fine >= NOW() AND DATEDIFF(NOW(), pr.creata_il) >= " . self::GIORNI_FERMA;
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM prenotazioni_risorse pr WHERE $dove");
        $r = $this->db->righe("SELECT pr.inizio, pr.nome, pr.cognome, r.nome AS risorsa, DATEDIFF(NOW(), pr.creata_il) AS gg
            FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE $dove ORDER BY pr.creata_il LIMIT 15");

        return $this->gruppo('prenotazioni', 'Prenotazioni di risorse da approvare', 'fa-door-open', '#475569', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['risorsa'] . ' · ' . self::nome($x), 'dettaglio' => 'per il ' . date('d/m/Y H:i', (int) strtotime((string) $x['inizio'])) . ', non ancora approvata',
            'link' => 'admin/prenotazioni_risorse.php', 'giorni' => max(0, (int) $x['gg']),
        ], 'Nessuna prenotazione è in attesa da troppo tempo.');
    }
}
