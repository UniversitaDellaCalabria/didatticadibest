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

// Richiesta unica «Certificato» (iscrizione, laurea, rinuncia): il modulo si prepara una volta, i campi dipendono dal tipo scelto
$cert_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave = 'certificato'")->fetch_assoc();
if (!$cert_) {
    (new \App\Didattica\ModelliPratiche(\App\Core\App::per($conn)->get(\App\Core\Database::class)))->prepara();
    $cert_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave = 'certificato'")->fetch_assoc();
}
prova((bool) $cert_ && $cert_['titolo'] === 'Richiesta Certificato' && !$conn->query("SELECT id FROM didattica_moduli WHERE chiave IN ('certificato_iscrizione', 'certificato_laurea')")->fetch_assoc(),
    "Certificato unico: un solo modulo, niente certificati separati di iscrizione e laurea");
$cc_ = CampiModulo::da($cert_['campi_json']);
$cp_ = array_column($cc_, null, 'etichetta');
prova($cp_['Tipo di certificato']['tipo'] === 'select' && $cp_['Tipo di certificato']['opzioni'] === \App\Didattica\ModelliPratiche::TIPI_CERTIFICATO,
    "Certificato unico: tendina Iscrizione / Laurea / Rinuncia agli studi");
prova($cp_['Certificati di rinuncia richiesti']['opzioni'] === \App\Didattica\ModelliPratiche::CERTIFICATI_RINUNCIA && $cp_['Certificati di rinuncia richiesti']['cond']['valore'] === 'Rinuncia agli studi'
    && $cp_['Certificati di iscrizione richiesti']['cond']['nome'] === $cp_['Tipo di certificato']['nome'], "Certificato unico: elenco di caselle diverso per ogni tipo, collegato alla tendina");
$leg_ = function (string $tipo, array $extra) use ($c_did, $cc_, $cp_) {
    $base = ['campo_' . $cp_['Tipo di certificato']['nome'] => $tipo, 'campo_' . $cp_['Matricola']['nome'] => '245678', 'campo_' . $cp_['Al Corso di Laurea in']['nome'] => 'X',
             'campo_' . $cp_['Codice fiscale']['nome'] => 'RSSLCU02A01D086X', 'campo_' . $cp_['Luogo di nascita']['nome'] => 'Cosenza', 'campo_' . $cp_['Data di nascita']['nome'] => '2002-01-01',
             'campo_' . $cp_['Cellulare']['nome'] => '3331234567', 'campo_' . $cp_['Email personale']['nome'] => 'a@b.it'];
    [$ris, $err] = $c_did->get(\App\Didattica\LetturaRisposte::class)->leggi($cc_, array_merge($base, $extra), [], false);

    return [array_column($ris, null, 'etichetta'), $err];
};
[$r_, $e_] = $leg_('Rinuncia agli studi', ['campo_' . $cp_['Certificati di rinuncia richiesti']['nome'] => ['Rinuncia', 'Rinuncia con esami'], 'campo_' . $cp_["Iscritto per l'A.A."]['nome'] => '2026/2027',
    'campo_' . $cp_[array_key_last($cp_)]['nome'] => '1']);
prova(!empty($r_['Certificati di iscrizione richiesti']['nascosto']) && !empty($r_["Laureato nell'A.A."]['nascosto']) && $r_['Certificati di rinuncia richiesti']['valore'] === 'Rinuncia, Rinuncia con esami'
    && empty($r_['Certificati di rinuncia richiesti']['nascosto']), "Certificato unico: scegliendo «Rinuncia agli studi» restano visibili solo i campi della rinuncia");
[$r_, $e_] = $leg_('Laurea', ['campo_' . $cp_['Certificati di laurea richiesti']['nome'] => ['Laurea'], 'campo_' . $cp_["Laureato nell'A.A."]['nome'] => '2025/2026', 'campo_' . $cp_['In data']['nome'] => '2026-07-10',
    'campo_' . $cp_['Con voto']['nome'] => '110/110', 'campo_' . $cp_[array_key_last($cp_)]['nome'] => '1']);
prova(!empty($r_["Iscritto per l'A.A."]['nascosto']) && empty($r_['Con voto']['nascosto']) && $e_ === [], "Certificato unico: scegliendo «Laurea» servono anno, data e voto, non l'anno di iscrizione");
[, $e_] = $leg_('Iscrizione', ['campo_' . $cp_["Iscritto per l'A.A."]['nome'] => '2026/2027', 'campo_' . $cp_[array_key_last($cp_)]['nome'] => '1']);
prova(count(array_filter($e_, fn($x) => str_contains($x, 'Certificati di iscrizione richiesti'))) === 1 && count($e_) === 1, "Certificato unico: per l'iscrizione va scelto almeno un certificato (e basta)");

// Pratiche «Immatricolazioni, passaggi e trasferimenti»: trasferimento in entrata, iscrizione per rinuncia/decadenza, abbreviazione di corso
$imm_ = [];
$res_imm_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ('trasferimento_entrata', 'iscrizione_rinuncia', 'abbreviazione_corso')");
while ($rg_ = $res_imm_->fetch_assoc()) {
    $imm_[$rg_['chiave']] = $rg_;
}
if (count($imm_) < 3) {
    (new \App\Didattica\ModelliPratiche(\App\Core\App::per($conn)->get(\App\Core\Database::class)))->prepara();
    $imm_ = [];
    $res_imm_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ('trasferimento_entrata', 'iscrizione_rinuncia', 'abbreviazione_corso')");
    while ($rg_ = $res_imm_->fetch_assoc()) {
        $imm_[$rg_['chiave']] = $rg_;
    }
}
prova(count($imm_) === 3 && array_unique(array_column($imm_, 'categoria')) === ['Immatricolazioni, passaggi e trasferimenti'] && array_unique(array_map('intval', array_column($imm_, 'in_verbale'))) === [1]
    && array_unique(array_map('intval', array_column($imm_, 'attivo'))) === [0], "Immatricolazioni: tre moduli inattivi, nella loro categoria e con passaggio in verbale");
$iter_imm_ = $c_did->get(ServizioIter::class);
foreach ($imm_ as $chiave_ => $m_) {
    $cm_ = CampiModulo::da($m_['campi_json']);
    $et_ = array_column($cm_, null, 'etichetta');
    $tipi_ = array_column($cm_, 'tipo');
    $ver_ = json_decode($m_['verbale_json'], true);
    preg_match_all('/\{([^{}\n]{1,200})\}/u', $ver_['testo'] . ' ' . (json_decode($m_['domanda_json'], true)['chiede'] ?? ''), $segn_);
    $mancanti_ = array_filter($segn_[1], fn($s) => !in_array(mb_strtolower($s), ['studente', 'protocollo', 'matricola'], true) && !isset(array_column($cm_, null, 'etichetta')[$s]));
    prova(json_decode($m_['iter_json'], true) === ['protocollo', '@cdl', '@segreteria'] && count(array_filter($cm_, fn($c) => $c['tipo'] === 'corso_studio')) === 1 && !empty($et_['Corso di destinazione']['obbligatorio'])
        && !empty($et_['Ricevuta di pagamento della marca da bollo']['obbligatorio']) && $et_['Ricevuta di pagamento della marca da bollo']['tipo'] === 'file' && !empty($et_['Obbligo formativo aggiuntivo (OFA)']['ufficio'])
        && !empty($et_['Autocertificazione degli esami sostenuti']['obbligatorio']) && $mancanti_ === [],
        "Immatricolazioni ($chiave_): iter con ufficio del corso e segreteria, corso di destinazione, ricevuta del bollo, OFA all'ufficio e segnaposti del verbale e della domanda tutti validi" . ($mancanti_ ? ' – mancano: ' . implode(', ', $mancanti_) : ''));
}
$tr_ = CampiModulo::da($imm_['trasferimento_entrata']['campi_json']);
$ri_ = CampiModulo::da($imm_['iscrizione_rinuncia']['campi_json']);
prova(!empty(array_column($tr_, null, 'etichetta')['Programmi degli esami sostenuti']['obbligatorio']) && empty(array_column($ri_, null, 'etichetta')['Programmi degli esami sostenuti']['obbligatorio'])
    && !empty(array_column($ri_, null, 'etichetta')['Mi sono iscritto/sono già iscritto sul sistema ESSE3']['obbligatorio']), "Immatricolazioni: programmi obbligatori solo per il trasferimento; per la rinuncia serve aver fatto l'iscrizione su Esse3");

// Pratiche di carriera: convalida e esonero di inglese, piano di studi, curriculum, tempo pieno/part-time, fuori piano, anticipo, titolo estero
$chiavi_car_ = ['convalida_inglese', 'esonero_inglese', 'modifica_piano', 'cambio_curriculum', 'tempo_pieno_part_time', 'esami_fuori_piano', 'anticipo_insegnamenti', 'titolo_estero'];
$in_car_ = "'" . implode("', '", $chiavi_car_) . "'";
$car_ = [];
$res_car_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ($in_car_)");
while ($rg_ = $res_car_->fetch_assoc()) {
    $car_[$rg_['chiave']] = $rg_;
}
if (count($car_) < 8) {
    (new \App\Didattica\ModelliPratiche(\App\Core\App::per($conn)->get(\App\Core\Database::class)))->prepara();
    $car_ = [];
    $res_car_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ($in_car_)");
    while ($rg_ = $res_car_->fetch_assoc()) {
        $car_[$rg_['chiave']] = $rg_;
    }
}
prova(count($car_) === 8 && array_unique(array_column($car_, 'categoria')) === ['Carriera'] && array_unique(array_map('intval', array_column($car_, 'in_verbale'))) === [1]
    && array_unique(array_map('intval', array_column($car_, 'attivo'))) === [0], "Carriera: otto moduli inattivi, nella categoria Carriera e con passaggio in verbale");
foreach ($car_ as $chiave_ => $m_) {
    $cm_ = CampiModulo::da($m_['campi_json']);
    $per_ = array_column($cm_, null, 'etichetta');
    $ver_ = json_decode($m_['verbale_json'], true);
    $dom_ = json_decode($m_['domanda_json'], true);
    preg_match_all('/\{([^{}\n]{1,200})\}/u', $ver_['testo'] . ' ' . $dom_['chiede'], $segn_);
    $manc_ = array_filter($segn_[1], fn($s) => !in_array(mb_strtolower($s), ['studente', 'protocollo', 'matricola'], true) && !isset($per_[$s]));
    prova(json_decode($m_['iter_json'], true) === ['protocollo', '@cdl', '@segreteria'] && count(array_filter($cm_, fn($c) => $c['tipo'] === 'corso_studio')) === 1 && $dom_['pdf'] === 1
        && (int) $dom_['bollo'] === ($chiave_ === 'convalida_inglese' ? 1 : 0) && $ver_['testo'] !== '' && $manc_ === [],
        "Carriera ($chiave_): iter protocollo, ufficio del corso e segreteria, un solo corso di studio, bollo solo per la convalida di inglese e segnaposti validi" . ($manc_ ? ' – mancano: ' . implode(', ', $manc_) : ''));
}
$pi_ = array_column(CampiModulo::da($car_['modifica_piano']['campi_json']), null, 'etichetta');
$fp_ = array_column(CampiModulo::da($car_['esami_fuori_piano']['campi_json']), null, 'etichetta');
prova($pi_['Variazioni al piano di studi']['tipo'] === 'tabella' && $pi_['Variazioni al piano di studi']['colonne'][3]['scelte'] === ['Inserire', 'Togliere'] && count($fp_['Insegnamenti da inserire']['colonne']) === 5
    && !empty(array_column(CampiModulo::da($car_['convalida_inglese']['campi_json']), null, 'etichetta')['Copia della certificazione del livello B2 (ed oltre)']['obbligatorio']),
    "Carriera: variazioni del piano con scelta Inserire/Togliere, tabella dei fuori piano e certificazione B2 obbligatoria");

// Pratiche di tesi, cancellazione, esonero e mobilità internazionale
$chiavi_t_ = ['tesi', 'cancellazione_iscrizione', 'esonero_frequenza', 'mobilita_svolgimento', 'mobilita_variazione', 'mobilita_fine'];
$in_t_ = "'" . implode("', '", $chiavi_t_) . "'";
$tt_ = [];
$res_t_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ($in_t_)");
while ($rg_ = $res_t_->fetch_assoc()) {
    $tt_[$rg_['chiave']] = $rg_;
}
if (count($tt_) < 6) {
    (new \App\Didattica\ModelliPratiche(\App\Core\App::per($conn)->get(\App\Core\Database::class)))->prepara();
    $tt_ = [];
    $res_t_ = $conn->query("SELECT * FROM didattica_moduli WHERE chiave IN ($in_t_)");
    while ($rg_ = $res_t_->fetch_assoc()) {
        $tt_[$rg_['chiave']] = $rg_;
    }
}
prova(count($tt_) === 6 && array_unique(array_map('intval', array_column($tt_, 'attivo'))) === [0] && array_unique(array_map('intval', array_column($tt_, 'in_verbale'))) === [1]
    && $tt_['tesi']['categoria'] === 'Lauree' && $tt_['mobilita_fine']['categoria'] === 'Mobilità internazionale' && $tt_['esonero_frequenza']['categoria'] === 'Carriera',
    "Tesi, cancellazione, esonero e mobilità: sei moduli inattivi, in verbale, nelle loro categorie");
$mob_uff_ = (int) $conn->query("SELECT id FROM didattica_uffici WHERE chiave = 'internazionalizzazione'")->fetch_assoc()['id'];
$it_t_ = $c_did->get(ServizioIter::class);
$p_mob_ = $it_t_->iter($tt_['mobilita_fine'], ['cdl_id' => 0, 'segreteria_id' => 0]);
prova($mob_uff_ > 0 && $p_mob_[1] === $mob_uff_ && count($p_mob_) === 4 && json_decode($tt_['esonero_frequenza']['iter_json'], true) === ['protocollo', '@cdl']
    && json_decode($tt_['cancellazione_iscrizione']['iter_json'], true) === ['protocollo', '@cdl'], "Iter: la mobilità passa dal referente di mobilità internazionale; esonero e cancellazione finiscono al referente del corso");
$def_t_ = $it_t_->definizione($tt_['tesi']);
prova(array_column($def_t_, 't') === ['conferma', 'ufficio', 'ufficio'] && $def_t_[0]['campo'] === 'Relatore/trice', "Tesi: prima la conferma del relatore, poi protocollo e referente del corso");
foreach ($tt_ as $chiave_ => $m_) {
    $cm_ = CampiModulo::da($m_['campi_json']);
    $per_ = array_column($cm_, null, 'etichetta');
    $ver_ = json_decode($m_['verbale_json'], true);
    $dom_ = json_decode($m_['domanda_json'], true);
    preg_match_all('/\{([^{}\n]{1,200})\}/u', $ver_['testo'] . ' ' . $dom_['chiede'], $segn_);
    $manc_ = array_filter($segn_[1], fn($s) => !in_array(mb_strtolower($s), ['studente', 'protocollo', 'matricola'], true) && !isset($per_[$s]));
    $senza_cond_ = array_filter($cm_, fn($c) => $c['cond'] === null ? false : $c['cond']['nome'] === '');
    prova(count(array_filter($cm_, fn($c) => $c['tipo'] === 'corso_studio')) === 1 && $dom_['pdf'] === 1 && $ver_['testo'] !== '' && $manc_ === [] && !$senza_cond_,
        "Pratica $chiave_: un solo corso di studio, domanda in PDF, segnaposti e condizioni dei campi validi" . ($manc_ ? ' – mancano: ' . implode(', ', $manc_) : ''));
}
// Tesi: i campi cambiano con il tipo di richiesta e il relatore (docente) è obbligatorio
$ct_ = $c_did->get(\App\Didattica\LetturaRisposte::class);
$tcm_ = CampiModulo::da($tt_['tesi']['campi_json']);
$tp_ = array_column($tcm_, null, 'etichetta');
$base_t_ = function (string $tipo, array $extra) use ($tp_) {
    $b = ['Tipo di richiesta' => $tipo, 'Luogo di nascita' => 'Cosenza', 'Data di nascita' => '2002-01-01', 'Cellulare' => '3331234567', "Iscritto per l'A.A." => '2026/2027', 'Matricola' => '245678',
          'Al Corso di Laurea in' => 'X', 'Relatore/trice' => 'Rossi Mario'];
    $post = [];
    foreach (array_merge($b, $extra) as $et => $v) {
        $post['campo_' . $tp_[$et]['nome']] = $v;
    }
    $post['campo_' . $tp_[array_key_last($tp_)]['nome']] = '1';

    return $post;
};
[$r_, $e_] = $ct_->leggi($tcm_, $base_t_('Variazione della richiesta di tesi', ['Tipo di numerazione' => 'Protocollo', 'Numero di protocollo, decreto o delibera' => '123', 'Data di protocollo, decreto o delibera' => '2026-03-01',
    'Cosa varia' => ['Laboratorio'], 'Nuovo laboratorio' => 'Lab 2']), [], false);
$r_ = array_column($r_, null, 'etichetta');
prova(!empty($r_['Progetto formativo']['nascosto']) && !empty($r_['Nuovo progetto formativo']['nascosto']) && empty($r_['Nuovo laboratorio']['nascosto']) && !empty($r_['Crediti acquisiti']['nascosto'])
    && $r_['Relatore/trice']['valore'] === 'Rossi Mario' && $e_ === [], "Tesi: per una variazione servono numero e data della richiesta e solo i campi di ciò che cambia" . ($e_ ? ' – ' . implode('; ', $e_) : ''));
[, $e_] = $ct_->leggi($tcm_, $base_t_('Richiesta tesi', ['Crediti acquisiti' => '150', 'Progetto formativo' => 'Studio X', 'Sede del lavoro di tesi' => 'Esterna']), [], false);
prova($e_ === [], "Tesi: la richiesta di tesi interna o esterna si compila senza i campi dell'altro tipo" . ($e_ ? ' – ' . implode('; ', $e_) : ''));
[, $e_] = $ct_->leggi($tcm_, $base_t_('Richiesta tesi LMCU Conservazione e Restauro dei Beni Culturali', ['Anno di corso' => '4° anno', 'Progetto formativo' => 'Restauro Y', 'Relatore prova teorico-metodologica (1)' => 'Bianchi Anna', 'Area (1)' => 'A',
    'Relatore prova teorico-metodologica (2)' => 'Verdi Luca', 'Area (2)' => 'B']), [], false);
prova(count($e_) === 1 && str_contains($e_[0], 'abstract'), "Tesi di restauro: serve l'abstract del progetto di tirocinio, non i campi della tesi ordinaria");
// La conferma del relatore mostra le risposte della richiesta, senza i dati personali
$ris_t_ = json_encode([['etichetta' => 'Relatore/trice', 'tipo' => 'docente', 'valore' => 'Rossi Mario'], ['etichetta' => 'Progetto formativo', 'tipo' => 'textarea', 'valore' => 'Studio X'],
    ['etichetta' => 'Cellulare', 'tipo' => 'tel', 'valore' => '333'], ['etichetta' => 'Luogo di nascita', 'tipo' => 'text', 'valore' => 'Cosenza'], ['etichetta' => 'Laboratorio DiBEST', 'tipo' => 'text', 'valore' => 'L1', 'nascosto' => true]]);
$rd_ = $c_did->get(\App\Didattica\ServizioConfermeDocenti::class)->righeDelDocente(['modulo_id' => (int) $tt_['tesi']['id'], 'risposte_json' => $ris_t_], ['nome' => 'Rossi Mario']);
prova(count($rd_) === 1 && array_keys($rd_[0]) === ['Relatore/trice', 'Progetto formativo'], "Conferma del relatore: vede le risposte della richiesta, senza telefono, luogo di nascita e campi nascosti");

// Cruscotto del Direttore: chi lo vede e i gruppi (nessuna eccezione anche senza dati)
$cru_dir_ = $c_did->get(\App\Portale\CruscottoDirettore::class);
$conn->query("UPDATE impostazioni_sistema SET conv_direttore_email = 'Direttore@Unical.it' WHERE id = 1");
prova($cru_dir_->puoVedere(['ruolo_id' => 1, 'email' => 'a@b.it']) && $cru_dir_->puoVedere(['ruolo_id' => 4, 'email' => 'direttore@unical.it']) && !$cru_dir_->puoVedere(['ruolo_id' => 4, 'email' => 'altro@unical.it']) && !$cru_dir_->puoVedere(null),
    "Cruscotto del Direttore: lo vedono gli amministratori e il Direttore indicato nelle impostazioni");
$gr_dir_ = $cru_dir_->gruppi();
prova(array_column($gr_dir_, 'chiave') === ['firme', 'convenzioni_firma', 'pratiche', 'conferme', 'verbali', 'convenzioni', 'tutorato', 'risorse', 'prenotazioni'] && $cru_dir_->riepilogo()['totale'] >= 0,
    "Cruscotto del Direttore: nove gruppi, tutti leggibili");

// Verifica dei backup: l'esportazione del database e lo ZIP dei file si rileggono per intero; un file troncato o senza config.php non passa
$ver_bk_ = new \App\Sistema\Backup\VerificaBackup();
$f_bk_ = sys_get_temp_dir() . '/prova_backup_' . getmypid() . '.sql.gz';
$esp_bk_ = \App\Core\App::per($conn)->get(\App\Sistema\Backup\EsportazioneDatabase::class)->esporta($f_bk_);
$v_bk_ = $ver_bk_->database($f_bk_, (int) $esp_bk_[0]);
prova($esp_bk_ !== null && $v_bk_['ok'] && $v_bk_['tabelle'] === $esp_bk_[0] && $v_bk_['righe'] === $esp_bk_[1], "Backup: l'esportazione del database si rilegge per intero, con le stesse tabelle e righe");
$t_bk_ = file_get_contents($f_bk_);
file_put_contents($f_bk_ . '.tr', substr($t_bk_, 0, intdiv(strlen($t_bk_), 2)));
prova(!$ver_bk_->database($f_bk_ . '.tr')['ok'] && !$ver_bk_->database($f_bk_ . '.manca')['ok'] && !$ver_bk_->database($f_bk_, (int) $esp_bk_[0] + 100)['ok'], "Backup: un file troncato, mancante o con meno tabelle del previsto non passa la verifica");
$zip_bk_ = new ZipArchive();
$zf_bk_ = sys_get_temp_dir() . '/prova_backup_' . getmypid() . '.zip';
$zip_bk_->open($zf_bk_, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip_bk_->addFromString('config.php', '<?php');
$zip_bk_->addFromString('uploads/a.pdf', 'x');
$zip_bk_->close();
prova($ver_bk_->zip($zf_bk_, 2) === null && str_contains((string) $ver_bk_->zip($zf_bk_, 5), '2 file invece di 5') && $ver_bk_->zip($zf_bk_ . '.manca') !== null, "Backup: lo ZIP dei file si apre, ha i file attesi e contiene config.php");
foreach ([$f_bk_, $f_bk_ . '.tr', $zf_bk_] as $x_bk_) {
    @unlink($x_bk_);
}
