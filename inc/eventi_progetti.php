<?php
// inc/eventi_progetti.php - Eventi, turni e progetti: permessi, duplicazione, eliminazione, widget della home, schede dei progetti.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// EVENTI E TURNI: permessi, duplicazione, eliminazione (usate da admin/eventi.php e admin/archivio.php)
// =======================================================================
if (!function_exists('ev_autorizzato')) {
    // Evento dell'area corrente e visibile al gestore ($sql_filtro_eventi_rbac di admin_header.php)
    function ev_autorizzato($conn, int $ev_id, int $p_id, string $rbac): bool {
        $r = $conn->query("SELECT 1 FROM eventi e WHERE e.id = $ev_id AND e.pagina_id = $p_id $rbac LIMIT 1");
        return $r && $r->num_rows > 0;
    }
}
if (!function_exists('turno_autorizzato')) {
    function turno_autorizzato($conn, int $t_id, int $p_id, string $rbac): bool {
        $r = $conn->query("SELECT 1 FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = $t_id AND e.pagina_id = $p_id $rbac LIMIT 1");
        return $r && $r->num_rows > 0;
    }
}
if (!function_exists('pren_autorizzata')) {
    // Prenotazione di un evento dell'area corrente visibile al gestore
    function pren_autorizzata($conn, int $pr_id, int $p_id, string $rbac): bool {
        $r = $conn->query("SELECT 1 FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                           WHERE pr.id = $pr_id AND e.pagina_id = $p_id $rbac LIMIT 1");
        return $r && $r->num_rows > 0;
    }
}
if (!function_exists('nega_accesso')) {
    function nega_accesso(): void { http_response_code(403); die("Accesso negato."); }
}

if (!function_exists('colonne_copiabili')) {
    // Colonne di una tabella (escluso id): la copia resta corretta anche se lo schema cambia
    function colonne_copiabili($conn, string $tabella, array $escludi = []): array {
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM `$tabella`");
        if ($r) while ($c = $r->fetch_assoc()) if ($c['Field'] !== 'id' && !in_array($c['Field'], $escludi, true)) $cols[] = $c['Field'];
        return $cols;
    }
}

if (!function_exists('duplica_turno')) {
    // Duplica un turno (stessi dati, nessuna prenotazione) nell'evento indicato. Ritorna il nuovo id.
    function duplica_turno($conn, int $t_id, int $ev_dest, bool $segna_copia): int {
        $cols = colonne_copiabili($conn, 'turni', ['evento_id', 'nome_turno']);
        $lista = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $nome  = $segna_copia ? "IF(nome_turno IS NULL OR nome_turno = '', NULL, CONCAT(nome_turno, ' (copia)'))" : 'nome_turno';
        if (!$conn->query("INSERT INTO turni (evento_id, nome_turno, $lista) SELECT $ev_dest, $nome, $lista FROM turni WHERE id = $t_id")) {
            throw new RuntimeException($conn->error);
        }
        return (int)$conn->insert_id;
    }
}

if (!function_exists('duplica_evento')) {
    // Copia un evento (titolo "(copia)", non archiviato) con campi del form e sondaggi (non attivi,
    // condizioni "mostra se" ricollegate alle domande nuove). $con_turni: copia anche i turni, senza iscritti.
    // Ritorna ['evento' => id, 'turni' => n, 'sondaggi' => n]; in caso di errore annulla tutto e lancia l'eccezione.
    function duplica_evento($conn, int $ev_id, bool $con_turni = true): array {
        $conn->begin_transaction();
        try {
            $cols  = colonne_copiabili($conn, 'eventi', ['titolo', 'archiviato']);
            $lista = implode(', ', array_map(fn($c) => "`$c`", $cols));
            if (!$conn->query("INSERT INTO eventi (titolo, archiviato, $lista) SELECT CONCAT(titolo, ' (copia)'), 0, $lista FROM eventi WHERE id = $ev_id")) {
                throw new RuntimeException($conn->error);
            }
            $nuovo_ev = (int)$conn->insert_id;
            if ($nuovo_ev <= 0) throw new RuntimeException('Evento da duplicare non trovato');

            $n_turni = 0;
            if ($con_turni) {
                $r_t = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id ORDER BY id");
                while ($r_t && $t = $r_t->fetch_assoc()) { duplica_turno($conn, (int)$t['id'], $nuovo_ev, false); $n_turni++; }
            }

            $cols_cf  = colonne_copiabili($conn, 'campi_form', ['evento_id']);
            $lista_cf = implode(', ', array_map(fn($c) => "`$c`", $cols_cf));
            if (!$conn->query("INSERT INTO campi_form (evento_id, $lista_cf) SELECT $nuovo_ev, $lista_cf FROM campi_form WHERE evento_id = $ev_id")) {
                throw new RuntimeException($conn->error);
            }

            // Scheda del progetto (se l'evento è un progetto)
            $cols_pd = colonne_copiabili($conn, 'progetti_dettagli', ['evento_id']);
            if ($cols_pd) {
                $lista_pd = implode(', ', array_map(fn($c) => "`$c`", $cols_pd));
                if (!$conn->query("INSERT INTO progetti_dettagli (evento_id, $lista_pd) SELECT $nuovo_ev, $lista_pd FROM progetti_dettagli WHERE evento_id = $ev_id")) {
                    throw new RuntimeException($conn->error);
                }
            }

            $n_sond = 0;
            $cols_s  = colonne_copiabili($conn, 'sondaggi', ['evento_id', 'attivo']);
            $lista_s = implode(', ', array_map(fn($c) => "`$c`", $cols_s));
            $cols_d  = colonne_copiabili($conn, 'sondaggi_domande', ['sondaggio_id']);
            $lista_d = implode(', ', array_map(fn($c) => "`$c`", $cols_d));
            $r_s = $conn->query("SELECT id FROM sondaggi WHERE evento_id = $ev_id ORDER BY id");
            while ($r_s && $s = $r_s->fetch_assoc()) {
                $vecchio_s = (int)$s['id'];
                $sql_s = "INSERT INTO sondaggi (evento_id, attivo" . ($lista_s !== '' ? ", $lista_s" : '') . ") SELECT $nuovo_ev, 0" . ($lista_s !== '' ? ", $lista_s" : '') . " FROM sondaggi WHERE id = $vecchio_s";
                if (!$conn->query($sql_s)) throw new RuntimeException($conn->error);
                $nuovo_s = (int)$conn->insert_id;
                $n_sond++;

                $mappa_dom = [];
                $r_d = $conn->query("SELECT id FROM sondaggi_domande WHERE sondaggio_id = $vecchio_s ORDER BY id");
                while ($r_d && $d = $r_d->fetch_assoc()) {
                    $vecchia_d = (int)$d['id'];
                    if (!$conn->query("INSERT INTO sondaggi_domande (sondaggio_id, $lista_d) SELECT $nuovo_s, $lista_d FROM sondaggi_domande WHERE id = $vecchia_d")) {
                        throw new RuntimeException($conn->error);
                    }
                    $mappa_dom[$vecchia_d] = (int)$conn->insert_id;
                }
                foreach ($mappa_dom as $nuova_d) {
                    $r_c = $conn->query("SELECT condizione_json FROM sondaggi_domande WHERE id = $nuova_d");
                    $cond = ($r_c && $rc = $r_c->fetch_assoc()) ? json_decode((string)$rc['condizione_json'], true) : null;
                    if (!is_array($cond) || !isset($cond['se_id'])) continue;
                    $cond['se_id'] = $mappa_dom[(int)$cond['se_id']] ?? 0;
                    $nuovo_json = $cond['se_id'] > 0 ? "'" . $conn->real_escape_string(json_encode($cond)) . "'" : 'NULL';
                    $conn->query("UPDATE sondaggi_domande SET condizione_json = $nuovo_json WHERE id = $nuova_d");
                }
            }
            $conn->commit();
            return ['evento' => $nuovo_ev, 'turni' => $n_turni, 'sondaggi' => $n_sond];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}

if (!function_exists('elimina_turno')) {
    // Elimina un turno con le sue prenotazioni e i messaggi collegati. Da usare dentro una transazione se serve.
    function elimina_turno($conn, int $t_id): void {
        $conn->query("DELETE m FROM messaggi_prenotazioni m JOIN prenotazioni pr ON m.prenotazione_id = pr.id WHERE pr.turno_id = $t_id");
        $conn->query("DELETE pp FROM partecipanti_prenotazione pp JOIN prenotazioni pr ON pp.prenotazione_id = pr.id WHERE pr.turno_id = $t_id");
        $conn->query("DELETE FROM prenotazioni WHERE turno_id = $t_id");
        // Aula occupata dal turno (Prenotazioni e risorse): torna libera
        @$conn->query("UPDATE prenotazioni_risorse SET stato = 'annullata' WHERE turno_id = $t_id AND stato IN ('confermata', 'da_approvare')");
        $conn->query("DELETE FROM turni WHERE id = $t_id");
    }
}

if (!function_exists('elimina_evento')) {
    // Elimina definitivamente un evento e TUTTO ciò che dipende da lui (turni, prenotazioni, messaggi,
    // campi del form, sondaggi con domande e risposte), in un'unica transazione: niente dati orfani.
    function elimina_evento($conn, int $ev_id): bool {
        $conn->begin_transaction();
        try {
            $conn->query("DELETE r FROM sondaggi_risposte r JOIN sondaggi s ON r.sondaggio_id = s.id WHERE s.evento_id = $ev_id");
            $conn->query("DELETE d FROM sondaggi_domande d JOIN sondaggi s ON d.sondaggio_id = s.id WHERE s.evento_id = $ev_id");
            $conn->query("DELETE FROM sondaggi WHERE evento_id = $ev_id");
            $r_t = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id");
            while ($r_t && $t = $r_t->fetch_assoc()) elimina_turno($conn, (int)$t['id']);
            $conn->query("DELETE FROM campi_form WHERE evento_id = $ev_id");
            $conn->query("DELETE FROM progetti_dettagli WHERE evento_id = $ev_id");
            if (!$conn->query("DELETE FROM eventi WHERE id = $ev_id")) throw new RuntimeException($conn->error);
            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[elimina_evento] ' . $e->getMessage());
            return false;
        }
    }
}

// =======================================================================
// WIDGET HOME: configurazione (JSON in configurazione_portale.widgets_home)
// =======================================================================
if (!function_exists('get_prenotazioni_attive_utente')) {
    // Prenotazioni ancora da vivere dell'utente (turno non concluso), la più urgente per prima:
    // prima i posti offerti da confermare, poi per data; i turni senza data in coda.
    function get_prenotazioni_attive_utente($conn, int $u_id, int $limite = 10): array {
        if ($u_id <= 0) return [];
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.codice_prenotazione, IFNULL(pr.stato, 'confermata') AS stato, pr.num_posti, pr.scadenza_conferma,
                    t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo AS evento_titolo, e.luogo, e.locandina_path,
                    pe.titolo AS area_titolo, pe.colore_primario, pe.slug
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE (pr.utente_id = ? OR LOWER(pr.email) = (SELECT LOWER(u.email) FROM utenti u WHERE u.id = ? AND u.email != ''))
               AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare', 'in_attesa')
               AND e.archiviato = 0
               AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) >= NOW())
             ORDER BY (IFNULL(pr.stato, 'confermata') = 'richiesta_conferma') DESC,
                      (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, pr.id ASC
             LIMIT ?"
        );
        // Mai bloccare la home per un widget: in caso di errore SQL, niente widget + log
        if (!$stmt) { error_log('[get_prenotazioni_attive_utente] ' . $conn->error); return []; }
        $stmt->bind_param("iii", $u_id, $u_id, $limite);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
}

if (!function_exists('get_posizioni_lista_attesa')) {
    // Posizione in coda (1 = il prossimo a essere promosso) delle prenotazioni 'in_attesa' indicate.
    // Stesso ordine di promuovi_lista_attesa: data di prenotazione, a parità l'id.
    // Ritorna [pr_id => ['posizione' => n, 'totale' => persone in coda nel turno]].
    function get_posizioni_lista_attesa($conn, array $pr_ids): array {
        $pr_ids = array_filter(array_map('intval', $pr_ids));
        if (!$pr_ids) return [];
        $in = implode(',', $pr_ids);
        $res = $conn->query(
            "SELECT p.id,
                    1 + (SELECT COUNT(*) FROM prenotazioni q
                          WHERE q.turno_id = p.turno_id AND q.stato = 'in_attesa'
                            AND (q.data_prenotazione < p.data_prenotazione
                                 OR (q.data_prenotazione = p.data_prenotazione AND q.id < p.id))) AS posizione,
                    (SELECT COUNT(*) FROM prenotazioni r WHERE r.turno_id = p.turno_id AND r.stato = 'in_attesa') AS totale
             FROM prenotazioni p
             WHERE p.id IN ($in) AND p.stato = 'in_attesa'"
        );
        $out = [];
        if ($res) while ($r = $res->fetch_assoc()) $out[(int)$r['id']] = ['posizione' => (int)$r['posizione'], 'totale' => (int)$r['totale']];
        return $out;
    }
}

if (!function_exists('get_turni_ultimi_posti')) {
    // Turni prenotabili adesso con pochi posti (<= 10% della capienza, almeno 1)
    // o con iscrizioni che chiudono entro 48 ore. Un solo turno per evento, i più urgenti prima.
    function get_turni_ultimi_posti($conn, array $pagine_ids, int $limite = 4): array {
        $pagine_ids = array_filter(array_map('intval', $pagine_ids));
        if (!$pagine_ids) return [];
        $in = implode(',', $pagine_ids);
        // Conteggio posti in una sottoquery: MariaDB non accetta alias di aggregati
        // dentro espressioni di HAVING/ORDER BY, e così vale anche ONLY_FULL_GROUP_BY.
        $res = $conn->query(
            "SELECT x.* FROM (
                SELECT t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti, t.data_chiusura,
                       e.id AS evento_id, e.titolo, e.tipo, e.locandina_path,
                       pe.titolo AS area_titolo, pe.colore_primario, pe.slug,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE e.archiviato = 0 AND pe.visibile = 1 AND e.pagina_id IN ($in)
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND IFNULL(e.tipo, 'evento') <> 'progetto'
                  AND t.max_posti > 0 AND t.max_posti < " . (int)POSTI_SENZA_LIMITE . "
                  AND (t.data_apertura IS NULL OR t.data_apertura <= NOW())
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             WHERE x.max_posti - x.occupati > 0
               AND (x.max_posti - x.occupati <= GREATEST(1, CEIL(x.max_posti * 0.10))
                    OR (x.data_chiusura IS NOT NULL AND x.data_chiusura <= NOW() + INTERVAL 48 HOUR))
             ORDER BY (x.max_posti - x.occupati) / x.max_posti ASC, x.data_chiusura IS NULL, x.data_chiusura ASC
             LIMIT 30"
        );
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (isset($out[(int)$r['evento_id']])) continue;
                $r['liberi'] = (int)$r['max_posti'] - (int)$r['occupati'];
                $out[(int)$r['evento_id']] = $r;
                if (count($out) >= $limite) break;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('get_riepilogo_posti')) {
    // Capienza e posti occupati dei turni ancora prenotabili, raggruppati per evento o per area.
    // $per = 'evento' | 'pagina'. Ritorna [id => ['capienza'=>, 'occupati'=>, 'liberi'=>]].
    // Esclusi: eventi senza prenotazione, turni senza limite (>= POSTI_SENZA_LIMITE), conclusi o con iscrizioni chiuse.
    function get_riepilogo_posti($conn, string $per, array $ids): array {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return [];
        $col = $per === 'pagina' ? 'e.pagina_id' : 'e.id';
        $in  = implode(',', $ids);
        $res = $conn->query(
            "SELECT x.chiave, SUM(x.max_posti) AS capienza, SUM(x.occupati) AS occupati FROM (
                SELECT $col AS chiave, t.max_posti,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                WHERE $col IN ($in) AND e.archiviato = 0
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND t.max_posti > 0 AND t.max_posti < " . (int)POSTI_SENZA_LIMITE . "
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             GROUP BY x.chiave"
        );
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $cap = (int)$r['capienza'];
                $occ = min($cap, (int)$r['occupati']);
                $out[(int)$r['chiave']] = ['capienza' => $cap, 'occupati' => $occ, 'liberi' => $cap - $occ];
            }
        }
        return $out;
    }
}

if (!function_exists('widgets_home_default')) {
    function widgets_home_default(): array {
        return [
            'slideshow' => 1, 'mia_prenotazione' => 1, 'annunci' => 0, 'card_aree' => 1,
            'ultimi_posti' => 0, 'prossimi_eventi' => 1, 'statistiche' => 0,
            // Widget dell'organizzazione per pubblico (inc/organizzazione.php): spenti finché non si accendono o si applica la proposta
            'percorsi' => 0, 'agenda' => 0, 'scadenze' => 0,
            'ordine'        => ['slideshow', 'percorsi', 'mia_prenotazione', 'annunci', 'agenda', 'scadenze', 'card_aree', 'ultimi_posti', 'prossimi_eventi', 'statistiche'],
            'aree_colonne'  => 2,         // 2 | 3 | 4 card per riga (desktop)
            'aree_max'      => 0,         // 0 = tutte; altrimenti le altre si aprono con "Mostra tutte"
            'eventi_num'    => 8,         // 4 | 8 | 12
            'eventi_layout' => 'scroll',  // scroll | griglia
        ];
    }
}

if (!function_exists('get_widgets_home')) {
    // Legge la configurazione salvata e la normalizza (valori non validi -> default).
    function get_widgets_home(?array $cfg_portale): array {
        $w = widgets_home_default();
        $dec = !empty($cfg_portale['widgets_home']) ? json_decode($cfg_portale['widgets_home'], true) : null;
        if (is_array($dec)) $w = array_merge($w, $dec);

        $chiavi = widgets_home_default()['ordine'];
        foreach ($chiavi as $k) $w[$k] = (int)!empty($w[$k]);

        // Ordine: solo chiavi note, senza duplicati. I widget nuovi (assenti in una
        // configurazione salvata prima che esistessero) vanno subito dopo il widget
        // che li precede nell'ordine predefinito, non in fondo alla pagina.
        $ordine = array_values(array_unique(array_intersect((array)$w['ordine'], $chiavi)));
        foreach ($chiavi as $i => $k) {
            if (in_array($k, $ordine, true)) continue;
            $pos = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $p = array_search($chiavi[$j], $ordine, true);
                if ($p !== false) { $pos = $p + 1; break; }
            }
            array_splice($ordine, $pos, 0, [$k]);
        }
        $w['ordine'] = $ordine;

        $w['aree_colonne']  = in_array((int)$w['aree_colonne'], [2, 3, 4], true) ? (int)$w['aree_colonne'] : 2;
        $w['aree_max']      = max(0, min(48, (int)$w['aree_max']));
        $w['eventi_num']    = in_array((int)$w['eventi_num'], [4, 8, 12], true) ? (int)$w['eventi_num'] : 8;
        $w['eventi_layout'] = $w['eventi_layout'] === 'griglia' ? 'griglia' : 'scroll';
        return $w;
    }
}

// =======================================================================
// PROGETTI (es. Formazione Scuola Lavoro): un progetto è un evento con tipo = 'progetto',
// una scheda in progetti_dettagli e un turno per edizione.
// - Dedicato alle scuole (per_scuole = 1): ogni edizione accoglie UNA scuola (1 posto), le altre
//   in lista d'attesa in ordine di arrivo; la scuola indica il numero di partecipanti.
// - Generico: ogni edizione ha i suoi posti, una persona per posto, come gli eventi.
// =======================================================================
if (!defined('CAMPO_PARTECIPANTI')) define('CAMPO_PARTECIPANTI', 'numero_partecipanti');
// Turni con almeno tanti posti = senza un limite reale (es. 5000 per un evento online): niente contatore né barra,
// solo «Posti disponibili». Prima la soglia era 9000 e le somme dei turni mostravano «100.000 posti liberi».
if (!defined('POSTI_SENZA_LIMITE')) define('POSTI_SENZA_LIMITE', 1000);
if (!function_exists('testo_posti_liberi')) {
    function testo_posti_liberi(int $liberi): string {
        if ($liberi >= POSTI_SENZA_LIMITE) return 'Posti disponibili';
        return $liberi > 0 ? $liberi . ($liberi === 1 ? ' posto libero' : ' posti liberi') : '';
    }
}
// Dopo quanti mesi dalla fine del progetto i nomi degli studenti vengono ridotti alle iniziali (cron_background.php)
if (!defined('MESI_CONSERVAZIONE_STUDENTI')) define('MESI_CONSERVAZIONE_STUDENTI', 12);

if (!function_exists('get_dettagli_progetti')) {
    // Schede dei progetti indicati: [evento_id => riga di progetti_dettagli con referenti e info già decodificati]
    function get_dettagli_progetti($conn, array $ev_ids): array {
        $ev_ids = array_filter(array_map('intval', $ev_ids));
        if (!$ev_ids) return [];
        $res = $conn->query("SELECT * FROM progetti_dettagli WHERE evento_id IN (" . implode(',', $ev_ids) . ")");
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['referenti']  = json_decode((string)($r['referenti_json'] ?? ''), true) ?: [];
                $r['info_extra'] = json_decode((string)($r['info_extra_json'] ?? ''), true) ?: [];
                $r['moduli']     = json_decode((string)($r['moduli_json'] ?? ''), true) ?: [];
                $out[(int)$r['evento_id']] = $r;
            }
        }
        return $out;
    }
}

if (!function_exists('destinazione_progetto')) {
    // Progetto che rimanda a un'altra pagina (es. OpenLab): la card resta nell'elenco dei progetti ma "Dettagli"
    // porta lì. progetti_dettagli.destinazione = slug di un'area del portale oppure indirizzo http(s).
    // Ritorna null se il progetto è normale, altrimenti ['url', 'nome', 'esterno'] (url relativo alla radice del sito).
    function destinazione_progetto($conn, ?array $d): ?array {
        $v = trim((string)($d['destinazione'] ?? ''));
        if ($v === '') return null;
        static $aree = null;
        if ($aree === null) {
            $aree = [];
            $r = $conn->query("SELECT slug, titolo FROM pagine_eventi");
            while ($r && $a = $r->fetch_assoc()) $aree[strtolower((string)$a['slug'])] = (string)$a['titolo'];
        }
        $slug = strtolower(preg_replace('/\.php$/i', '', $v));
        if (isset($aree[$slug])) return ['url' => $slug . '.php', 'nome' => $aree[$slug] !== '' ? $aree[$slug] : $slug, 'esterno' => false];
        if (preg_match('#^https?://#i', $v) && filter_var($v, FILTER_VALIDATE_URL)) {
            return ['url' => $v, 'nome' => preg_replace('/^www\./i', '', (string)parse_url($v, PHP_URL_HOST)), 'esterno' => true];
        }
        return null; // area eliminata o indirizzo non valido: il progetto torna a comportarsi normalmente
    }
}

if (!function_exists('html_pulsante_destinazione')) {
    // Pulsante "Vai a OPENLAB" dei progetti con rimando ($stile = colori del pulsante dell'area)
    function html_pulsante_destinazione(array $dest, string $stile): string {
        $target = $dest['esterno'] ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . htmlspecialchars($dest['url']) . '"' . $target . ' class="btn fw-bold w-100" style="' . htmlspecialchars($stile) . '">Vai a '
             . htmlspecialchars($dest['nome']) . ' <i class="fa fa-arrow-right ms-1" aria-hidden="true"></i></a>';
    }
}

if (!function_exists('periodo_progetto')) {
    // "Dal 13/10/2026 al 18/12/2026", "Dal 13/10/2026", "Entro il 18/12/2026" oppure "Date da definire"
    function periodo_progetto(?array $d): string {
        $ini = !empty($d['data_inizio']) ? date('d/m/Y', strtotime($d['data_inizio'])) : '';
        $fin = !empty($d['data_fine'])   ? date('d/m/Y', strtotime($d['data_fine']))   : '';
        if ($ini && $fin) return $ini === $fin ? "Il $ini" : "Dal $ini al $fin";
        if ($ini) return "Dal $ini";
        if ($fin) return "Entro il $fin";
        return 'Date da definire';
    }
}

if (!function_exists('stato_progetto')) {
    // Stato calcolato dalle date del progetto e dal turno di iscrizione.
    // $occupati = posti occupati del turno (0 o 1). Ritorna ['codice', 'etichetta', 'bg', 'fg', 'ordine'].
    function stato_progetto(?array $d, ?array $turno, int $occupati): array {
        $oggi = date('Y-m-d');
        $ora  = date('Y-m-d H:i:s');
        $stati = [
            'aperte'   => ['Iscrizioni aperte', '#DCFCE7', '#166534', 1],
            'attesa'   => ["Assegnato · lista d'attesa aperta", '#FEF3C7', '#92400E', 2],
            'arrivo'   => ['Iscrizioni in arrivo', '#DBEAFE', '#1E40AF', 3],
            'chiuse'   => ['Iscrizioni chiuse', '#F1F5F9', '#334155', 4],
            'in_corso' => ['In corso', '#EDE9FE', '#5B21B6', 5],
            'concluso' => ['Concluso', '#E5E7EB', '#374151', 6],
        ];
        if (!empty($d['data_fine']) && $d['data_fine'] < $oggi) $c = 'concluso';
        elseif (!$turno) $c = (!empty($d['data_inizio']) && $d['data_inizio'] <= $oggi) ? 'in_corso' : 'chiuse';
        elseif (!empty($turno['data_apertura']) && $ora < $turno['data_apertura']) $c = 'arrivo';
        elseif (!empty($turno['data_chiusura']) && $ora > $turno['data_chiusura']) $c = (!empty($d['data_inizio']) && $d['data_inizio'] <= $oggi) ? 'in_corso' : 'chiuse';
        elseif ($occupati < (int)$turno['max_posti']) $c = 'aperte';
        elseif (!empty($turno['abilita_lista_attesa'])) $c = 'attesa';
        else $c = (!empty($d['data_inizio']) && $d['data_inizio'] <= $oggi) ? 'in_corso' : 'chiuse';
        [$et, $bg, $fg, $ord] = $stati[$c];
        return ['codice' => $c, 'etichetta' => $et, 'bg' => $bg, 'fg' => $fg, 'ordine' => $ord];
    }
}

if (!function_exists('info_edizioni_progetto')) {
    // Edizioni (repliche) di un progetto: ogni turno è un'edizione da 1 scuola con la sua lista d'attesa.
    // $mie = [turno_id => stato] dell'utente corrente. Ritorna le edizioni con posti, coda e scuola assegnata,
    // lo stato complessivo (stato_progetto su un turno "riassuntivo") e l'eventuale iscrizione dell'utente.
    function info_edizioni_progetto($conn, ?array $d, array $turni, array $mie = []): array {
        $edizioni = []; $occupate = 0; $posti = 0; $mio = null; $mio_turno = null; $prossima_apertura = null;
        foreach (array_values($turni) as $i => $t) {
            $t_id = (int)$t['id'];
            $occ = getPostiOccupati($conn, $t_id);
            $r_w = $conn->query("SELECT COUNT(*) AS n FROM prenotazioni WHERE turno_id = $t_id AND stato = 'in_attesa'");
            $r_a = $conn->query("SELECT id, nome, cognome, email, stato, presente, attestato_inviato, dati_custom_json FROM prenotazioni WHERE turno_id = $t_id AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare') ORDER BY data_prenotazione ASC, id ASC LIMIT 1");
            $max = max(1, (int)$t['max_posti']);
            $st_ed = stato_progetto($d, $t + ['abilita_lista_attesa' => 1], $occ);
            $lim = limiti_partecipanti($d, $t);
            $edizioni[] = [
                't' => $t, 'numero' => $i + 1,
                'etichetta' => trim((string)($t['nome_turno'] ?? '')) !== '' ? $t['nome_turno'] : 'Edizione ' . ($i + 1),
                'occ' => $occ, 'libera' => $occ < $max,
                'attesa' => $r_w ? (int)$r_w->fetch_assoc()['n'] : 0,
                'assegnata' => $r_a ? $r_a->fetch_assoc() : null,
                'mio' => $mie[$t_id] ?? null,
                // Ogni edizione ha la sua finestra di iscrizione e i suoi limiti di partecipanti
                'stato' => $st_ed, 'min' => $lim['min'], 'max' => $lim['max'],
            ];
            // Posti liberi contati solo sulle edizioni con iscrizioni aperte o ancora da aprire
            if (in_array($st_ed['codice'], ['aperte', 'attesa', 'arrivo'], true)) { $posti += $max; $occupate += min($max, $occ); }
            if (isset($mie[$t_id]) && $mio === null) { $mio = $mie[$t_id]; $mio_turno = $t_id; }
            if ($st_ed['codice'] === 'arrivo' && ($prossima_apertura === null || $t['data_apertura'] < $prossima_apertura)) $prossima_apertura = $t['data_apertura'];
        }
        // Stato del progetto: il più favorevole tra le edizioni (una aperta basta per "Iscrizioni aperte")
        $stato = $edizioni ? $edizioni[0]['stato'] : stato_progetto($d, null, 0);
        foreach ($edizioni as $ed) if ($ed['stato']['ordine'] < $stato['ordine']) $stato = $ed['stato'];
        return ['edizioni' => $edizioni, 'stato' => $stato, 'liberi' => $posti - $occupate,
                'mio' => $mio, 'mio_turno' => $mio_turno, 'prossima_apertura' => $prossima_apertura];
    }
}
