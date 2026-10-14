<?php
// Prove del modulo Didattica (App\Didattica): le funzioni di inc/didattica.php e inc/convalide.php come facciate dei servizi, le cartelle
// protette, l'iter delle pratiche e i collegamenti nel container. Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni
// (prova, sezione). I dati di prova usano il modulo 9980 e le pratiche create qui, tutti ripuliti alla fine.
sezione("Modulo Didattica: facciate di inc/, moduli, pratiche, iter e convalide");

use App\Core\App as AppDid;
use App\Didattica\CampiModulo;
use App\Didattica\ServizioIter;
use App\Didattica\ServizioModuli;
use App\Didattica\ServizioPratiche;
use App\Didattica\ServizioUffici;

$c_did = AppDid::per($conn);
$s_uff = $c_did->get(ServizioUffici::class);
$s_mod = $c_did->get(ServizioModuli::class);
$s_pra = $c_did->get(ServizioPratiche::class);
$s_iter = $c_did->get(ServizioIter::class);

prova($c_did->get(\App\Didattica\FlussoPratica::class) instanceof \App\Didattica\ServizioFlussoConvalide && $c_did->get(\App\Tutorato\OperatoriUfficio::class) instanceof \App\Didattica\OperatoriPerTutorato,
    "container: il flusso delle convalide e gli operatori del Tutorato sono serviti da App\\Didattica");
prova(STATI_PRATICA === \App\Didattica\Costanti::STATI_PRATICA && COMPITI_UFFICIO === \App\Didattica\Costanti::COMPITI_UFFICIO && TIPI_UFFICIO === \App\Didattica\Costanti::TIPI_UFFICIO
    && ESITI_CONVALIDA === \App\Didattica\Costanti::ESITI_CONVALIDA && ITER_CDL === -1 && ITER_SEGRETERIA === -2 && TESTO_DICHIARAZIONE_DPR === \App\Didattica\Costanti::TESTO_DICHIARAZIONE_DPR,
    "costanti globali di prima, con i valori di App\\Didattica\\Costanti");

// Funzioni pure: facciata e classe danno la stessa risposta
$json_c = json_encode([['etichetta' => 'Corso', 'tipo' => 'corso_studio', 'obbligatorio' => 1], ['etichetta' => 'Esami', 'tipo' => 'tabella', 'opzioni' => 'Insegnamento, CFU, Piano:piano'],
                       ['etichetta' => 'Dettaglio', 'tipo' => 'text', 'cond' => ['campo' => 'Corso', 'op' => 'compilato']]]);
prova(campi_modulo($json_c) === CampiModulo::da($json_c) && count(campi_modulo($json_c)) === 3 && campi_modulo($json_c)[2]['cond']['nome'] === 'c1', "campi_modulo(): normalizzazione e condizioni");
prova(colonne_tabella(['Esito:scelta(Sì|No)', 'Voto']) === CampiModulo::colonne(['Esito:scelta(Sì|No)', 'Voto']) && tipo_colonna_da_nome('Relatore') === 'docente' && testo_colonne_tabella(colonne_tabella(['Esito:scelta(Sì|No)'])) === 'Esito:scelta(Sì|No)', "colonne delle tabelle");
prova(condizione_vera(['op' => 'uguale', 'valore' => 'b'], 'a, B') && !condizione_vera(['op' => 'vuoto', 'valore' => ''], 'x') && valore_piano('A scelta; elimina: X') === [true, 'X'] && valore_piano('no') === [false, ''], "condizioni e colonna del piano");
prova(periodo_modulo(['aperto_al' => date('Y-m-d', strtotime('-1 day'))])[0] === false && periodo_modulo([]) === [true, ''] && periodo_modulo(['aperto_al' => date('Y-m-d')])[0] === true, "periodo_modulo(): chiuso ieri, aperto fino a oggi");
prova(badge_stato_pratica('accolta') === ServizioIter::badge('accolta') && str_contains(badge_stato_pratica('boh'), '#64748b'), "badge degli stati");
prova(str_contains(html_campo_pratica(campi_modulo($json_c)[0], 'x', null, true), 'class="campo-pratica col-md-6"') && count(anni_accademici_scelta()) === 5, "html_campo_pratica() e anni accademici");

// Dati di prova
foreach (["DELETE FROM pratiche_eventi WHERE pratica_id IN (SELECT id FROM pratiche WHERE modulo_id = 9980)", "DELETE FROM pratiche_operatori WHERE pratica_id IN (SELECT id FROM pratiche WHERE modulo_id = 9980)",
          "DELETE FROM pratiche WHERE modulo_id = 9980", "DELETE FROM didattica_moduli WHERE id = 9980", "DELETE FROM ufficio_didattica WHERE email = 'prova.did80@example.org'",
          "DELETE FROM didattica_uffici WHERE nome = 'Ufficio prova 80'"] as $sql) $q($sql);
$q("INSERT INTO didattica_uffici (nome, tipo, smista, segue_corsi) VALUES ('Ufficio prova 80', '', 0, 0)");
$uff80 = uffici_didattica($conn, true);
$id_uff80 = (int)array_search('Ufficio prova 80', array_column($uff80, 'nome', 'id'), true);
$q("INSERT INTO ufficio_didattica (nominativo, email, compiti, ufficio_id) VALUES ('Prova Ottanta', 'prova.did80@example.org', 'pratiche', $id_uff80)");
prova($id_uff80 > 0 && ufficio_didattica_id($conn, $id_uff80) === $id_uff80 && ufficio_didattica_id($conn, 'boh') === null && $uff80 == $s_uff_t = $c_did->get(\App\Didattica\UfficioRepository::class)->tutti(), "uffici: lettura e ricerca per id");
$op80 = operatore_ufficio($conn, 0, ['email' => 'PROVA.DID80@example.org']);
prova($op80 && $op80['nominativo'] === 'Prova Ottanta' && etichetta_operatore($op80) === 'Prova Ottanta · Ufficio prova 80' && in_array('prova.did80@example.org', email_ufficio_didattica($conn), true)
    && utente_operatore_ufficio($conn, ['id' => 99, 'email' => 'prova.did80@example.org']) && utente_gestisce_didattica($conn, ['id' => 99, 'email' => 'prova.did80@example.org', 'ruolo_id' => 5]), "operatori: riconosciuti dall'email, etichetta, avvisi e permessi");
prova(operatori_ufficio($conn) === $s_uff->operatori() && nome_autore_ufficio($conn, ['email' => 'prova.did80@example.org']) === 'Prova Ottanta · Ufficio prova 80' && nome_autore_ufficio($conn, ['nome' => 'A', 'cognome' => 'B']) === 'A B · Ufficio didattico', "operatori: facciata e servizio");

$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, iter_json, destinatari, attivo, giorni_promemoria, aggiornato_il)
    VALUES (9980, 'Modulo prova 80', 'Prova', 'online', '" . $conn->real_escape_string($json_c) . "', '[$id_uff80]', 'tutti', 1, 7, NOW())");
$m80 = modulo_didattica($conn, 9980);
prova($m80['titolo'] === 'Modulo prova 80' && $m80 === $s_mod->modulo(9980) && iter_modulo($m80) === [$id_uff80] && passi_pratica($m80) === [0 => 'Ricevuta e da smistare', 1 => 'Ufficio prova 80'] && iter_modulo(['iter_json' => '["@cdl","@segreteria"]']) === [ITER_CDL, ITER_SEGRETERIA],
    "modulo e iter: passi risolti dagli uffici");
$id_pr80 = crea_pratica($conn, $m80, ['id' => 1, 'nome' => 'Prova', 'cognome' => 'Ottanta', 'email' => 'prova80@example.org', 'matricola_studente' => '8080'], [['etichetta' => 'Corso', 'tipo' => 'corso_studio', 'valore' => 'Corso 80']]);
$p80 = pratica($conn, $id_pr80);
prova($id_pr80 > 0 && $p80 === $s_pra->pratica($id_pr80) && $p80['stato'] === 'inviata' && $p80['matricola'] === '8080' && preg_match('/^PR-[0-9A-F]{8}$/', $p80['codice']) && $p80['modulo_titolo'] === 'Modulo prova 80', "crea_pratica(): pratica inviata con codice e matricola");
$EMAIL = [];
prova(cambia_stato_pratica($conn, $id_pr80, 'boh', '', 1) === false && cambia_stato_pratica($conn, $id_pr80, 'in_lavorazione', 'ok', 1) && $EMAIL === [] && cambia_stato_pratica($conn, $id_pr80, 'accolta', 'Fatto', 1) && count($EMAIL) === 1 && $EMAIL[0]['a'] === 'prova80@example.org', "cambia_stato_pratica(): email solo ai passaggi");
$p80 = pratica($conn, $id_pr80);
prova(assegna_pratica($conn, $id_pr80, (int)$op80['id'], 1, 'nota', 1, 'Admin') === null && pratica($conn, $id_pr80)['assegnata_a'] == $op80['id'] && operatori_pratica($conn, $id_pr80) === [(int)$op80['id']] && assegna_pratica($conn, $id_pr80, 99999, 1, '', 1) !== null
    && str_contains(html_iter_pratica($conn, pratica($conn, $id_pr80), $m80), 'Accolta') && messaggio_pratica($conn, $id_pr80, 'studente', 1, '') === 'Scrivi il messaggio o allega un file.', "iter: assegnazione, operatori della pratica e messaggi");
prova(statistiche_pratiche($conn, '2000-01-01', '2100-01-01')['totale'] >= 1 && isset(statistiche_pratiche($conn, '2000-01-01', '2100-01-01')['per_modulo']['Modulo prova 80']) && promemoria_pratiche_ferme($conn) >= 0, "statistiche e promemoria delle pratiche");

// Cartelle protette: ci sono e non si leggono via web
$all = $c_did->get(\App\Didattica\AllegatiPratiche::class);
$all->proteggi();
prova(is_file(RADICE_SITO . '/' . $all->cartella() . '.htaccess') && $all->cartella() === DIR_PRATICHE, "cartella degli allegati delle pratiche protetta (.htaccess)");

foreach (["DELETE FROM pratiche_eventi WHERE pratica_id = $id_pr80", "DELETE FROM pratiche_operatori WHERE pratica_id = $id_pr80", "DELETE FROM pratiche WHERE id = $id_pr80", "DELETE FROM didattica_moduli WHERE id = 9980",
          "DELETE FROM ufficio_didattica WHERE email = 'prova.did80@example.org'", "DELETE FROM didattica_uffici WHERE id = $id_uff80"] as $sql) $q($sql);
uffici_didattica($conn, true);

// Fasi delle pratiche: dove si trova una pratica e che cosa fare (App\Didattica\FasePratica)
sezione("Didattica: fasi delle pratiche");
use App\Didattica\FasePratica as FP_;
prova(FP_::di(['stato' => 'accolta'], 'protocollo') === 'conclusa' && FP_::di(['stato' => 'chiusa', 'seduta_id' => 3]) === 'conclusa', "FasePratica: una pratica conclusa è «conclusa» ovunque si trovi");
prova(FP_::di(['stato' => 'integrazione', 'ufficio_id' => 6], 'protocollo') === 'integrazione', "FasePratica: se si aspetta lo studente, prima di tutto «attesa dello studente»");
prova(FP_::di(['stato' => 'inviata', 'ufficio_id' => 6], 'protocollo') === 'protocollare' && FP_::di(['stato' => 'inviata', 'ufficio_id' => 9, 'assegnata_a' => 4], 'segreteria') === 'segreteria', "FasePratica: protocollo e segreteria si riconoscono dal tipo dell'ufficio");
prova(FP_::di(['stato' => 'in_lavorazione', 'seduta_id' => 2, 'esito_seduta' => '', 'assegnata_a' => 4]) === 'consiglio' && FP_::di(['stato' => 'in_lavorazione', 'seduta_id' => 2, 'esito_seduta' => 'approvata']) === 'decisa', "FasePratica: in seduta senza esito è per il consiglio, con esito è decisa");
prova(FP_::di(['stato' => 'inviata']) === 'smistare' && FP_::di(['stato' => 'inviata', 'ufficio_id' => 7], 'cdl') === 'prendere' && FP_::di(['stato' => 'in_lavorazione', 'ufficio_id' => 7, 'assegnata_a' => 4], 'cdl') === 'lavorazione', "FasePratica: da assegnare, da prendere in carico, in lavorazione");
prova(FP_::cosaFare('protocollare', ['protocollo' => ''])['tipo'] === 'azione' && FP_::cosaFare('protocollare', ['protocollo' => '55'])['tipo'] === 'attesa', "FasePratica::cosaFare(): protocollo registrato = si aspetta il bollo");
prova(FP_::cosaFare('consiglio', [])['dove'] === 'seduta' && FP_::cosaFare('integrazione', [])['tipo'] === 'attesa' && FP_::cosaFare('conclusa', [])['tipo'] === 'nessuna' && FP_::cosaFare('lavorazione', ['seduta_id' => 1])['testo'] === 'Istruisci', "FasePratica::cosaFare(): dove si va e tipo di azione");
prova(array_keys(FP_::FASI) === ['protocollare', 'smistare', 'prendere', 'lavorazione', 'firma', 'integrazione', 'consiglio', 'decisa', 'segreteria', 'conclusa'], "FasePratica: fasi nell'ordine dell'iter");
$cp_ = FP_::conta([['stato' => 'inviata'], ['stato' => 'inviata'], ['stato' => 'accolta']], fn($x) => '');
prova($cp_ === ['smistare' => 2, 'conclusa' => 1], "FasePratica::conta(): numeri per fase");
$it_ = \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->passiIter(['stato' => 'accolta', 'passo' => 1, 'assegnata_a' => null, 'ufficio_id' => null], null);
prova(array_unique(array_column($it_, 'stato')) === ['fatto'] && end($it_)['nome'] === 'Accolta', "ServizioIter::passiIter(): pratica conclusa, tutti i passi fatti e l'ultimo porta l'esito");
$it2_ = \App\Core\App::per($conn)->get(\App\Didattica\ServizioIter::class)->passiIter(['stato' => 'inviata', 'passo' => 1, 'assegnata_a' => null, 'ufficio_id' => null], null);
prova(count(array_filter($it2_, fn($x) => $x['stato'] === 'qui')) === 1 && $it2_[0]['stato'] === 'fatto' && end($it2_)['stato'] === '', "ServizioIter::passiIter(): un solo passo corrente, prima fatti e dopo da fare");
