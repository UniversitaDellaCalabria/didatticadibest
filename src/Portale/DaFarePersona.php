<?php

declare(strict_types=1);

namespace App\Portale;

use App\ConvenzioniTesi\Costanti as CostantiTesi;
use App\Core\Database;
use App\Didattica\SostituzioniInCorso;
use App\Uffici\Direzione;

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

    /** Quante righe per gruppo si leggono in area personale (la pagina «Da fare» ne chiede di più). */
    public const RIGHE_BREVI = 15;

    private int $limite = self::RIGHE_BREVI;

    public function __construct(private Database $db, private Direzione $direzione, private SostituzioniInCorso $sostituzioni)
    {
    }

    /**
     * I gruppi con almeno una cosa da fare per questa persona, nell'ordine in cui compaiono.
     *
     * @param array<string, mixed>|null $utente
     * @param int $limite quante righe leggere al massimo per gruppo (il numero `n` è sempre il totale)
     * @return list<array{chiave: string, titolo: string, icona: string, colore: string, n: int, righe: list<array{testo: string, dettaglio: string, link: string, giorni: int}>}>
     */
    public function gruppi(?array $utente, int $limite = self::RIGHE_BREVI): array
    {
        $this->limite = max(1, min($limite, 500));
        $email = mb_strtolower(trim((string) ($utente['email'] ?? '')));
        $cf = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));
        if ($email === '' && $cf === '') {
            return [];
        }
        $out = [];
        // Le fonti per email non servono a chi non ha l'email; quelle per codice fiscale (il tutor) funzionano anche senza
        $fonti = $email !== '' ? ['convenzioniFsl' => $email, 'conferme' => $email, 'firme' => $email, 'convenzioniTesiFirma' => $email, 'convenzioniTesiUfficio' => $email, 'verbali' => $email,
            'tutoratoLettere' => $email, 'tutoratoLettereDirettore' => $email, 'convenzioniFslFirma' => $email] : [];
        if ($cf !== '') {
            $fonti['tutoratoAccettazione'] = $cf;
        }
        if ($email !== '') {
            $fonti += ['tutoratoRegistri' => $email, 'valutazioni' => $email, 'elenchi' => $email, 'risorse' => $email, 'pratiche' => $email,
                'praticheIntegrazione' => $email, 'postoLiberato' => $email];
        }
        foreach ($fonti as $metodo => $chi) {
            try {
                $g = $this->$metodo($chi);
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
            "SELECT b.direttore_email FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id WHERE i.stato = 'firmata_docente' AND b.direttore_email <> ''",
            "SELECT email FROM prenotazioni WHERE valutazione_inviata IS NOT NULL AND valutazione_inviata >= NOW() - INTERVAL 90 DAY",
            "SELECT pr.email FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id WHERE pd.attestati = 1 AND pr.email <> ''",
            "SELECT email FROM convenzioni_compilate WHERE stato_iter IN ('', 'rifiutata') AND email <> '' AND creata_il >= NOW() - INTERVAL 1 YEAR",
            "SELECT email FROM risorse_responsabili WHERE puo_approvare = 1",
            "SELECT email FROM didattica_consigli_persone WHERE ruolo IN ('segretario', 'coordinatore')",
            "SELECT email FROM uffici_persone",
            "SELECT email FROM pratiche WHERE stato = 'integrazione' AND email <> ''",
            "SELECT email FROM prenotazioni WHERE stato = 'richiesta_conferma' AND email <> ''",
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

    /** Convenzione della scuola con il Dipartimento (FSL): compilata online ma non ancora caricata firmata, o rifiutata dall'ufficio e da ricaricare. */
    private function convenzioniFsl(string $email): array
    {
        $dove = "LOWER(cc.email) = ? AND cc.stato_iter IN ('', 'rifiutata') AND cc.dati_json NOT LIKE '%\"convenzione\":\"si\"%'
            AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'da_approvare') AND e.archiviato = 0 AND cc.creata_il >= NOW() - INTERVAL 1 YEAR";
        $da = "FROM convenzioni_compilate cc JOIN prenotazioni pr ON pr.id = cc.prenotazione_id JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id";
        $n = (int) $this->db->valore("SELECT COUNT(DISTINCT cc.id) $da WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT cc.token, cc.stato_iter, cc.nota_ufficio, cc.dati_json, MIN(e.titolo) AS titolo, DATEDIFF(NOW(), COALESCE(cc.aggiornata_il, cc.creata_il)) AS gg
            $da WHERE $dove GROUP BY cc.id ORDER BY cc.creata_il LIMIT " . $this->limite, [$email]);

        return $this->gruppo('convenzioni_fsl', 'Convenzione della scuola da caricare', 'fa-file-signature', '#B30000', $n, $r, function (array $x): array {
            $dati = json_decode((string) ($x['dati_json'] ?? ''), true) ?: [];
            $scuola = trim((string) ($dati['scuola']['denominazione'] ?? ''));
            $rifiutata = (string) $x['stato_iter'] === 'rifiutata';

            return [
                'testo' => ($scuola !== '' ? $scuola . ' · ' : '') . (string) $x['titolo'],
                'dettaglio' => $rifiutata ? 'la convenzione firmata è stata rifiutata' . ((string) $x['nota_ufficio'] !== '' ? ': ' . $x['nota_ufficio'] : '') . ': ricaricala corretta' : 'scarica la convenzione, falla firmare digitalmente (PAdES) dal Dirigente e caricala qui',
                'link' => 'convenzione_online.php?t=' . urlencode((string) $x['token']) . '#firmata', 'giorni' => max(0, (int) $x['gg']),
            ];
        });
    }

    /** Conferme richieste al docente sugli attestati degli studenti (frequenza, esame sostenuto). */
    private function conferme(string $email): array
    {
        $dove = "c.stato = 'attesa' AND p.stato IN ('inviata', 'in_lavorazione') AND LOWER(c.email) = ?";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT c.token, DATEDIFF(NOW(), c.richiesta_il) AS gg, p.codice, p.nome, p.cognome, m.titolo FROM pratiche_conferme c JOIN pratiche p ON p.id = c.pratica_id
            JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY c.richiesta_il LIMIT " . $this->limite, [$email]);

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
            JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY f.richiesta_il LIMIT " . $this->limite, [$email]);

        return $this->gruppo('firme', 'Documenti da firmare', 'fa-signature', '#7c3aed', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ': firma digitale PAdES',
            'link' => 'firma_pratica.php?t=' . urlencode((string) $x['token']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Convenzioni di tesi che aspettano la firma di chi firma ora (il Direttore o, con «Direttore assente», il vice). */
    private function convenzioniTesiFirma(string $email): array
    {
        $chi = mb_strtolower(trim($this->direzione->chiFirma()['email']));
        $r = $chi !== '' && $chi === $email
            ? $this->db->righe("SELECT token_direttore, azienda, DATEDIFF(NOW(), da_firmare_il) AS gg FROM convenzioni_tesi WHERE stato = 'da_firmare' AND token_direttore IS NOT NULL ORDER BY da_firmare_il LIMIT " . $this->limite) : [];

        return $this->gruppo('tesi_firma', 'Convenzioni di tesi da firmare', 'fa-file-signature', '#0f766e', count($r), $r, static fn (array $x): array => [
            'testo' => (string) $x['azienda'], 'dettaglio' => 'convenzione per la tesi: firma digitale PAdES', 'link' => 'firma_convenzione_tesi.php?t=' . urlencode((string) $x['token_direttore']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Convenzioni FSL con le scuole arrivate alla firma di chi firma ora (il Direttore o, con «Direttore assente», il vice): il link porta direttamente alla firma. */
    private function convenzioniFslFirma(string $email): array
    {
        $chi = mb_strtolower(trim($this->direzione->chiFirma()['email']));
        $r = $chi !== '' && $chi === $email
            ? $this->db->righe("SELECT c.token_direttore, COALESCE(s.denominazione, c.scuola_codice, 'Scuola') AS scuola, DATEDIFF(NOW(), COALESCE(c.da_firmare_il, c.aggiornata_il, c.creata_il)) AS gg
                FROM convenzioni_compilate c LEFT JOIN scuole s ON s.codice = c.scuola_codice WHERE c.stato_iter = 'da_firmare' AND c.token_direttore IS NOT NULL AND c.token_direttore <> ''
                ORDER BY COALESCE(c.da_firmare_il, c.aggiornata_il, c.creata_il) LIMIT " . $this->limite) : [];

        return $this->gruppo('fsl_firma', 'Convenzioni FSL da firmare', 'fa-file-signature', '#0f766e', count($r), $r, static fn (array $x): array => [
            'testo' => (string) $x['scuola'], 'dettaglio' => 'convenzione con le scuole (Formazione Scuola Lavoro): firma digitale PAdES', 'link' => 'firma_convenzione.php?t=' . urlencode((string) $x['token_direttore']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Convenzioni di tesi che aspettano l'ufficio (modifiche da valutare, protocollo in entrata e in uscita), per le persone dell'ufficio convenzioni di tesi. */
    private function convenzioniTesiUfficio(string $email): array
    {
        $membro = (int) $this->db->valore("SELECT COUNT(*) FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE u.chiave = 'convenzioni_tesi' AND LOWER(p.email) = ?", [$email]) > 0;
        $stati = "'" . implode("','", CostantiTesi::DA_FARE_UFFICIO) . "'";
        $r = $membro ? $this->db->righe("SELECT azienda, email, stato, DATEDIFF(NOW(), COALESCE(aggiornata_il, creata_il)) AS gg FROM convenzioni_tesi WHERE stato IN ($stati) ORDER BY COALESCE(aggiornata_il, creata_il) LIMIT " . $this->limite) : [];
        $cosa = [CostantiTesi::MODIFICHE => 'modifiche agli articoli da valutare', CostantiTesi::CARICATA => 'firmata dall\'azienda: da protocollare in entrata', CostantiTesi::IN_CARICO => 'da mandare al Direttore', CostantiTesi::FIRMATA => 'firmata dal Direttore: da protocollare in uscita'];

        return $this->gruppo('tesi_ufficio', 'Convenzioni di tesi da seguire', 'fa-handshake', '#047857', count($r), $r, static fn (array $x): array => [
            'testo' => (string) ($x['azienda'] ?: $x['email']), 'dettaglio' => $cosa[$x['stato']] ?? (string) $x['stato'], 'link' => 'admin/convenzioni_tesi.php', 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Verbali dei consigli inviati alla firma, quando tocca al segretario o al coordinatore che sei. */
    private function verbali(string $email): array
    {
        $dove = "p.ruolo IN ('segretario', 'coordinatore') AND LOWER(p.email) = ? AND ((s.verbale_stato = 'segretario' AND p.ruolo = 'segretario') OR (s.verbale_stato = 'coordinatore' AND p.ruolo = 'coordinatore'))
            AND s.verbale_token IS NOT NULL AND s.verbale_token <> ''";
        $n = (int) $this->db->valore("SELECT COUNT(DISTINCT s.id) FROM didattica_sedute s JOIN didattica_consigli_persone p ON p.consiglio_id = s.consiglio_id WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT DISTINCT s.id, s.organo, s.data, s.verbale_token, DATEDIFF(NOW(), COALESCE(s.verbale_inviato_il, s.data, s.creata_il)) AS gg
            FROM didattica_sedute s JOIN didattica_consigli_persone p ON p.consiglio_id = s.consiglio_id WHERE $dove ORDER BY s.data LIMIT " . $this->limite, [$email]);

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
        $r = $this->db->righe("SELECT codice, nome, cognome, attivita, token_docente, DATEDIFF(NOW(), COALESCE(confermata_il, aggiornata_il, creata_il)) AS gg FROM tutorato_incarichi WHERE $dove ORDER BY confermata_il LIMIT " . $this->limite, [$email]);

        return $this->gruppo('tutorato_lettere', 'Lettere di incarico da firmare (tutorato)', 'fa-user-graduate', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => 'Tutor ' . self::nome($x), 'dettaglio' => 'incarico ' . $x['codice'] . ($x['attivita'] ? ': ' . mb_substr((string) $x['attivita'], 0, 80) : ''),
            'link' => 'firma_incarico.php?t=' . urlencode((string) $x['token_docente']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Lettere di incarico del tutorato già firmate dal docente, arrivate alla firma del Direttore del bando. */
    private function tutoratoLettereDirettore(string $email): array
    {
        $dove = "i.stato = 'firmata_docente' AND LOWER(b.direttore_email) = ? AND i.token_direttore IS NOT NULL AND i.token_direttore <> ''";
        $da = 'FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id';
        $n = (int) $this->db->valore("SELECT COUNT(*) $da WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT i.codice, i.nome, i.cognome, i.attivita, i.token_direttore, DATEDIFF(NOW(), COALESCE(i.firmata_docente_il, i.aggiornata_il, i.creata_il)) AS gg
            $da WHERE $dove ORDER BY i.firmata_docente_il LIMIT " . $this->limite, [$email]);

        return $this->gruppo('tutorato_lettere_direttore', 'Lettere di incarico da firmare (tutorato, Direttore)', 'fa-file-signature', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => 'Tutor ' . self::nome($x), 'dettaglio' => 'incarico ' . $x['codice'] . ': firmata dal docente responsabile, aspetta la tua firma',
            'link' => 'firma_incarico.php?t=' . urlencode((string) $x['token_direttore']), 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Lettere di incarico del tutorato mandate a te come tutor (riconosciuto dal codice fiscale): da controllare e accettare. */
    private function tutoratoAccettazione(string $cf): array
    {
        $dove = "stato = 'inviata' AND UPPER(codice_fiscale) = ? AND token_studente IS NOT NULL AND token_studente <> '' AND anonimizzata = 0";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi WHERE $dove", [$cf]);
        $r = $this->db->righe("SELECT codice, nome, cognome, attivita, token_studente, DATEDIFF(NOW(), COALESCE(inviata_il, aggiornata_il, creata_il)) AS gg FROM tutorato_incarichi WHERE $dove ORDER BY inviata_il LIMIT " . $this->limite, [$cf]);

        return $this->gruppo('tutorato_accettazione', 'Incarico di tutorato da accettare', 'fa-user-graduate', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => 'Incarico ' . $x['codice'] . ($x['attivita'] ? ': ' . mb_substr((string) $x['attivita'], 0, 80) : ''), 'dettaglio' => 'controlla i dati della lettera e accetta con SPID o CIE',
            'link' => 'incarico.php?t=' . urlencode((string) $x['token_studente']), 'giorni' => max(0, (int) $x['gg']),
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
            FROM tutorato_incarichi i WHERE $dove ORDER BY i.id LIMIT " . $this->limite, [$email]);

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
            LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id WHERE $dove ORDER BY pr.valutazione_inviata LIMIT " . $this->limite, [$email]);

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
            $da WHERE $dove ORDER BY t.data_turno LIMIT " . $this->limite, [$email]);

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
        $r = $this->db->righe("SELECT DISTINCT pr.id, pr.inizio, pr.nome, pr.cognome, r.nome AS risorsa, DATEDIFF(NOW(), pr.creata_il) AS gg $da WHERE $dove ORDER BY pr.inizio LIMIT " . $this->limite, [$email]);

        return $this->gruppo('risorse', 'Prenotazioni di risorse da approvare', 'fa-door-open', '#0f766e', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['risorsa'] . ' · ' . self::nome($x), 'dettaglio' => 'per il ' . date('d/m/Y H:i', (int) strtotime((string) $x['inizio'])),
            'link' => 'risorse_responsabile.php', 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Le tue pratiche (moduli online) per cui l'ufficio ha chiesto un'integrazione: lo studente deve rispondere. */
    private function praticheIntegrazione(string $email): array
    {
        $dove = "p.stato = 'integrazione' AND LOWER(p.email) = ?";
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche p WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT p.id, p.codice, DATEDIFF(NOW(), COALESCE(p.aggiornata_il, p.creata_il)) AS gg, m.titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
            WHERE $dove ORDER BY COALESCE(p.aggiornata_il, p.creata_il) LIMIT " . $this->limite, [$email]);

        return $this->gruppo('pratiche_integrazione', 'Pratiche da integrare', 'fa-folder-open', '#b45309', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'], 'dettaglio' => 'pratica ' . $x['codice'] . ': l\'ufficio ha chiesto un\'integrazione, rispondi dalla pratica',
            'link' => 'pratiche.php?id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg']),
        ]);
    }

    /** Posti liberati in una lista d'attesa e offerti a te: vanno confermati entro la scadenza, poi passano al prossimo. */
    private function postoLiberato(string $email): array
    {
        $dove = "pr.stato = 'richiesta_conferma' AND (pr.scadenza_conferma IS NULL OR pr.scadenza_conferma >= NOW()) AND LOWER(pr.email) = ? AND e.archiviato = 0";
        $da = 'FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN eventi e ON e.id = t.evento_id';
        $n = (int) $this->db->valore("SELECT COUNT(*) $da WHERE $dove", [$email]);
        $r = $this->db->righe("SELECT pr.id, pr.scadenza_conferma, e.titolo $da WHERE $dove ORDER BY pr.scadenza_conferma LIMIT " . $this->limite, [$email]);

        return $this->gruppo('posto_liberato', 'Posti liberati da confermare', 'fa-hourglass-half', '#B30000', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'], 'dettaglio' => 'si è liberato un posto per te' . ($x['scadenza_conferma'] ? ': conferma entro il ' . date('d/m/Y \a\l\l\e H:i', (int) strtotime((string) $x['scadenza_conferma'])) : ''),
            'link' => 'miei_eventi.php?conferma_posto=' . (int) $x['id'], 'giorni' => 0,
        ]);
    }

    /** Pratiche degli studenti in carico alla persona (operatore dell'Ufficio didattico) o da prendere in carico nel suo ufficio. */
    private function pratiche(string $email): array
    {
        $op = $this->db->riga('SELECT op_id AS id, ufficio_id FROM uffici_persone WHERE LOWER(email) = ? AND op_id IS NOT NULL ORDER BY id LIMIT 1', [$email]);
        if (!$op) {
            return $this->gruppo('pratiche', 'Pratiche da lavorare', 'fa-inbox', '#047857', 0, [], static fn (array $x): array => []);
        }
        // Pratiche degli operatori assenti che questa persona sostituisce oggi
        $assenti = $this->sostituzioni->sostituisce((int) $op['id']);
        $inPiu = $assenti ? ' OR p.assegnata_a IN (' . implode(',', $assenti) . ')' : '';
        $dove = "p.stato NOT IN ('bozza', 'accolta', 'respinta', 'chiusa', 'integrazione') AND NOT EXISTS (SELECT 1 FROM pratiche_firme f WHERE f.pratica_id = p.id AND f.stato = 'attesa')
            AND NOT EXISTS (SELECT 1 FROM pratiche_conferme c WHERE c.pratica_id = p.id AND c.stato = 'attesa')
            AND (p.assegnata_a = " . (int) $op['id'] . $inPiu . ($op['ufficio_id'] ? ' OR (p.assegnata_a IS NULL AND p.ufficio_id = ' . (int) $op['ufficio_id'] . ')' : '') . ')';
        $n = (int) $this->db->valore("SELECT COUNT(*) FROM pratiche p WHERE $dove");
        $r = $this->db->righe("SELECT p.id, p.codice, p.nome, p.cognome, p.assegnata_a, m.titolo, DATEDIFF(NOW(), COALESCE(p.aggiornata_il, p.creata_il)) AS gg
            FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id WHERE $dove ORDER BY COALESCE(p.aggiornata_il, p.creata_il) LIMIT " . $this->limite);

        return $this->gruppo('pratiche', 'Pratiche da lavorare', 'fa-inbox', '#047857', $n, $r, fn (array $x): array => [
            'testo' => (string) $x['titolo'] . ' · ' . self::nome($x), 'dettaglio' => 'pratica ' . $x['codice'] . ($x['assegnata_a'] ? ((int) $x['assegnata_a'] === (int) $op['id'] ? ': in carico a te' : ': in carico a un collega che stai sostituendo') : ': del tuo ufficio, da prendere in carico'),
            'link' => 'admin/didattica.php?tab=pratiche&id=' . (int) $x['id'], 'giorni' => max(0, (int) $x['gg']),
        ]);
    }
}
