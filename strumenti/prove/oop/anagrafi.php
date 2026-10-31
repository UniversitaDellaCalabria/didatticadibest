<?php
// Prove del modulo Anagrafi e catalogo di Ateneo (App\Anagrafi): le funzioni di inc/ come facciate (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). Nessuna rete: solo dati del database di prova.
sezione("Modulo Anagrafi: facciate di inc/");

prova(maiuscole_nome("D'AMICO MARIA") === "D'Amico Maria" && maiuscole_scuola('LICEO SCIENTIFICO E. FERMI') === 'Liceo Scientifico E. Fermi' && maiuscole_corso('SCIENZE GEOLOGICHE') === 'Scienze geologiche', "maiuscole_nome(), maiuscole_scuola(), maiuscole_corso()");
prova(separa_cognome_nome('ROSSI MARIO', 'mario.rossi') === ['Rossi', 'Mario'] && gruppo_personale('PO') === 'docenti' && gruppo_personale('AU', true) === 'docenti' && gruppo_personale('AU') === 'altro', "separa_cognome_nome() e gruppo_personale()");
prova(defined('GRUPPI_PERSONALE') && GRUPPI_PERSONALE === \App\Anagrafi\Anagrafe::GRUPPI_PERSONALE && API_UNICAL === \App\Anagrafi\Anagrafe::API_UNICAL, "costanti globali di prima, con i valori di App\\Anagrafi\\Anagrafe");
prova(anno_accademico_corrente() === ((int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1), "anno_accademico_corrente()");

// Scuole
$q("INSERT IGNORE INTO scuole (codice, denominazione, comune, provincia, regione, tipo, statale) VALUES ('ZZPS99999Z', 'LICEO PROVA ANAGRAFI', 'RENDE', 'COSENZA', 'CALABRIA', 'LICEO', 1)");
$sc = scuola_per_codice($conn, 'zzps99999z');
prova($sc && $sc['denominazione'] === 'LICEO PROVA ANAGRAFI' && scuola_per_codice($conn, 'non valido') === null, "scuola_per_codice(): codice normalizzato, codici non validi respinti");
$ris = cerca_scuole($conn, 'prova anagrafi');
prova(count($ris) === 1 && $ris[0]['codice'] === 'ZZPS99999Z' && $ris[0]['nome'] === 'Liceo Prova Anagrafi – Rende' && $ris[0]['conv'] === [], "cerca_scuole(): nome ufficiale e convenzioni");
prova(array_column(luoghi_scuole($conn, 'comuni', 'calabria', 'cosenza'), 'valore') !== [] && luoghi_scuole($conn, 'xx') === [], "luoghi_scuole()");
$custom = ['scuola' => 'scritta a mano', 'altro' => 'x'];
prova(applica_scuola_scelta($conn, $custom, ['scuola' => 'ZZPS99999Z']) === 'ZZPS99999Z' && $custom['scuola'] === 'Liceo Prova Anagrafi – Rende', "applica_scuola_scelta(): nel campo il nome ufficiale");
$h_sc = html_campo_scuola('scuola', 'Liceo "X"', 'ZZPS99999Z');
prova(str_contains($h_sc, 'name="custom_scuola"') && str_contains($h_sc, 'value="Liceo &quot;X&quot;"') && str_contains($h_sc, 'Scuola dall\'anagrafe del Ministero') && !empty($GLOBALS['usa_campo_scuola']), "html_campo_scuola()");
prova(nome_scuola_prenotazione(['dati_custom_json' => json_encode(['scuola_di_provenienza' => ' Istituto Rossi '])]) === 'Istituto Rossi' && nome_scuola_prenotazione(['dati_custom_json' => '{"scuola":"123"}']) === '', "nome_scuola_prenotazione(): primo campo che parla di scuola");
$q("DELETE FROM scuole WHERE codice = 'ZZPS99999Z'");

// Personale, corsi, insegnamenti, catalogo
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, ruolo_cod, gruppo, docente, attivo, ssd_cod, ssd) VALUES ('prova.anagrafi', 'Anagrafi', 'Prova', 'prova.anagrafi@unical.it', 'Ordinario', 'PO', 'docenti', 1, 1, 'BIO/01', 'Botanica'), ('prova.uscito', 'Uscito', 'Prova', '', 'Tecnico', 'ND', 'pta', 0, 0, '', '')");
$pa = persona_ateneo($conn, 'prova.anagrafi');
prova($pa && $pa['cognome'] === 'Anagrafi' && persona_ateneo($conn, '!!') === null && nome_persona($pa) === 'Prova Anagrafi', "persona_ateneo() e nome_persona()");
$cp = cerca_personale($conn, 'anagrafi', 'docenti');
prova(count($cp) === 1 && $cp[0]['id'] === 'prova.anagrafi' && $cp[0]['gruppo'] === 'Docenti' && $cp[0]['link'] === 'https://www.unical.it/storage/teachers/prova.anagrafi/' && cerca_personale($conn, '') === [], "cerca_personale(): parole e filtri, serve almeno un filtro");
prova(salva_modifiche_persona($conn, 'prova.anagrafi', ['telefono' => 'ab']) !== null && salva_modifiche_persona($conn, 'prova.anagrafi', ['telefono' => '0984 49', 'sito' => 'esempio.it', 'bio' => '<b>Profilo</b>']) === null, "salva_modifiche_persona(): validazione e salvataggio");
$sch = scheda_persona($conn, $pa);
prova($sch['valori']['telefono'] === '0984 49' && $sch['valori']['sito'] === 'https://esempio.it' && $sch['valori']['bio'] === 'Profilo' && count($sch['modificati']) === 3 && modifiche_persona($conn, 'prova.anagrafi')['telefono'] === '0984 49', "scheda_persona() e modifiche_persona()");
$ref = html_referente_pubblico($conn, ['persona_id' => 'prova.anagrafi', 'ruolo' => 'Referente', 'email' => 'x@y.it'], '#123456');
prova(str_contains($ref, 'persona.php?id=prova.anagrafi') && str_contains($ref, 'Prova Anagrafi') && str_contains($ref, 'mailto:x@y.it'), "html_referente_pubblico(): porta alla pagina della persona");
prova(str_contains(html_ricerca_personale($conn), 'ricerca-personale') && str_contains(html_avatar_persona(null, 'x'), '<svg'), "html_ricerca_personale() e html_avatar_persona()");
$av = avvisi_anagrafe($conn);
prova(isset($av['gestori'], $av['referenti']), "avvisi_anagrafe()");

$q("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, visibile, presente, regdid_id) VALUES ('ZZ001', 'Corso prova anagrafi', 'L', 'Laurea', 1, 1, 4242), ('ZZ002', 'Corso nascosto', 'LM', 'Laurea Magistrale', 0, 1, NULL)");
$cv = corsi_studio_visibili($conn);
$trovato = false; foreach ($cv['Laurea'] ?? [] as $c) if ($c['codice'] === 'ZZ001') $trovato = true;
prova($trovato && !array_filter($cv, fn($g) => array_filter($g, fn($c) => $c['codice'] === 'ZZ002')), "corsi_studio_visibili(): solo visibili, per tipo");
prova(corso_studio($conn, 'ZZ001')['regdid_id'] == 4242 && url_corso_studio(corso_studio($conn, 'ZZ001')) === 'https://www.unical.it/storage/cds/4242/' && nome_scheda_corso(['tipo' => 'L', 'nome' => 'Biologia']) === 'Corso di laurea in Biologia', "corso_studio(), url_corso_studio(), nome_scheda_corso()");
prova(html_corso_pubblico($conn, ['struttura' => '', 'corso_codice' => 'ZZ001']) !== '' && str_contains(html_corso_pubblico($conn, ['struttura' => 'Il corso', 'corso_codice' => 'ZZ001']), 'storage/cds/4242') && html_corso_pubblico($conn, ['struttura' => '']) === '', "html_corso_pubblico()");
prova(str_contains(html_campo_corso($conn, 'corso', 'Corso prova anagrafi (Laurea)'), 'selected') && str_contains(html_scelta_corso_scheda($conn, ['corso_codice' => 'ZZ001', 'struttura' => 'testo']), 'name="corsi_codici[]" value="ZZ001"'), "html_campo_corso() e html_scelta_corso_scheda()");
// Più corsi di studio per attività
$sc_corsi = html_scelta_corso_scheda($conn, ['corsi_codici' => 'ZZ001,ZZ002', 'corso_codice' => 'ZZ001', 'struttura' => 'Altra struttura'], 'provaCorsi');
prova(substr_count($sc_corsi, 'name="corsi_codici[]"') === 3 && str_contains($sc_corsi, 'value="ZZ002"') && str_contains($sc_corsi, 'Corso di laurea magistrale in Corso nascosto') && str_contains($sc_corsi, 'value="Altra struttura"') && str_contains($sc_corsi, 'Aggiungi corso'),
    "scheda dell'attività: più corsi di studio scelti (anche uno non più tra i visibili) e il testo libero a parte");
prova(!str_contains(html_scelta_corso_scheda($conn, ['corso_codice' => 'ZZ001', 'struttura' => 'Corso di laurea in Corso prova anagrafi']), 'value="Corso di laurea in Corso prova anagrafi"'), "scheda dell'attività: il vecchio testo uguale al nome del corso non si ripete");
$pub_corsi = html_corso_pubblico($conn, ['struttura' => '', 'corsi_codici' => 'ZZ001,ZZ002', 'corso_codice' => 'ZZ001']);
prova(str_contains($pub_corsi, 'Corso di laurea in Corso prova anagrafi') && str_contains($pub_corsi, 'storage/cds/4242') && str_contains($pub_corsi, 'Corso di laurea magistrale in Corso nascosto'), "scheda pubblica: ogni corso scelto, con il link alla sua pagina");
$pub_testo = html_corso_pubblico($conn, ['struttura' => 'Dipartimento DiBEST', 'corsi_codici' => 'ZZ001', 'corso_codice' => 'ZZ001']);
prova(str_starts_with($pub_testo, 'Dipartimento DiBEST') && str_contains($pub_testo, 'Corso di laurea in Corso prova anagrafi'), "scheda pubblica: il testo della struttura e poi i corsi");
$q("DELETE FROM progetti_dettagli WHERE evento_id = 98999");
App\Core\App::per($conn)->get(App\Eventi\ServizioEventi::class)->salvaCorso(98999, ['struttura' => '', 'corsi_codici' => ['ZZ002', 'ZZ001', 'ZZ002', 'XX999']]);
$riga_corsi = $conn->query("SELECT corso_codice, corsi_codici FROM progetti_dettagli WHERE evento_id = 98999")->fetch_assoc();
prova($riga_corsi['corso_codice'] === 'ZZ002' && $riga_corsi['corsi_codici'] === 'ZZ002,ZZ001', "salvataggio dei corsi dell'attività: elenco validato, senza doppioni né codici sconosciuti", json_encode($riga_corsi));
$q("DELETE FROM progetti_dettagli WHERE evento_id = 98999");

$q("INSERT INTO insegnamenti (id, nome, cds_nome, cds_cod, anno_accademico, coorte, anno_corso, presente, partizione, cfu, ssd_cod, docente, semestre) VALUES (9901, 'Insegnamento prova', 'Corso prova anagrafi', 'ZZ001', 2025, 2025, 1, 1, '', 6, 'BIO/01', 'Rossi', 'Primo')");
prova(insegnamento($conn, 9901)['nome'] === 'Insegnamento prova' && insegnamento($conn, 0) === null && isset(insegnamenti_per_corso($conn, 2025)['Corso prova anagrafi']), "insegnamento() e insegnamenti_per_corso()");
prova(etichetta_insegnamento(insegnamento($conn, 9901)) === 'Insegnamento prova · 1° anno · Primo · Rossi', "etichetta_insegnamento()");
prova(isset(insegnamenti_dipartimento_scelta($conn)[9901]) || insegnamenti_per_corso($conn) !== [], "insegnamenti_dipartimento_scelta()");
$q("INSERT IGNORE INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento) VALUES ('ZZ001', 2025, 'Corso prova anagrafi', 'L', 'Laurea', 'Dip')");
prova(isset(catalogo_tipi_corso($conn)['L']) && catalogo_anni($conn, 'L') !== [] && catalogo_corso($conn, 'ZZ001')['nome'] === 'Corso prova anagrafi' && array_filter(catalogo_corsi($conn, 'L', 2025), fn($c) => $c['codice'] === 'ZZ001'), "catalogo_tipi_corso(), catalogo_anni(), catalogo_corso(), catalogo_corsi()");
$n = salva_catalogo_insegnamenti($conn, 'ZZ001', 2025, [['StudyActivityID' => 9902, 'StudyActivityName' => 'MATERIA PROVA', 'StudyActivityCdSCod' => 'ZZ001', 'StudyActivityYear' => 2, 'StudyActivityCFU' => '9,5'], ['StudyActivityID' => 9903, 'StudyActivityName' => 'ALTRO', 'StudyActivityCdSCod' => 'YY']]);
$ci = catalogo_insegnamenti($conn, 'ZZ001', 2025, false);
prova($n === 1 && count($ci) === 1 && $ci[0]['nome'] === 'Materia prova' && (float)$ci[0]['cfu'] === 9.5 && catalogo_insegnamenti($conn, 'ZZ001', 1800, false) === [], "salva_catalogo_insegnamenti() e catalogo_insegnamenti()");

// Insegnamenti di tutto l'Ateneo: tutti i campi dell'API, integrato con i suoi moduli, CFU dal dettaglio, link
$ap = new class implements \App\Anagrafi\ClientApiAteneo {
    public array $elenchi = [];
    public array $dettagli = [];
    public function get(string $percorso, array $query = [], int $timeout = 25): ?array { return $this->dettagli[$percorso] ?? null; }
    public function tutte(string $percorso, array $query = []): ?array { $k = $percorso . '|' . ($query['academic_year'] ?? '') . '|' . ($query['department'] ?? ''); return array_key_exists($k, $this->elenchi) ? $this->elenchi[$k] : []; }
    public function scarica(string $url, int $timeout = 10): ?string { return null; }
};
$aa_i = anno_accademico_corrente();
$riga_i = fn (int $id, string $nome, string $dip, string $cds, array $extra = []): array => $extra + ['StudyActivityID' => $id, 'StudyActivityName' => $nome, 'StudyActivityCod' => "27$id", 'StudyActivityCdSCod' => $cds,
    'StudyActivityCdSName' => 'CORSO ' . $cds, 'DepartmentCod' => $dip, 'DepartmentName' => 'Dipartimento ' . $dip, 'StudyActivityYear' => 1.0, 'StudyActivitySemester' => 'Primo Semestre',
    'StudyActivitySSDCod' => 'FIS-01/A', 'StudyActivitySSD' => 'Fisica sperimentale', 'StudyActivityTeacherName' => 'ROSSI MARIO', 'StudyActivityTeacherID' => 'mario.rossi', 'StudyActivityFathers' => []];
$padre_i = [['FatherID' => 9950, 'FatherName' => 'FISICA E CHIMICA (INTEGRATO)', 'RegdidId' => 1]];
$ap->elenchi['activities/|' . $aa_i . '|'] = [
    $riga_i(9951, 'FISICA', 'ZD1', 'ZC1', ['StudyActivityFathers' => $padre_i]),
    $riga_i(9952, 'CHIMICA', 'ZD1', 'ZC1', ['StudyActivityFathers' => $padre_i]),
    $riga_i(9953, 'ANATOMIA', 'ZD2', 'ZC2'),
];
$ap->dettagli['activities/9951/'] = ['results' => ['StudyActivityID' => 9951, 'StudyActivityECTS' => 6.0]];
$ap->dettagli['activities/9952/'] = ['results' => ['StudyActivityID' => 9952, 'StudyActivityECTS' => 3]];
$ap->dettagli['activities/9953/'] = ['results' => ['StudyActivityID' => 9953, 'StudyActivityECTS' => null]];
$q("DELETE FROM insegnamenti WHERE id IN (9950, 9951, 9952, 9953)"); $q("DELETE FROM ateneo_insegnamenti WHERE id IN (9951, 9952, 9953)");
$cont_i = \App\Core\App::per($conn);
$db_i = \App\Core\Database::per($conn);
$cat_i = new \App\Anagrafi\ServizioCatalogo($cont_i->get(\App\Anagrafi\CatalogoRepository::class), $cont_i->get(\App\Anagrafi\CorsoRepository::class), $cont_i->get(\App\Anagrafi\InsegnamentoRepository::class), $ap, $cont_i->get(\App\Core\Orologio::class));
$sin_i = new \App\Anagrafi\SincronizzazioneAnagrafe($db_i, $ap, $cont_i->get(\App\Anagrafi\StruttureRepository::class), $cont_i->get(\App\Anagrafi\PersonaRepository::class), $cont_i->get(\App\Anagrafi\CorsoRepository::class),
    $cont_i->get(\App\Anagrafi\InsegnamentoRepository::class), $cat_i, $cont_i->get(\App\Anagrafi\CollegamentoUtente::class), $cont_i->get(\App\Core\Sito::class));
$n_i = $sin_i->sincronizzaInsegnamenti();
$i1 = $conn->query("SELECT * FROM insegnamenti WHERE id = 9951")->fetch_assoc();
prova($n_i >= 3 && (int)$conn->query("SELECT COUNT(*) c FROM insegnamenti WHERE id IN (9951, 9952, 9953) AND presente = 1")->fetch_assoc()['c'] === 3, "sincronizzazione: insegnamenti di tutto l'Ateneo, anche di dipartimenti diversi");
prova($i1['codice'] === '279951' && $i1['nome'] === 'Fisica' && $i1['cds_cod'] === 'ZC1' && $i1['semestre'] === 'Primo Semestre' && $i1['ssd_cod'] === 'FIS-01/A' && $i1['ssd'] === 'Fisica sperimentale'
    && $i1['docente'] === 'Rossi Mario' && $i1['dipartimento_cod'] === 'ZD1' && $i1['dipartimento'] === 'Dipartimento ZD1' && (int)$i1['anno_accademico'] === $aa_i, "sincronizzazione: codice, descrizione, SSD, semestre, docente, corso e dipartimento", json_encode($i1, JSON_UNESCAPED_UNICODE));
prova((int)$i1['padre_id'] === 9950 && $i1['padre_nome'] === 'Fisica e chimica (integrato)' && $i1['link'] === 'https://www.unical.it/storage/activities/9951/', "sincronizzazione: integrato di cui è modulo e link alla pagina dell'insegnamento", json_encode($i1, JSON_UNESCAPED_UNICODE));
prova((int)$conn->query("SELECT COUNT(*) c FROM ateneo_insegnamenti WHERE id IN (9951, 9952, 9953)")->fetch_assoc()['c'] === 3 && $conn->query("SELECT padre_nome FROM ateneo_insegnamenti WHERE id = 9952")->fetch_assoc()['padre_nome'] === 'Fisica e chimica (integrato)', "sincronizzazione: anche il catalogo dei moduli ha l'integrato");
prova($i1['cfu'] === null && $i1['dettaglio_il'] === null, "i CFU non arrivano dall'elenco: restano da scaricare dal dettaglio");
$fatti_i = $cat_i->completaDettagli(30, 3);
$i1 = $conn->query("SELECT * FROM insegnamenti WHERE id = 9951")->fetch_assoc();
prova($fatti_i >= 3 && (float)$i1['cfu'] === 6.0 && $i1['dettaglio_il'] !== null && (float)$conn->query("SELECT cfu FROM ateneo_insegnamenti WHERE id = 9952")->fetch_assoc()['cfu'] === 3.0 && $conn->query("SELECT cfu FROM insegnamenti WHERE id = 9953")->fetch_assoc()['cfu'] === null, "dettaglio: CFU scaricati dalla scheda dell'attività (senza CFU indicati restano vuoti)");
$sin_i->sincronizzaInsegnamenti();
$pi_i = $conn->query("SELECT * FROM insegnamenti WHERE id = 9950")->fetch_assoc();
prova($pi_i && (int)$pi_i['e_integrato'] === 1 && $pi_i['nome'] === 'Fisica e chimica (integrato)' && $pi_i['cds_cod'] === 'ZC1' && $pi_i['dipartimento_cod'] === 'ZD1' && (float)$pi_i['cfu'] === 9.0 && $pi_i['padre_id'] === null, "l'integrato è una riga dell'archivio, con i CFU dei suoi moduli", json_encode($pi_i, JSON_UNESCAPED_UNICODE));
$rep_i0 = $cont_i->get(\App\Anagrafi\InsegnamentoRepository::class);
$sc_i = array_column($rep_i0->dellAnno($aa_i, 'ZD1', 'scelta'), 'id');
prova(in_array(9950, array_map('intval', $sc_i), true) && !in_array(9951, array_map('intval', $sc_i), true) && !in_array(9950, array_map('intval', array_column($rep_i0->dellAnno($aa_i, 'ZD1'), 'id')), true) && count($rep_i0->dellAnno($aa_i, 'ZD1', 'tutti')) === 3, "le scelte propongono l'integrato e non i suoi moduli; l'elenco di prima non cambia");
$gr_i = \App\Anagrafi\Testi::raggruppaIntegrati($cat_i->insegnamenti('ZC1', $aa_i, false));
prova(count($gr_i) === 1 && $gr_i[0]['integrato'] === true && $gr_i[0]['id'] === 9950 && $gr_i[0]['cfu'] === 9.0 && count($gr_i[0]['moduli']) === 2 && $gr_i[0]['ssd'] === 'FIS-01/A' && $gr_i[0]['moduli'][0]['link'] === 'https://www.unical.it/storage/activities/' . $gr_i[0]['moduli'][0]['id'] . '/', "catalogo dei moduli: l'integrato si sceglie intero con i suoi moduli", json_encode($gr_i, JSON_UNESCAPED_UNICODE));
prova(\App\Anagrafi\Testi::etichettaInsegnamentoCompleta($pi_i) === 'Fisica e chimica (integrato) [integrato] · 9 CFU · FIS-01/A · Primo Semestre · 1° anno · Corso zc1 (ZC1)' && !str_contains(\App\Anagrafi\Testi::etichettaInsegnamentoCompleta($i1), 'Rossi'), "etichetta dei pannelli: codice, CFU, SSD, semestre, corso con codice, senza docente", \App\Anagrafi\Testi::etichettaInsegnamentoCompleta($pi_i));
prova(\App\Didattica\CampiModulo::tipoColonnaDaNome('Semestre') === 'semestre' && \App\Didattica\CampiModulo::tipoColonnaDaNome('Codice corso di laurea') === 'codice_corso' && \App\Didattica\CampiModulo::tipoColonnaDaNome('Corso di laurea') === 'corso' && \App\Didattica\CampiModulo::tipoColonnaDaNome('Link insegnamento') === 'link' && \App\Didattica\CampiModulo::tipoColonnaDaNome('Codice insegnamento') === 'codice', "colonne delle tabelle dei moduli: semestre, corso, codice del corso e link si riconoscono dal nome");
prova((float)$conn->query("SELECT cfu FROM insegnamenti WHERE id = 9951")->fetch_assoc()['cfu'] === 6.0 && (float)$conn->query("SELECT cfu FROM ateneo_insegnamenti WHERE id = 9951")->fetch_assoc()['cfu'] === 6.0, "una nuova sincronizzazione non cancella i CFU già scaricati");
$ap->elenchi['activities/|' . $aa_i . '|'] = [$riga_i(9951, 'FISICA', 'ZD1', 'ZC1')];
$sin_i->sincronizzaInsegnamenti();
prova($conn->query("SELECT presente FROM insegnamenti WHERE id = 9952")->fetch_assoc()['presente'] == 0 && (int)$conn->query("SELECT presente FROM insegnamenti WHERE id = 9951")->fetch_assoc()['presente'] === 1, "un insegnamento che l'Ateneo non pubblica più è segnato non presente");
$rep_i = $cont_i->get(\App\Anagrafi\InsegnamentoRepository::class);
prova(count($rep_i->elenco($aa_i, 'ZC1', 0, '', '', 'ZD2')) === 0 && count($rep_i->elenco($aa_i, '', 0, '', 'zc1', 'ZD1')) === 1 && in_array('ZD1', array_column($rep_i->dipartimentiDellAnno($aa_i), 'dipartimento_cod'), true), "elenco per dipartimento o per tutto l'Ateneo");
$ap->elenchi['activities/|' . $aa_i . '|'] = null;
prova($sin_i->sincronizzaInsegnamenti() === null && (int)$conn->query("SELECT presente FROM insegnamenti WHERE id = 9951")->fetch_assoc()['presente'] === 1, "API non raggiungibili: nessun insegnamento cancellato");
$q("DELETE FROM insegnamenti WHERE id IN (9950, 9951, 9952, 9953)"); $q("DELETE FROM ateneo_insegnamenti WHERE id IN (9951, 9952, 9953)"); $q("DELETE FROM ateneo_insegnamenti_scaricati WHERE cds_cod IN ('ZC1', 'ZC2')");

// Abilitazioni
$q("INSERT INTO pagine_eventi (id, titolo, slug, permessi_gestori_json) VALUES (9980, 'Area anagrafi', 'area-anagrafi', '{}')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (9981, 9980, 'Evento A', 'evento')");
assegna_permessi_gestore($conn, 9980, 9971, ['full']);
prova(db_valore($conn, "SELECT permessi_gestori_json FROM pagine_eventi WHERE id = 9980") === '{"9971":["full"]}', "assegna_permessi_gestore(): tutta l'area");
prova(applica_abilitazione($conn, 9972, 'attivita', 9980, [9981], 1) && str_contains((string)db_valore($conn, "SELECT permessi_gestori_json FROM eventi WHERE id = 9981"), '"9972"') && !applica_abilitazione($conn, 9972, 'attivita', 9980), "applica_abilitazione(): singole attività");
revoca_permessi_gestore($conn, 9980, 9972);
prova(!str_contains((string)db_valore($conn, "SELECT permessi_gestori_json FROM eventi WHERE id = 9981"), '9972'), "revoca_permessi_gestore()");
$q("DELETE FROM eventi WHERE id = 9981"); $q("DELETE FROM pagine_eventi WHERE id = 9980");

// Collegamento al login
$ids_g = ids_gruppi_personale($conn);
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (9970, 'Prova', 'Anagrafi', 'prova.anagrafi@unical.it', 5, '')");
$sec = collega_utente_anagrafe($conn, 9970, '');
prova(db_valore($conn, "SELECT persona_id FROM utenti WHERE id = 9970") === 'prova.anagrafi' && (!isset($ids_g['docenti']) || $sec === (string)$ids_g['docenti']), "collega_utente_anagrafe(): persona e gruppo automatico");
prova(collega_utente_anagrafe($conn, 99999999) === null, "collega_utente_anagrafe(): utente inesistente");
$q("DELETE FROM personale_ateneo WHERE id = 'prova.anagrafi'");
scollega_utenti_senza_persona($conn);
prova(db_valore($conn, "SELECT persona_id FROM utenti WHERE id = 9970") === null, "scollega_utenti_senza_persona()");
$q("DELETE FROM utenti WHERE id = 9970"); $q("DELETE FROM personale_ateneo WHERE id = 'prova.uscito'"); $q("DELETE FROM personale_modifiche WHERE persona_id = 'prova.anagrafi'");
$q("DELETE FROM corsi_studio WHERE codice IN ('ZZ001', 'ZZ002')"); $q("DELETE FROM insegnamenti WHERE id = 9901");
$q("DELETE FROM ateneo_cds WHERE codice = 'ZZ001'"); $q("DELETE FROM ateneo_insegnamenti WHERE cds_cod = 'ZZ001'"); $q("DELETE FROM ateneo_insegnamenti_scaricati WHERE cds_cod = 'ZZ001'");
