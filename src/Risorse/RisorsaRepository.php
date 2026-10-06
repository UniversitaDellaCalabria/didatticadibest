<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Tutto l'SQL di aule, laboratori e sportelli prenotabili a slot (risorse, risorse_orari, risorse_chiusure, prenotazioni_risorse).
 * Le query che prima passavano da $conn->query() restituiscono stringhe (Righe::testo/riga), quelle preparate valori nativi: come prima.
 */
final class RisorsaRepository
{
    /** @var array<int, array<int, list<array{0: string, 1: string}>>> orari già letti nella richiesta, per risorsa */
    private array $cacheOrari = [];

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, string|null>|null la risorsa con titolo, indirizzo e colore della sua area */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug, p.colore_primario FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.id = ?', [$id]));
    }

    /** @return array<int, list<array{0: string, 1: string}>> fasce [dalle, alle] per giorno della settimana (1 = lunedì) */
    public function orari(int $risorsaId): array
    {
        if (!isset($this->cacheOrari[$risorsaId])) {
            $out = [];
            foreach (Righe::testo($this->db->righe('SELECT giorno, dalle, alle FROM risorse_orari WHERE risorsa_id = ? ORDER BY giorno, dalle', [$risorsaId])) as $x) {
                $out[(int) $x['giorno']][] = [(string) $x['dalle'], (string) $x['alle']];
            }
            $this->cacheOrari[$risorsaId] = $out;
        }

        return $this->cacheOrari[$risorsaId];
    }

    /** Motivo della chiusura della risorsa (o di tutta l'area) nel giorno, null se aperta; «Chiuso» se senza motivo. */
    public function chiusura(int $risorsaId, int $paginaId, string $data): ?string
    {
        $x = $this->db->riga('SELECT motivo FROM risorse_chiusure WHERE (risorsa_id = ? OR (risorsa_id IS NULL AND pagina_id = ?)) AND ? BETWEEN dal AND al LIMIT 1', [$risorsaId, $paginaId, $data]);

        return $x ? ($x['motivo'] !== '' ? $x['motivo'] : 'Chiuso') : null;
    }

    /**
     * Intervalli occupati (confermati o da approvare) che toccano il giorno, come timestamp [inizio, fine].
     *
     * @return list<array{0: int|false, 1: int|false}>
     */
    public function occupatiNelGiorno(int $risorsaId, string $data, ?int $escludiPrenotazione = null): array
    {
        $out = [];
        foreach ($this->db->righe(
            "SELECT inizio, fine FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND inizio < ? AND fine > ?" . ($escludiPrenotazione ? ' AND id <> ' . (int) $escludiPrenotazione : ''),
            [$risorsaId, "$data 23:59:59", "$data 00:00:00"]
        ) as $x) {
            $out[] = [strtotime((string) $x['inizio']), strtotime((string) $x['fine'])];
        }

        return $out;
    }

    /**
     * @param list<int> $ruoliIds
     * @return list<string> nomi dei ruoli, in minuscolo
     */
    public function nomiRuoli(array $ruoliIds): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT nome FROM ruoli WHERE id IN (' . implode(',', array_map('intval', $ruoliIds)) . ')')) as $x) {
            $out[] = mb_strtolower((string) $x['nome']);
        }

        return $out;
    }

    /** @return array<string, string|null>|null campi che dicono chi gestisce l'area */
    public function gestoriArea(int $paginaId): ?array
    {
        return Righe::riga($this->db->riga('SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = ?', [$paginaId]));
    }

    /**
     * @param list<int> $ids
     * @return list<string> indirizzi email (minuscoli, senza doppioni) degli utenti
     */
    public function emailUtenti(array $ids): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT DISTINCT LOWER(email) e FROM utenti WHERE id IN (' . implode(',', array_map('intval', $ids)) . ") AND email <> ''")) as $x) {
            $out[] = (string) $x['e'];
        }

        return $out;
    }

    /** Blocco per risorsa: due prenotazioni contemporanee sullo stesso calendario si accodano (10 secondi di attesa). */
    public function bloccaCalendario(int $risorsaId): bool
    {
        return (int) ($this->db->valore('SELECT GET_LOCK(?, 10)', [$this->nomeBlocco($risorsaId)]) ?? 0) === 1;
    }

    public function rilasciaCalendario(int $risorsaId): void
    {
        $this->db->valore('SELECT RELEASE_LOCK(?)', [$this->nomeBlocco($risorsaId)]);
    }

    private function nomeBlocco(int $risorsaId): string
    {
        return 'dibest_risorsa_' . $risorsaId;
    }

    /** Registra la prenotazione; false se l'INSERT non riesce (es. codice già usato). */
    public function inserisciPrenotazione(int $risorsaId, int $utenteId, string $nome, string $cognome, string $email, string $inizio, string $fine, string $motivo, string $stato, ?string $serie, string $codice): bool
    {
        return $this->db->esegui(
            'INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, serie, codice) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$risorsaId, $utenteId, $nome, $cognome, $email, $inizio, $fine, $motivo, $stato, $serie, $codice]
        ) >= 0;
    }

    /** @return array<string, mixed>|null prenotazione con risorsa e area, per id (int) o per codice (string) */
    public function prenotazione(int|string $chiave): ?array
    {
        $campo = is_int($chiave) ? 'pr.id' : 'pr.codice';

        return $this->db->riga(
            "SELECT pr.*, r.nome AS risorsa_nome, r.tipo AS risorsa_tipo, r.luogo, r.pagina_id, r.email_notifiche, r.approvazione, p.titolo AS area_titolo, p.colore_primario
             FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id JOIN pagine_eventi p ON p.id = r.pagina_id WHERE $campo = ? LIMIT 1",
            [$chiave]
        );
    }

    /** Passa la prenotazione da $da a $nuovo; true solo se è cambiata esattamente una riga (nessun altro l'ha gestita nel frattempo). */
    public function cambiaStato(int $id, string $nuovo, string $da): bool
    {
        return $this->db->esegui('UPDATE prenotazioni_risorse SET stato = ? WHERE id = ? AND stato = ?', [$nuovo, $id, $da]) === 1;
    }

    /**
     * Prenotazioni attive (confermate e da approvare) delle risorse nel periodo, per risorsa.
     *
     * @param list<int> $risorseIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function nelPeriodo(array $risorseIds, string $dal, string $al): array
    {
        $ids = array_values(array_filter(array_map('intval', $risorseIds)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->righe(
            "SELECT id, risorsa_id, utente_id, nome, cognome, inizio, fine, motivo, stato, codice FROM prenotazioni_risorse
             WHERE risorsa_id IN (" . implode(',', $ids) . ") AND stato IN ('confermata', 'da_approvare') AND inizio <= ? AND fine >= ? ORDER BY inizio",
            ["$al 23:59:59", "$dal 00:00:00"]
        ) as $x) {
            $out[(int) $x['risorsa_id']][] = $x;
        }

        return $out;
    }

    // ---- gestione (admin/risorse.php, ricevimento.php) ----

    /** @return list<array<string, string|null>> risorse dell'area con le prenotazioni future ancora attive (future) */
    public function elencoConFuture(int $paginaId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT r.*, (SELECT COUNT(*) FROM prenotazioni_risorse pr WHERE pr.risorsa_id = r.id AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW()) AS future
             FROM risorse r WHERE r.pagina_id = ? ORDER BY r.ordine, r.nome",
            [$paginaId]
        ));
    }

    /** @return list<array<string, string|null>> tutte le risorse dell'area (anche non attive) */
    public function dellArea(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM risorse WHERE pagina_id = ? ORDER BY ordine, nome', [$paginaId]));
    }

    /** @return list<array<string, string|null>> risorse attive dell'area, per la pagina pubblica */
    public function attiveDellArea(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM risorse WHERE pagina_id = ? AND attiva = 1 ORDER BY ordine, nome', [$paginaId]));
    }

    /** @return list<array<string, string|null>> id, nome e stato delle risorse dell'area */
    public function sintesiDellArea(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT id, nome, attiva FROM risorse WHERE pagina_id = ? ORDER BY ordine, nome', [$paginaId]));
    }

    /** @return list<array<string, string|null>> docenti attivi dell'anagrafe, per scegliere chi tiene lo sportello */
    public function docentiAttivi(): array
    {
        return Righe::testo($this->db->righe("SELECT id, cognome, nome FROM personale_ateneo WHERE gruppo = 'docenti' AND attivo = 1 ORDER BY cognome, nome"));
    }

    /** @return list<array<string, string|null>> chiusure dell'area degli ultimi 30 giorni e future, con il nome della risorsa */
    public function chiusureRecenti(int $paginaId): array
    {
        return Righe::testo($this->db->righe(
            'SELECT c.*, r.nome AS risorsa_nome FROM risorse_chiusure c LEFT JOIN risorse r ON r.id = c.risorsa_id WHERE c.pagina_id = ? AND c.al >= CURDATE() - INTERVAL 30 DAY ORDER BY c.dal',
            [$paginaId]
        ));
    }

    /**
     * Dati di una risorsa dal modulo: nome, tipo, descr, luogo, capienza (?int), referente, emails, durata, max_slot, anticipo, max_giorni,
     * accesso, appr, rip, motivo, attiva (tutti già validati).
     *
     * @param array<string, mixed> $v
     */
    public function aggiorna(int $id, int $paginaId, array $v): void
    {
        $this->db->esegui(
            'UPDATE risorse SET nome=?, tipo=?, descrizione=?, luogo=?, capienza=?, referente=?, email_notifiche=?, durata_slot=?, max_slot=?, anticipo_ore=?, max_giorni=?, accesso=?, approvazione=?, ripetizione=?, chiede_motivo=?, attiva=? WHERE id=? AND pagina_id=?',
            [$v['nome'], $v['tipo'], $v['descr'], $v['luogo'], $v['capienza'], $v['referente'], $v['emails'], $v['durata'], $v['max_slot'], $v['anticipo'], $v['max_giorni'], $v['accesso'], $v['appr'], $v['rip'], $v['motivo'], $v['attiva'], $id, $paginaId]
        );
    }

    /**
     * @param array<string, mixed> $v come in aggiorna()
     * @return int id della nuova risorsa, in fondo all'elenco dell'area
     */
    public function crea(int $paginaId, array $v): int
    {
        $ordine = (int) $this->db->valore('SELECT IFNULL(MAX(ordine), 0) + 1 n FROM risorse WHERE pagina_id = ?', [$paginaId]);

        return $this->db->inserisci(
            'INSERT INTO risorse (pagina_id, nome, tipo, descrizione, luogo, capienza, referente, email_notifiche, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione, chiede_motivo, attiva, ordine)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$paginaId, $v['nome'], $v['tipo'], $v['descr'], $v['luogo'], $v['capienza'], $v['referente'], $v['emails'], $v['durata'], $v['max_slot'], $v['anticipo'], $v['max_giorni'], $v['accesso'], $v['appr'], $v['rip'], $v['motivo'], $v['attiva'], $ordine]
        );
    }

    public function impostaColore(int $id, ?string $colore): void
    {
        $this->db->esegui('UPDATE risorse SET colore = ? WHERE id = ?', [$colore, $id]);
    }

    public function impostaPersona(int $id, ?string $personaId): void
    {
        $this->db->esegui('UPDATE risorse SET persona_id = ? WHERE id = ?', [$personaId, $id]);
    }

    public function impostaEmailNotifiche(int $id, string $email): void
    {
        $this->db->esegui('UPDATE risorse SET email_notifiche = ? WHERE id = ?', [$email, $id]);
    }

    public function impostaReferente(int $id, string $referente): void
    {
        $this->db->esegui('UPDATE risorse SET referente = ? WHERE id = ?', [$referente, $id]);
    }

    /**
     * Sostituisce gli orari settimanali della risorsa (le vecchie fasce si cancellano).
     *
     * @param list<array{0: int, 1: string, 2: string}> $fasce [giorno, dalle, alle]
     */
    public function sostituisciOrari(int $id, array $fasce): void
    {
        $this->db->esegui('DELETE FROM risorse_orari WHERE risorsa_id = ?', [$id]);
        foreach ($fasce as [$giorno, $dalle, $alle]) {
            $this->db->esegui('INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (?, ?, ?, ?)', [$id, $giorno, $dalle, $alle]);
        }
        unset($this->cacheOrari[$id]);
    }

    /** Impostazioni che il docente cambia dal suo ricevimento (max_slot resta 1: un appuntamento alla volta). */
    public function aggiornaSportello(int $id, int $durata, int $maxGiorni, int $anticipo, int $approvazione, int $attiva, string $luogo, string $descrizione): void
    {
        $this->db->esegui(
            'UPDATE risorse SET durata_slot = ?, max_giorni = ?, anticipo_ore = ?, approvazione = ?, attiva = ?, luogo = ?, descrizione = ?, max_slot = 1 WHERE id = ?',
            [$durata, $maxGiorni, $anticipo, $approvazione, $attiva, $luogo, $descrizione, $id]
        );
    }

    /** Prenotazioni attive che non sono ancora finite. */
    public function prenotazioniFuture(int $id): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND fine >= NOW()", [$id]);
    }

    public function disattiva(int $id): void
    {
        $this->db->esegui('UPDATE risorse SET attiva = 0 WHERE id = ?', [$id]);
    }

    /** Elimina la risorsa con orari, chiusure e prenotazioni. */
    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM risorse_orari WHERE risorsa_id = ?', [$id]);
        $this->db->esegui('DELETE FROM risorse_chiusure WHERE risorsa_id = ?', [$id]);
        $this->db->esegui('DELETE FROM prenotazioni_risorse WHERE risorsa_id = ?', [$id]);
        $this->db->esegui('DELETE FROM risorse WHERE id = ?', [$id]);
    }

    /** Chiusura di una risorsa o, con $risorsaId null, di tutta l'area. */
    public function aggiungiChiusura(int $paginaId, ?int $risorsaId, string $dal, string $al, string $motivo): void
    {
        $this->db->esegui('INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (?, ?, ?, ?, ?)', [$paginaId, $risorsaId, $dal, $al, $motivo]);
    }

    public function eliminaChiusura(int $chiusuraId, int $paginaId): void
    {
        $this->db->esegui('DELETE FROM risorse_chiusure WHERE id = ? AND pagina_id = ?', [$chiusuraId, $paginaId]);
    }

    public function eliminaChiusuraDellaRisorsa(int $chiusuraId, int $risorsaId): void
    {
        $this->db->esegui('DELETE FROM risorse_chiusure WHERE id = ? AND risorsa_id = ?', [$chiusuraId, $risorsaId]);
    }

    /** Prenotazioni attive dell'area (o di una sola risorsa) che iniziano nei giorni chiusi. */
    public function prenotazioniNeiGiorni(int $paginaId, ?int $risorsaId, string $dal, string $al): int
    {
        return (int) $this->db->valore(
            "SELECT COUNT(*) n FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ?" . ($risorsaId ? ' AND r.id = ' . $risorsaId : '')
            . " AND pr.stato IN ('confermata', 'da_approvare') AND DATE(pr.inizio) BETWEEN ? AND ?",
            [$paginaId, $dal, $al]
        );
    }

    /** Come prenotazioniNeiGiorni() per una sola risorsa, senza passare dall'area. */
    public function prenotazioniDellaRisorsaNeiGiorni(int $risorsaId, string $dal, string $al): int
    {
        return (int) $this->db->valore(
            "SELECT COUNT(*) n FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND DATE(inizio) BETWEEN ? AND ?",
            [$risorsaId, $dal, $al]
        );
    }

    /** @return list<array<string, string|null>> assenze della risorsa che non sono ancora finite */
    public function chiusureFuture(int $risorsaId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM risorse_chiusure WHERE risorsa_id = ? AND al >= CURDATE() ORDER BY dal', [$risorsaId]));
    }

    /** @return list<array<string, string|null>> prossimi appuntamenti attivi della risorsa (al massimo 200) */
    public function appuntamentiFuturi(int $risorsaId): array
    {
        return Righe::testo($this->db->righe("SELECT * FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare') AND fine >= NOW() ORDER BY inizio LIMIT 200", [$risorsaId]));
    }

    public function impostaNota(int $prenotazioneId, string $nota): void
    {
        $this->db->esegui('UPDATE prenotazioni_risorse SET nota_gestore = ? WHERE id = ?', [$nota, $prenotazioneId]);
    }

    // ---- pannello prenotazioni (admin/prenotazioni_risorse.php) ----

    /** @return list<int> id delle prenotazioni non ancora finite della stessa serie settimanale */
    public function idPrenotazioniDellaSerie(string $serie, int $risorsaId): array
    {
        return array_map(static fn (array $x): int => (int) $x['id'], $this->db->righe('SELECT id FROM prenotazioni_risorse WHERE serie = ? AND risorsa_id = ? AND fine >= NOW()', [$serie, $risorsaId]));
    }

    /**
     * Prenotazioni dell'area per l'elenco del pannello (al massimo 1000). $filtri: risorsa (id o 0), stato (confermata|da_approvare|annullata|rifiutata|attive),
     * periodo (future|passate|tutte), q (testo da cercare).
     *
     * @param array{risorsa: int, stato: string, periodo: string, q: string} $filtri già validati dal chiamante
     * @return list<array<string, mixed>>
     */
    public function elenco(int $paginaId, array $filtri): array
    {
        $where = ['r.pagina_id = ' . $paginaId];
        $par = [];
        if ($filtri['risorsa']) {
            $where[] = 'r.id = ' . (int) $filtri['risorsa'];
        }
        $where[] = match ($filtri['stato']) {
            'attive' => "pr.stato IN ('confermata', 'da_approvare')",
            default => "pr.stato = '" . $filtri['stato'] . "'",
        };
        if ($filtri['periodo'] === 'future') {
            $where[] = 'pr.fine >= NOW()';
        } elseif ($filtri['periodo'] === 'passate') {
            $where[] = 'pr.fine < NOW()';
        }
        if ($filtri['q'] !== '') {
            $where[] = '(pr.nome LIKE ? OR pr.cognome LIKE ? OR pr.email LIKE ? OR pr.codice LIKE ? OR pr.motivo LIKE ?)';
            $like = '%' . addcslashes($filtri['q'], '%_\\') . '%';
            array_push($par, $like, $like, $like, $like, $like);
        }

        return $this->db->righe(
            'SELECT pr.*, r.nome AS risorsa_nome, r.luogo FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY pr.inizio ' . ($filtri['periodo'] === 'passate' ? 'DESC' : 'ASC') . ' LIMIT 1000',
            $par
        );
    }

    /** @return array<string, string|null> prenotazioni di oggi, dei prossimi 7 giorni e da approvare dell'area */
    public function kpi(int $paginaId): array
    {
        return Righe::riga($this->db->riga(
            "SELECT
                SUM(pr.stato = 'confermata' AND DATE(pr.inizio) = CURDATE()) AS oggi,
                SUM(pr.stato = 'confermata' AND pr.inizio >= NOW() AND pr.inizio < NOW() + INTERVAL 7 DAY) AS settimana,
                SUM(pr.stato = 'da_approvare' AND pr.fine >= NOW()) AS da_approvare
             FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ?",
            [$paginaId]
        )) ?? [];
    }

    /** @return list<array<string, string|null>> agenda di oggi dell'area */
    public function oggi(int $paginaId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, r.nome AS risorsa_nome FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
             WHERE r.pagina_id = ? AND pr.stato IN ('confermata', 'da_approvare') AND DATE(pr.inizio) = CURDATE() ORDER BY pr.inizio, r.nome",
            [$paginaId]
        ));
    }

    // ---- pagina pubblica (calendario_area.php) ----

    /** @return list<array<string, string|null>> prossime prenotazioni attive dell'utente nell'area (al massimo 20) */
    public function prenotazioniDellUtente(int $paginaId, int $utenteId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, r.nome AS risorsa_nome, r.luogo FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id
             WHERE r.pagina_id = ? AND pr.utente_id = ? AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW() ORDER BY pr.inizio LIMIT 20",
            [$paginaId, $utenteId]
        ));
    }

    /** @return array<string, mixed>|null la riga dell'utente (tipi nativi, come get_result()) */
    public function utente(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM utenti WHERE id = ?', [$id]);
    }

    // ---- promemoria ----

    /** @return list<int> prenotazioni confermate che iniziano tra 1 e 24 ore e non hanno ancora il promemoria */
    public function idDaPromemoria(): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->righe("SELECT id FROM prenotazioni_risorse WHERE stato = 'confermata' AND promemoria_inviato = 0 AND inizio BETWEEN NOW() + INTERVAL 1 HOUR AND NOW() + INTERVAL 24 HOUR")
        );
    }

    public function segnaPromemoria(int $id): void
    {
        $this->db->esegui('UPDATE prenotazioni_risorse SET promemoria_inviato = 1 WHERE id = ?', [$id]);
    }

    // ---- aule dei turni degli eventi ----

    /** @return list<array<string, string|null>> risorse attive delle aree, per le scelte dell'aula nei turni, in ordine di area e di risorsa */
    public function aulePerTurni(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT r.id, r.nome, r.luogo, r.capienza, p.titolo FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id
             WHERE r.attiva = 1 AND r.tipo IN ('aula', 'laboratorio', 'altro') ORDER BY p.titolo, r.ordine, r.nome"
        ));
    }

    /** @return array<string, string|null>|null il turno con il titolo del suo evento */
    public function turnoConEvento(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT t.*, e.titolo FROM turni t JOIN eventi e ON e.id = t.evento_id WHERE t.id = ?', [$turnoId]));
    }

    /** @return array<string, string|null>|null la prenotazione attiva che occupa l'aula per il turno */
    public function prenotazioneDelTurno(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM prenotazioni_risorse WHERE turno_id = ? AND stato IN ('confermata', 'da_approvare') LIMIT 1", [$turnoId]));
    }

    /** L'aula torna libera: le prenotazioni attive del turno si annullano. */
    public function liberaTurno(int $turnoId): void
    {
        $this->db->esegui("UPDATE prenotazioni_risorse SET stato = 'annullata' WHERE turno_id = ? AND stato IN ('confermata', 'da_approvare')", [$turnoId]);
    }

    /** @return array<string, mixed>|null un'altra prenotazione che si sovrappone all'intervallo (non quella del turno stesso) */
    public function conflitto(int $risorsaId, string $inizio, string $fine, int $turnoId): ?array
    {
        return $this->db->riga(
            "SELECT inizio, fine, nome, cognome FROM prenotazioni_risorse WHERE risorsa_id = ? AND stato IN ('confermata', 'da_approvare')
             AND inizio < ? AND fine > ? AND (turno_id IS NULL OR turno_id <> ?) LIMIT 1",
            [$risorsaId, $fine, $inizio, $turnoId]
        );
    }

    public function aggiornaPrenotazioneAula(int $id, int $risorsaId, string $inizio, string $fine, string $motivo, string $nome): void
    {
        $this->db->esegui("UPDATE prenotazioni_risorse SET risorsa_id = ?, inizio = ?, fine = ?, motivo = ?, nome = ?, stato = 'confermata' WHERE id = ?", [$risorsaId, $inizio, $fine, $motivo, $nome, $id]);
    }

    public function inserisciPrenotazioneAula(int $risorsaId, ?int $utenteId, string $nome, string $inizio, string $fine, string $motivo, string $codice, int $turnoId): void
    {
        $this->db->esegui(
            "INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice, turno_id) VALUES (?, ?, ?, '', '', ?, ?, ?, 'confermata', ?, ?)",
            [$risorsaId, $utenteId, $nome, $inizio, $fine, $motivo, $codice, $turnoId]
        );
    }

    // ---- statistiche ----

    /** @return list<array<string, string|null>> risorse dell'area (id, nome, tipo) in ordine di pagina */
    public function risorseDellArea(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT id, nome, tipo FROM risorse WHERE pagina_id = ? ORDER BY ordine, nome', [$paginaId]));
    }

    /**
     * @param list<int> $risorseIds
     * @return array<int, array<int, float>> ore di apertura per risorsa e giorno della settimana
     */
    public function oreDiAperturaPerGiorno(array $risorseIds): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe('SELECT risorsa_id, giorno, TIME_TO_SEC(TIMEDIFF(alle, dalle)) / 3600 AS ore FROM risorse_orari WHERE risorsa_id IN (' . implode(',', array_map('intval', $risorseIds)) . ')')) as $x) {
            $out[(int) $x['risorsa_id']][(int) $x['giorno']] = ($out[(int) $x['risorsa_id']][(int) $x['giorno']] ?? 0) + (float) $x['ore'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> prenotazioni dell'area che iniziano nel periodo, con il solo necessario alle statistiche */
    public function prenotazioniDelPeriodo(int $paginaId, string $dal, string $al): array
    {
        return $this->db->righe(
            'SELECT pr.risorsa_id, pr.stato, pr.inizio, pr.fine FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id WHERE r.pagina_id = ? AND pr.inizio BETWEEN ? AND ?',
            [$paginaId, "$dal 00:00:00", "$al 23:59:59"]
        );
    }
}
