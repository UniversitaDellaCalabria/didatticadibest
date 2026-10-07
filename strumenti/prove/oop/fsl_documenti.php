<?php
// Prove dei documenti da consegnare prima delle attività FSL (App\Fsl\DocumentiClasse e ServizioDocumentiClasse): elenco degli studenti
// e autorizzazione della scuola, scadenza, promemoria del cron e avviso dopo la prenotazione. Chiamato da esegui.php: stesse variabili
// ($conn, $q, $giorni, $EMAIL) e funzioni (prova, sezione). Dati di prova con id 987xx e la scuola ZZDC98700X.
sezione("Modulo FSL: documenti prima dell'attività (elenco studenti e autorizzazione)");

use App\Core\App as AppDoc;
use App\Fsl\DocumentiClasse;

// Orologio fisso: 2026-10-07 12:00
$orologio_doc = new class implements \App\Core\Orologio {
    public function adesso(): DateTimeImmutable { return new DateTimeImmutable('2026-10-07 12:00:00'); }
};
$regole_doc = new DocumentiClasse($orologio_doc);

// Chi deve consegnare i documenti: attività FSL, progetti solo se per le scuole
prova($regole_doc->richiesti(['fsl' => 1, 'evento_tipo' => 'progetto', 'per_scuole' => 1]) && $regole_doc->richiesti(['fsl' => '1', 'evento_tipo' => 'evento'])
    && !$regole_doc->richiesti(['fsl' => 0, 'evento_tipo' => 'progetto', 'per_scuole' => 1]) && !$regole_doc->richiesti(['fsl' => 1, 'evento_tipo' => 'progetto', 'per_scuole' => 0])
    && !$regole_doc->richiesti([]), "documenti richiesti solo per le attività FSL di classe");
prova($regole_doc->attiva(['stato' => 'da_approvare']) && $regole_doc->attiva(['stato' => 'in_attesa']) && $regole_doc->attiva(['stato' => 'confermata'])
    && !$regole_doc->attiva(['stato' => 'annullata']) && !$regole_doc->attiva(['stato' => 'rifiutata']), "si consegnano anche in attesa della convenzione, non se annullata o rifiutata");

// Scadenza: 7 giorni prima dell'inizio (il turno vale più dell'inizio del progetto); chi prenota tardi ha tempo fino al giorno prima
prova($regole_doc->inizio(['data_turno' => '2026-11-20', 'data_inizio' => '2026-11-01']) === '2026-11-20' && $regole_doc->inizio(['data_turno' => null, 'pd_inizio' => '2026-11-01']) === '2026-11-01'
    && $regole_doc->inizio([]) === null, "inizio dell'attività: turno, altrimenti inizio del progetto");
prova($regole_doc->scadenza(['data_turno' => '2026-11-20', 'data_prenotazione' => '2026-10-01 10:00:00']) === '2026-11-13', "scadenza: 7 giorni prima dell'inizio");
prova($regole_doc->scadenza(['data_turno' => '2026-10-10', 'data_prenotazione' => '2026-10-07 09:00:00']) === '2026-10-09', "prenotazione a ridosso: scadenza il giorno prima");
prova($regole_doc->scadenza(['data_turno' => null]) === null, "senza data niente scadenza");
prova($regole_doc->consegnaAperta(['fsl' => 1, 'evento_tipo' => 'evento', 'stato' => 'confermata', 'data_turno' => '2026-10-07'])
    && !$regole_doc->consegnaAperta(['fsl' => 1, 'evento_tipo' => 'evento', 'stato' => 'confermata', 'data_turno' => '2026-10-06'])
    && !$regole_doc->consegnaAperta(['fsl' => 0, 'evento_tipo' => 'evento', 'stato' => 'confermata', 'data_turno' => '2026-12-01']), "consegna aperta fino al giorno dell'attività");

// Stato dei documenti
$s_doc = $regole_doc->stato(['autorizzazione_file' => 'uploads/autorizzazioni/x.pdf', 'data_turno' => '2026-11-20'], 3, 15);
prova($s_doc['completi'] && $s_doc['elenco'] && $s_doc['autorizzazione'] && !$s_doc['scaduti'] && $s_doc['scadenza'] === '2026-11-13', "documenti completi: elenco e autorizzazione");
$s_doc = $regole_doc->stato(['autorizzazione_file' => null, 'data_turno' => '2026-10-10', 'data_prenotazione' => '2026-09-01'], 0, 15);
prova(!$s_doc['completi'] && !$s_doc['elenco'] && !$s_doc['autorizzazione'] && $s_doc['scaduti'], "senza documenti e oltre la scadenza: segnalato come scaduto");

// Calendario degli avvisi: primo promemoria dopo 7 giorni dalla prenotazione, poi ogni 7 giorni (al massimo 6), un solo ultimo avviso
$base_doc = ['data_turno' => '2026-12-15', 'data_prenotazione' => '2026-10-07 08:00:00', 'doc_promemoria' => 0, 'doc_promemoria_il' => null, 'doc_ultimo_avviso' => 0];
prova($regole_doc->prossimoAvviso($base_doc) === null, "avviso: appena prenotato, niente (basta l'email della prenotazione)");
prova($regole_doc->prossimoAvviso(['data_prenotazione' => '2026-09-29 08:00:00'] + $base_doc) === 'promemoria', "avviso: 8 giorni dopo la prenotazione, primo promemoria");
prova($regole_doc->prossimoAvviso(['doc_promemoria' => 1, 'doc_promemoria_il' => '2026-10-03 08:00:00', 'data_prenotazione' => '2026-09-20 08:00:00'] + $base_doc) === null, "avviso: promemoria di 4 giorni fa, niente");
prova($regole_doc->prossimoAvviso(['doc_promemoria' => 1, 'doc_promemoria_il' => '2026-09-29 08:00:00', 'data_prenotazione' => '2026-09-20 08:00:00'] + $base_doc) === 'promemoria', "avviso: ogni 7 giorni, anche se l'attività è lontana");
prova($regole_doc->prossimoAvviso(['doc_promemoria' => 6, 'doc_promemoria_il' => '2026-09-01 08:00:00', 'data_prenotazione' => '2026-08-01 08:00:00'] + $base_doc) === null, "avviso: massimo 6 promemoria periodici");
$vicina_doc = ['data_turno' => '2026-10-12', 'data_prenotazione' => '2026-09-01 08:00:00', 'doc_promemoria' => 3, 'doc_promemoria_il' => '2026-09-28 08:00:00', 'doc_ultimo_avviso' => 0];
prova($regole_doc->prossimoAvviso($vicina_doc) === 'ultimo', "avviso: superata la scadenza (5 giorni prima dell'inizio), ultimo avviso");
prova($regole_doc->prossimoAvviso(['doc_ultimo_avviso' => 1] + $vicina_doc) === null, "avviso: l'ultimo avviso parte una volta sola");
prova($regole_doc->prossimoAvviso(['data_turno' => '2026-10-06'] + $vicina_doc) === null, "avviso: attività già iniziata, niente");

// Servizio con il database: promemoria del cron, avviso dopo la prenotazione
foreach (["DELETE FROM prenotazioni WHERE id IN (98703, 98704)", "DELETE FROM turni WHERE id = 98702", "DELETE FROM progetti_dettagli WHERE evento_id = 98701", "DELETE FROM eventi WHERE id = 98701", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98700, 'FSL documenti 987', 'fsl_documenti_987', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98701, 98700, 'Progetto FSL documenti', 'progetto', 'Prova documenti.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (98701, 1, 1, 0, '" . $giorni(40) . "', '" . $giorni(70) . "', 20)");
$q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (98702, 98701, 'Edizione 987', 5, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, data_prenotazione, convenzione)
    VALUES (98703, 98702, 'FS-DOC987', 'da_approvare', 'Rosa', 'Docente', 'rosa987@example.org', NOW() - INTERVAL 8 DAY, 'no'),
           (98704, 98702, 'FS-DOC988', 'annullata', 'Gino', 'Docente', 'gino987@example.org', NOW() - INTERVAL 8 DAY, 'no')");
$doc_srv = AppDoc::per($conn)->get(\App\Fsl\ServizioDocumentiClasse::class);

$n_prima = count($EMAIL);
$resoconto = $doc_srv->promemoria();
$inviate_doc = array_values(array_filter(array_slice($EMAIL, $n_prima), fn($e) => str_contains($e['oggetto'], 'autorizzazione della scuola')));
prova(count($inviate_doc) === 1 && $inviate_doc[0]['a'] === 'rosa987@example.org' && str_starts_with($inviate_doc[0]['oggetto'], 'Promemoria:') && str_contains($resoconto, '1 promemoria'),
    "cron: promemoria al docente che non ha consegnato (non a chi ha annullato)", $resoconto);
prova(str_contains($inviate_doc[0]['corpo'] ?? '', 'elenco degli studenti') && str_contains($inviate_doc[0]['corpo'] ?? '', 'autorizzazione della scuola') && str_contains($inviate_doc[0]['corpo'] ?? '', 'elenco_studenti.php?code=FS-DOC987'),
    "il promemoria dice cosa manca e rimanda alla pagina");
$riga_doc = $conn->query("SELECT doc_promemoria, doc_promemoria_il FROM prenotazioni WHERE id = 98703")->fetch_assoc();
prova((int)$riga_doc['doc_promemoria'] === 1 && !empty($riga_doc['doc_promemoria_il']), "il promemoria è contato");
$n_prima = count($EMAIL);
$doc_srv->promemoria();
prova(count(array_filter(array_slice($EMAIL, $n_prima), fn($e) => str_contains($e['oggetto'], 'autorizzazione della scuola'))) === 0, "cron: subito dopo non ne parte un altro");

// Con elenco e autorizzazione il promemoria non serve più
$q("INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (98703, 'Rossi', 'Mario', 1)");
$q("UPDATE prenotazioni SET autorizzazione_file = 'uploads/autorizzazioni/" . str_repeat('a', 32) . ".pdf', autorizzazione_nome = 'aut.pdf', doc_promemoria_il = NOW() - INTERVAL 8 DAY WHERE id = 98703");
$n_prima = count($EMAIL);
$doc_srv->promemoria();
prova(count(array_filter(array_slice($EMAIL, $n_prima), fn($e) => str_contains($e['oggetto'], 'autorizzazione della scuola'))) === 0, "cron: documenti completi, nessun promemoria");
$st_doc = $doc_srv->stato($conn->query("SELECT * FROM prenotazioni WHERE id = 98703")->fetch_assoc(), 14);
prova($st_doc['completi'] && $st_doc['studenti'] === 1 && $st_doc['max'] === 14, "stato dal database: 1 studente e autorizzazione = completi");

// Avviso dopo la prenotazione (email e pagina)
$avv = $doc_srv->avvisoPrenotazione(['convenzione' => 1, 'per_scuole' => 1, 'data_inizio' => $giorni(40)], true, 'FS-NUOVO', null, true);
prova(str_contains($avv, 'elenco degli studenti') && str_contains($avv, 'autorizzazione della scuola') && str_contains($avv, 'elenco_studenti.php?code=FS-NUOVO') && str_contains($avv, date('d/m/Y', strtotime($giorni(33)))),
    "avviso dopo la prenotazione: cosa caricare, link e scadenza (7 giorni prima)");
prova($doc_srv->avvisoPrenotazione(['convenzione' => 0, 'per_scuole' => 1], true, 'FS-NUOVO', null, true) === '' && $doc_srv->avvisoPrenotazione(null, false, 'X', null, true) === '', "nessun avviso per le attività non FSL");
$mod = $doc_srv->avvisoModulo(['convenzione' => 1, 'per_scuole' => 1, 'data_inizio' => $giorni(40)], true, null);
prova(str_contains($mod, 'Dopo la prenotazione dovrai caricare due documenti') && str_contains($mod, 'elenco degli studenti') && str_contains($mod, 'autorizzazione della scuola') && str_contains($mod, date('d/m/Y', strtotime($giorni(33))))
    && str_contains($mod, 'border:2px solid') && $doc_srv->avvisoModulo(['convenzione' => 0, 'per_scuole' => 1], true, null) === '' && $doc_srv->avvisoModulo(null, false, null) === '', "riquadro nel modulo di prenotazione: evidente, solo per le attività FSL");
prova(str_contains($doc_srv->avvisoPerCodice('FS-DOC987'), 'Elenco degli studenti e autorizzazione della scuola') && $doc_srv->avvisoPerCodice('FS-DOC988') === '' && $doc_srv->avvisoPerCodice('NON-ESISTE') === '', "avviso nella pagina di conferma: solo per prenotazioni FSL attive");
prova(AppDoc::per($conn)->get(\App\Iscrizioni\RegoleFsl::class)->avvisoDocumentiClasse(['convenzione' => 1, 'per_scuole' => 1, 'data_inizio' => $giorni(40)], true, 'FS-NUOVO', null) === $avv, "RegoleFsl serve l'avviso al modulo Iscrizioni");

// Pulizia
foreach (["DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = 98703", "DELETE FROM prenotazioni WHERE id IN (98703, 98704)", "DELETE FROM turni WHERE id = 98702", "DELETE FROM progetti_dettagli WHERE evento_id = 98701", "DELETE FROM eventi WHERE id = 98701", "DELETE FROM pagine_eventi WHERE id = 98700"] as $sql) $q($sql);
