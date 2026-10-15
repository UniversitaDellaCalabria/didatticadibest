<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * «Da fare per te»: tutte le cose che aspettano UNA persona (docente, responsabile, operatore, segretario…) nei vari moduli del portale, in un solo elenco per
 * l'Area personale e per il riepilogo settimanale via email: conferme degli attestati, firme richieste, lettere e registri del tutorato, schede di valutazione
 * FSL, elenchi degli studenti, prenotazioni di risorse da approvare, verbali da firmare, pratiche da lavorare. Come il cruscotto del Direttore, non scrive
 * nulla: legge quello che c'è, e se una tabella manca (installazione non aggiornata) il gruppo resta vuoto e l'errore va nel log. La persona si riconosce
 * dall'email (la stessa a cui arrivano i link).
 */
final class DaFarePersona
{
    /** Giorni dopo i quali una cosa che aspetta si segnala come ferma. */
    public const GIORNI_FERMA = 7;

    public function __construct(private Database $db)
    {
    }

    /**
     * I gruppi con almeno una cosa da fare per questa persona, nell'ordine in cui compaiono.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array{chiave: string, titolo: string, icona: string, colore: string, n: int, righe: list<array{testo: string, dettaglio: string, link: string, giorni: int}>}>
     */
    public function gruppi(?array $utente): array
    {
        $email = mb_strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email === '') {
            return [];
        }
        $out = [];
        foreach (['conferme', 'firme', 'verbali', 'tutoratoLettere', 'tutoratoRegistri', 'valutazioni', 'elenchi', 'risorse', 'pratiche'] as $metodo) {
            try {
                $g = $this->$metodo($email);
                if ($g['n'] > 0) {
                    $out[] = $g;
                }
            } catch (\Throwable $e) {
                error_log('Da fare per te (' . $metodo . '): ' . $e->getMessage());
            }
        }

        return $out;
    }

    /** @param list<array{n: int}> $gruppi */
    public static function totale(array $gruppi): int
    {
        return array_sum(array_column($gruppi, 'n'));
    }

    /**
     * Email di chi ha qualcosa che lo aspetta in uno dei moduli (per il riepilogo settimanale): un elenco largo, poi gruppi() decide chi ha davvero cose da fare.
     *
     * @return list<string>
     */
    public function candidati(): array
    {
        $fonti = [
            "SELECT email FROM pratiche_conferme WHERE stato = 'attesa'",
            "SELECT email FROM pratiche_firme WHERE stato = 'attesa'",
            "SELECT docente_email FROM tutorato_incarichi WHERE stato IN ('confermata', 'protocollata') AND docente_email <> ''",
            "SELECT email FROM prenotazioni WHERE valutazione_inviata IS NOT NULL AND valutazione_inviata >= NOW() - INTERVAL 90 DAY",
            "SELECT pr.email FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id WHERE pd.attestati = 1 AND pr.email <> ''",
            "SELECT email FROM risorse_responsabili WHERE puo_approvare = 1",
            "SELECT email FROM didattica_consigli_persone WHERE ruolo IN ('segretario', 'coordinatore')",
            "SELECT email FROM ufficio_didattica",
        ];
        $emails = [];
        foreach ($fonti as $q) {
            try {
                foreach ($this->db->righe($q) as $r) {
                    $e = mb_strtolower(trim((string) array_values($r)[0]));
                    if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                        $emails[$e] = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('Da fare per te (candidati): ' . $e->getMessage());
            }
        }

        return array_keys($emails);
    }

    /**
     * @param list<array<string, mixed>> $righe
     * @param callable(array<string, mixed>): array{testo: string, dettaglio: string, link: string, giorni: int} $riga
     * @return array{chiave: string, titolo: string, icona: string, colore: string, n: int, righe: list<array{testo: string, dettaglio: string, link: string, giorni: int}>}
     */
    private function gruppo(string $chiave, string $titolo, string $icona, string $colore, int $n, array $righe, callable $riga): array
    {
        return ['chiave' => $chiave, 'titolo' => $titolo, 'icona' => $icona, 'colore' => $colore, 'n' => $n, 'righe' => array_map($riga, $righe)];
    }

    private static function nome(array $r): string
    {
        return trim((string) ($r['cognome'] ?? '') . ' ' . (string) ($r['nome'] ?? ''));
    }

    /** Conferme richieste al docente sugli attestati degli studenti (frequenza, esame sostenuto). */
    private function conferme(string $email): array
    {
        $dove = "c.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') AND LOWER(c.email) = ?";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT c.token, DATEDIFF(NOW(), c.richiesta_il) AS gg, p.codice, p.nome, p.cognome, m.titolo FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id
            JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY c.richiesta_il LIMIT 15", [$email]);

        return $this->gruppo('conferme', 'Attestati da confermare', 'fa-user-check', '#0369a1', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ': controlla le righe che ti riguardano e conferma',
            'link' => 'conferma_attestato.php?t=' . urlencode((string) $x['token']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Documenti delle pratiche (tesi, attestati, certificati) che aspettano la firma digitale della persona. */
    private function firme(string $email): array
    {
        $dove = "f.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') AND LOWER(f.email) = ?";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT f.token, DATEDIFF(NOW(), f.richiesta_il) AS gg, p.codice, p.nome, p.cognome, m.titolo FROM pratiche_firme f JOIN pratiche p ON p.id = f.pratica_id
            JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY f.richiesta_il LIMIT 15", [$email]);

        return $this->gruppo('firme', 'Documenti da firmare', 'fa-signature', '#7c3aed', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ': firma digitale PAdES',
            'link' => 'firma_pratica.php?t=' . urlencode((string) $x['token']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Verbali dei consigli inviati alla firma, quando tocca al segretario o al coordinatore che sei. */
    private function verbali(string $email): array
    {
        $dove = "p.ruolo IN ('segretario', 'coordinatore') AND LOWER(p.email) = ? AND ((s.verbale_stato = 'segretario' AND p.ruolo = 'segretario') OR (s.verbale_stato = 'coordinatore' AND p.ruolo = 'coordinatore'))
            AND s.verbale_token IS NOT NULL AND s.verbale_token <> ''";
        $n = (int) $this->db->valore("SELECT COUNT(DISTINCT s.id) FROM didattica_sedute s JOIN didattica_consigli_persone p ON p.consiglio_id = s.consiglio_id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT DISTINCT s.id, s.organo, s.data, s.verbale_token, DATEDIFF(NOW(), COALESCE(s.verbale_inviato_il, s.data, s.creata_il)) AS gg
            FROM didattica_sedute s JOIN didattica_consigli_persone p ON p.consiglio_id = s.consiglio_id WHERE $dove ORDER BY s.data LIMIT 15", [$email]);

        return $this->gruppo('verbali', 'Verbali da firmare', 'fa-gavel', '#475569', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['organo'], 'dettaglio' => 'seduta del ' . ($x['data'] ? date('d/m/Y', (int) strtotime((string) $x['data'])) : '—'),
            'link' => 'firma_verbale.php?t=' . urlencode((string) $x['verbale_token']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Lettere di incarico dei tutor di cui sei il docente responsabile, arrivate alla tua firma. */
    private function tutoratoLettere(string $email): array
    {
        $dove = "stato = 'confermata' AND LOWER(docente_email) = ? AND token_docente IS NOT NULL AND token_docente <> ''";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT codice, nome, cognome, attivita, token_docente, DATEDIFF(NOW(), COALESCE(confermata_il, aggiornata_il, creata_il)) AS gg FROM tutorato_incarichi WHERE $dove ORDER BY confermata_il LIMIT 15", [$email]);

        return $this->gruppo('tutorato_lettere', 'Lettere di incarico da firmare (tutorato)', 'fa-user-graduate', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => 'Tutor ' . self::nome($x), 'dettaglio' => 'incarico ' . $x['codice'] . ($x['attivita'] ? ': ' . mb_substr((string) $x['attivita'], 0, 80) : ''),
            'link' => 'firma_incarico.php?t=' . urlencode((string) $x['token_docente']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Registri del tutorato con attività da approvare e dichiarazioni di fine attività da confermare o firmare, per i tuoi tutor. */
    private function tutoratoRegistri(string $email): array
    {
        $dove = "LOWER(i.docente_email) = ? AND (i.fine_stato IN ('richiesta', 'da_firmare') OR EXISTS (SELECT 1 FROM tutorato_registro r WHERE r.incarico_id = i.id AND r.stato = 'inviata'))";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi i WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT i.id, i.codice, i.nome, i.cognome, i.fine_stato,
                (SELECT COUNT(*) FROM tutorato_registro r WHERE r.incarico_id = i.id AND r.stato = 'inviata') AS da_approvare,
                COALESCE((SELECT DATEDIFF(NOW(), MIN(r.creata_il)) FROM tutorato_registro r WHERE r.incarico_id = i.id AND r.stato = 'inviata'), 0) AS gg
            FROM tutorato_incarichi i WHERE $dove ORDER BY i.id LIMIT 15", [$email]);

        return $this->gruppo('tutorato_registri', 'Registri del tutorato da approvare', 'fa-book', '#b45309', $n, $r, function (array $x): array {
            $cose = [];
            if ((int) $x['da_approvare'] > 0) {
                $cose[] = (int) $x['da_approvare'] === 1 ? '1 attività da approvare' : $x['da_approvare'] . ' attività da approvare';
            }
            if ($x['fine_stato'] === 'richiesta') {
                $cose[] = 'il tutor dichiara concluse le attività: conferma';
            } elseif ($x['fine_stato'] === 'da_firmare') {
                $cose[] = 'dichiarazione di fine attività da firmare';
            }

            return ['testo' => 'Tutor ' . self::nome($x), 'dettaglio' => 'incarico ' . $x['codice'] . ': ' . implode(' · ', $cose), 'link' => 'registro_tutorato.php?id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg'])];
        });
    }

    /** Schede di valutazione della struttura ospitante (FSL) a cui hai ricevuto l'invito e non hai ancora risposto. */
    private function valutazioni(string $email): array
    {
        $dove = "pr.valutazione_inviata IS NOT NULL AND pr.valutazione_inviata >= NOW() - INTERVAL 90 DAY AND v.id IS NULL AND IFNULL(pr.stato, 'confermata') = 'confermata'
            AND pr.valutazione_token IS NOT NULL AND pr.valutazione_token <> '' AND LOWER(pr.email) = ?";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM prenotazioni pr LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT pr.valutazione_token, e.titolo, DATEDIFF(NOW(), pr.valutazione_inviata) AS gg FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id
            LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id WHERE $dove ORDER BY pr.valutazione_inviata LIMIT 15", [$email]);

        return $this->gruppo('valutazioni', 'Schede di valutazione FSL da compilare', 'fa-star', '#0f766e', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'], 'dettaglio' => 'scheda di valutazione della struttura ospitante (Formazione Scuola Lavoro)',
            'link' => 'valutazione_fsl.php?t=' . urlencode((string) $x['valutazione_token']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Elenchi degli studenti da inserire per ricevere gli attestati (attività finite da poco o in chiusura). */
    private function elenchi(string $email): array
    {
        $dove = "pd.attestati = 1 AND e.archiviato = 0 AND IFNULL(pr.stato, 'confermata') = 'confermata' AND LOWER(pr.email) = ?
            AND ((e.tipo = 'progetto' AND pd.per_scuole = 1 AND pd.data_fine BETWEEN CURDATE() - INTERVAL 60 DAY AND CURDATE() + INTERVAL 7 DAY)
              OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND t.data_turno BETWEEN CURDATE() - INTERVAL 60 DAY AND CURDATE() + INTERVAL 3 DAY))
            AND NOT EXISTS (SELECT 1 FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id)";
        $da = "FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id JOIN progetti_dettagli pd ON pd.evento_id = e.id";
        $n = (int) $this->db->valore("SELECT COUNT(*) $da WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT pr.codice_prenotazione, e.titolo, e.tipo, pd.data_fine, t.data_turno, DATEDIFF(NOW(), COALESCE(IF(e.tipo = 'progetto', pd.data_fine, t.data_turno), t.data_turno)) AS gg
            $da WHERE $dove ORDER BY t.data_turno LIMIT 15", [$email]);

        return $this->gruppo('elenchi', 'Elenchi degli studenti da inserire', 'fa-list-check', '#047857', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'], 'dettaglio' => 'senza l\'elenco i tuoi studenti non ricevono gli attestati di partecipazione',
            'link' => 'elenco_studenti.php?code=' . urlencode((string) $x['codice_prenotazione']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Prenotazioni di aule, laboratori e mezzi da approvare, per le risorse di cui sei responsabile. */
    private function risorse(string $email): array
    {
        $dove = "pr.stato = 'da_approvare' AND pr.fine >= NOW() AND LOWER(rr.email) = ? AND rr.puo_approvare = 1";
        $da = "FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id JOIN risorse_responsabili rr ON rr.risorsa_id = r.id";
        $n = (int) $this->db->valore("SELECT COUNT(DISTINCT pr.id) $da WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT DISTINCT pr.id, pr.inizio, pr.nome, pr.cognome, r.nome AS risorsa, DATEDIFF(NOW(), pr.creata_il) AS gg $da WHERE $dove ORDER BY pr.inizio LIMIT 15", [$email]);

        return $this->gruppo('risorse', 'Prenotazioni di risorse da approvare', 'fa-door-open', '#0f766e', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['risorsa'] . ' · ' . self::nome($x), 'dettaglio' => 'per il ' . date('d/m/Y H:i', (int) strtotime((string) $x['inizio'])),
            'link' => 'risorse_responsabile.php', 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Pratiche degli studenti in carico alla persona (operatore dell'Ufficio didattico) o da prendere in carico nel suo ufficio. */
    private function pratiche(string $email): array
    {
        $op = $this->db->riga('SELECT id, ufficio_id FROM ufficio_didattica WHERE LOWER(email) = ? LIMIT 1', [$email]);
        if (!$op) {
            return $this->gruppo('pratiche', 'Pratiche da lavorare', 'fa-inbox', '#047857', 0, [], static fn (array $x): array => []);
        }
        $dove = "p.stato NOT IN ('bozza', 'accolta', 'respinta', 'chiusa', 'integrazione') AND NOT EXISTS (SELECT 1 FROM pratiche_firme f WHERE f.pratica_id = p.id AND f.stato = 'attesa')
            AND NOT EXISTS (SELECT 1 FROM pratiche_conferme c WHERE c.pratica_id = p.id AND c.stato = 'attesa')
            AND (p.assegnata_a = " . (int) $op['id'] . ($op['ufficio_id'] ? ' OR (p.assegnata_a IS NULL AND p.ufficio_id = ' . (int) $op['ufficio_id'] . ')' : '') . ')';
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche p WHERE $dove");
        $r = $this->db->righe("SELECT p.id, p.codice, p.nome, p.cognome, p.assegnata_a, m.titolo, DATEDIFF(NOW(), COALESCE(p.aggiornata_il, p.creata_il)) AS gg
            FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY COALESCE(p.aggiornata_il, p.creata_il) LIMIT 15");

        return $this->gruppo('pratiche', 'Pratiche da lavorare', 'fa-inbox', '#047857', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ($x['assegnata_a'] ? ': in carico a te' : ': del tuo ufficio, da prendere in carico'),
            'link' => 'admin/didattica.php?tab=pratiche&id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg']),
        ]);
    }
}
