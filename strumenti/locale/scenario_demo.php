<?php
// =========================================================================
// Scenario di dimostrazione per l'ambiente locale (schermate delle guide e delle presentazioni): persone, uffici, pratiche in vari punti dell'iter,
// una seduta con verbale, risorse con prenotazioni, liste d'attesa e scadenze. Dati inventati (example.org).
//   php -d extension=zip -d extension=gd -d extension=intl -d extension=mysqli strumenti/locale/scenario_demo.php
// Si lancia dopo avvia.sh --nuovo. È ripetibile: ogni volta ricrea lo scenario (cancella le pratiche, le sedute e le risorse di dimostrazione).
// =========================================================================
if (PHP_SAPI !== 'cli') {
    exit;
}
chdir(realpath(__DIR__ . '/../..'));
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SESSION = [];
define('AMBIENTE_LOCALE_CLI', 1);
$e = parse_ini_file('.env.locale');
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($e['DB_HOST'], $e['DB_USER'], $e['DB_PASS'], $e['DB_NAME']);
$conn->set_charset('utf8mb4');
// Le email non partono (da riga di comando sarebbe lento e inutile)
$EMAIL = [];
function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null, array $allegati = []) { global $EMAIL; $EMAIL[] = ['a' => $to, 'oggetto' => $subject]; return true; }
require 'functions.php';
assicura_schema($conn);
\App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->preparaFlusso(); // applica anche le correzioni dei dati di partenza dei moduli

$c = \App\Core\App::per($conn);
$db = $c->get(\App\Core\Database::class);
$q = fn(string $sql) => $conn->query($sql) ?: print("ERRORE SQL: {$conn->error}\n  $sql\n");

// ── Pulizia dello scenario precedente ──
foreach (['pratiche_eventi', 'pratiche_firme', 'pratiche_conferme', 'pratiche_operatori', 'pratiche', 'didattica_sedute_presenze', 'didattica_sedute_punti', 'didattica_sedute',
          'prenotazioni_risorse_attesa', 'prenotazioni_risorse', 'risorse_scadenze', 'risorse_orari', 'risorse_responsabili', 'risorse', 'ufficio_didattica', 'personale_ateneo', 'corsi_studio'] as $t) {
    @$conn->query("DELETE FROM $t");
}
$q("DELETE FROM utenti WHERE id >= 5");
$q("DELETE FROM pagine_eventi WHERE id = 20");

// ── Persone (si entra da /__accesso) ──
$q("INSERT INTO utenti (id, ruolo_id, ruoli_secondari, nome, cognome, email, codice_fiscale, matricola_studente) VALUES
 (5, 3, '', 'Luca', 'Rossi', 'luca.rossi@example.org', 'RSSLCU02A01D086X', '245678'),
 (6, 4, '', 'Anna', 'Verdi', 'anna.verdi@example.org', 'VRDNNA78C41D086Y', NULL),
 (7, 4, '', 'Mario', 'Bianchi', 'mario.bianchi@example.org', 'BNCMRA65A01D086Z', NULL),
 (8, 4, '', 'Anna', 'Protocollo', 'anna.protocollo@example.org', 'PRTNNA80A41D086A', NULL),
 (9, 4, '', 'Marco', 'Referente', 'marco.referente@example.org', 'RFRMRC76A01D086B', NULL),
 (10, 4, '', 'Giulia', 'Responsabile', 'giulia.responsabile@example.org', 'RSPGLI82A41D086C', NULL),
 (11, 4, '', 'Chiara', 'Internazionale', 'chiara.internazionale@example.org', 'NTRCHR85A41D086D', NULL),
 (12, 4, '', 'Gianna', 'Manager', 'gianna.manager@example.org', 'MNGGNN70A41D086E', NULL)");
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, gruppo, docente, attivo) VALUES
 ('p.verdi', 'Verdi', 'Anna', 'anna.verdi@example.org', 'Professore associato', 'docenti', 1, 1),
 ('p.neri', 'Neri', 'Giulia', 'giulia.neri@example.org', 'Professore ordinario', 'docenti', 1, 1),
 ('p.gialli', 'Gialli', 'Paolo', 'paolo.gialli@example.org', 'Ricercatore', 'docenti', 1, 1),
 ('p.bianchi', 'Bianchi', 'Mario', 'mario.bianchi@example.org', 'Professore ordinario', 'docenti', 1, 1),
 ('p.resp', 'Responsabile', 'Giulia', 'giulia.responsabile@example.org', 'Professore associato', 'docenti', 1, 1)");
$q("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, visibile, presente) VALUES
 ('B01', 'Corso di laurea in Biologia', 'L', 'Corsi di laurea', 1, 1), ('B02', 'Corso di laurea in Scienze e Tecnologie Biologiche', 'L', 'Corsi di laurea', 1, 1),
 ('B03', 'Corso di laurea magistrale in Biologia', 'LM', 'Corsi di laurea magistrale', 1, 1), ('N01', 'Corso di laurea in Scienze Naturali e Ambientali', 'L', 'Corsi di laurea', 1, 1),
 ('C01', 'Corso di laurea magistrale a ciclo unico in Conservazione e Restauro dei Beni Culturali', 'LM5', 'Corsi di laurea magistrale a ciclo unico', 1, 1)");
$q("UPDATE didattica_consigli SET corsi = '[\"Corso di laurea in Biologia\", \"Corso di laurea in Scienze e Tecnologie Biologiche\", \"Corso di laurea magistrale in Biologia\"]' WHERE id = 4");
$q("UPDATE didattica_consigli SET corsi = '[\"Corso di laurea in Scienze Naturali e Ambientali\"]' WHERE id = 1");
$q("UPDATE didattica_consigli SET corsi = '[\"Corso di laurea magistrale a ciclo unico in Conservazione e Restauro dei Beni Culturali\"]' WHERE id = 5");
$q("UPDATE impostazioni_sistema SET conv_direttore_nome = 'Mario Bianchi', conv_direttore_email = 'mario.bianchi@example.org', conv_direttore_cf = 'BNCMRA65A01D086Z' WHERE id = 1");

// ── Uffici e persone ──
$uff = fn(string $chiave): int => (int) $db->valore('SELECT id FROM didattica_uffici WHERE chiave = ?', [$chiave]);
$op = fn(string $nome, string $email, string $compiti, string $ufficio, ?string $corsi = null) => $db->inserisci('INSERT INTO ufficio_didattica (nominativo, email, compiti, ufficio_id, corsi, ruolo) VALUES (?, ?, ?, ?, ?, ?)', [$nome, $email, $compiti, $uff($ufficio), $corsi, 'Ufficio didattico']);
$op('Anna Protocollo', 'anna.protocollo@example.org', 'pratiche', 'protocollo');
$op('Marco Referente', 'marco.referente@example.org', 'pratiche,sedute', 'cdl_4', '["Corso di laurea in Biologia", "Corso di laurea in Scienze e Tecnologie Biologiche", "Corso di laurea magistrale in Biologia"]');
$op('Chiara Internazionale', 'chiara.internazionale@example.org', 'pratiche', 'internazionalizzazione');
$op('Gianna Manager', 'gianna.manager@example.org', 'pratiche,sedute,ricevimento,bandi', 'manager');
$op('Gianna Manager', 'gianna.manager@example.org', 'pratiche', 'attestati');
foreach (['tesi', 'certificato', 'diploma_supplement', 'convalida_inglese', 'esonero_inglese', 'esonero_frequenza', 'modifica_piano', 'mobilita_svolgimento', 'mobilita_variazione', 'mobilita_fine',
          'trasferimento_entrata', 'attestato_frequenza', 'attestato_esame', 'cancellazione_iscrizione', 'cambio_curriculum', 'convalida_esami'] as $k) {
    $q("UPDATE didattica_moduli SET attivo = 1 WHERE chiave = '$k'");
}

// ── Pratiche ──
$studente = $db->riga('SELECT * FROM utenti WHERE id = 5');
$modulo = fn(string $k) => $db->riga('SELECT * FROM didattica_moduli WHERE chiave = ?', [$k]);
$r = fn(string $et, string $tipo, string $v) => ['etichetta' => $et, 'tipo' => $tipo, 'valore' => $v];
$anagrafica = [$r('Luogo di nascita', 'text', 'Cosenza (CS)'), $r('Data di nascita', 'date', '2002-01-01'), $r('Cellulare', 'tel', '3331234567'), $r("Iscritto per l'A.A.", 'anno_accademico', '2026/2027'),
               $r('Matricola', 'text', '245678'), $r('Al Corso di Laurea in', 'corso_studio', 'Corso di laurea in Biologia')];
$dich = $r('Dichiaro di aver letto e compreso quanto scritto sopra e di assumermi ogni responsabilità su quanto dichiarato', 'dichiarazione', 'Sì');
$pratiche = $c->get(\App\Didattica\ServizioPratiche::class);
$flusso = $c->get(\App\Didattica\ServizioFlussoConvalide::class);
$marche = $c->get(\App\Didattica\ServizioMarcheBollo::class);
$invecchia = fn(int $id, int $giorni) => $q("UPDATE pratiche SET creata_il = DATE_SUB(NOW(), INTERVAL $giorni DAY), aggiornata_il = DATE_SUB(NOW(), INTERVAL $giorni DAY) WHERE id = $id");
$pdf = function (): array {
    // Un PDF vero di una pagina (così il visualizzatore del portale lo mostra): oggetti e tabella dei riferimenti scritti a mano
    $testo = "BT /F1 20 Tf 70 760 Td (CERTIFICATO DI ISCRIZIONE) Tj 0 -34 Td /F1 12 Tf (Esempio generato dall'ambiente di prova - Dipartimento di Biologia, Ecologia e Scienze della Terra) Tj 0 -22 Td (Si certifica che Luca Rossi, matricola 245678, e iscritto per l'a.a. 2026/2027.) Tj ET";
    $ogg = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            "<< /Length " . strlen($testo) . " >>
stream
$testo
endstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $out = "%PDF-1.4
";
    $pos = [];
    foreach ($ogg as $i => $o) {
        $pos[] = strlen($out);
        $out .= ($i + 1) . " 0 obj
$o
endobj
";
    }
    $xref = strlen($out);
    $out .= "xref
0 " . (count($ogg) + 1) . "
0000000000 65535 f 
";
    foreach ($pos as $x) {
        $out .= sprintf("%010d 00000 n 
", $x);
    }
    $out .= "trailer
<< /Size " . (count($ogg) + 1) . " /Root 1 0 R >>
startxref
$xref
%%EOF
";
    $tmp = tempnam(sys_get_temp_dir(), 'cert');
    file_put_contents($tmp, $out);

    return ['name' => 'certificato.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($out)];
};
$ID = [];

// A. Tesi: aspetta la conferma del relatore
$ID['tesi'] = $pratiche->crea($modulo('tesi'), $studente, array_merge([$r('Tipo di richiesta', 'select', 'Richiesta tesi')], $anagrafica, [
    $r('Crediti acquisiti', 'number', '150'), $r('Progetto formativo', 'textarea', 'Studio della diversità dei batteri del suolo in ambienti mediterranei'), $r('Sede del lavoro di tesi', 'radio', 'Interna'),
    $r('Laboratorio DiBEST', 'text', 'Laboratorio di Microbiologia'), $r('Relatore/trice', 'docente', 'Verdi Anna'), $dich]));
// B. Convalida di inglese: protocollata, all'ufficio del corso
$ID['inglese'] = $pratiche->crea($modulo('convalida_inglese'), $studente, array_merge($anagrafica, [$r('Esame oggetto della richiesta', 'radio', 'English for Basic Academic Skills'), $dich]));
$flusso->registraProtocollo($ID['inglese'], '3345/2026', date('Y-m-d', strtotime('-3 days')), 8, 'Anna Protocollo', true);
// C. Esonero dalla frequenza: ferma al protocollo da dodici giorni
$ID['esonero'] = $pratiche->crea($modulo('esonero_frequenza'), $studente, array_merge($anagrafica, [$r('Motivo dell\'esonero', 'radio', 'Motivi di salute'), $dich]));
$invecchia($ID['esonero'], 12);
// D. Mobilità: dall'ufficio Internazionalizzazione da dieci giorni
$ID['mobilita'] = $pratiche->crea($modulo('mobilita_svolgimento'), $studente, array_merge([$r('Email personale', 'email', 'luca.rossi@example.org')], $anagrafica, [
    $r('Programma', 'radio', 'Studio'), $r('Sede ospitante', 'text', 'Universidad de Granada (Spagna)'), $r('Periodo dal', 'date', '2027-02-01'), $r('Periodo al', 'date', '2027-07-31'),
    $r('Attività all\'estero', 'multicheck', 'Frequentare le lezioni e sostenere gli esami'), $r('Coordinatore Erasmus del corso (Prof.)', 'text', 'Giulia Neri'), $r('Del corso di studio in', 'text', 'Biologia'), $dich]));
$flusso->registraProtocollo($ID['mobilita'], '3301/2026', date('Y-m-d', strtotime('-9 days')), 8, 'Anna Protocollo');
$invecchia($ID['mobilita'], 10);
// E. Certificato di iscrizione: pagamento delle marche registrato, in attesa della firma del Direttore
$ID['certificato'] = $pratiche->crea($modulo('certificato'), $studente, array_merge([$r('Tipo di certificato', 'select', 'Iscrizione')], $anagrafica, [
    $r('Codice fiscale', 'codice_fiscale', 'RSSLCU02A01D086X'), $r('Email personale', 'email', 'luca.rossi@example.org'), $r('Certificati di iscrizione richiesti', 'multicheck', 'Iscrizione, Iscrizione con esami'), $dich]));
$flusso->registraProtocollo($ID['certificato'], '3290/2026', date('Y-m-d', strtotime('-8 days')), 8, 'Anna Protocollo');
$marche->avvisaMarche($ID['certificato'], 9, 'Marco Referente');
$marche->registraPagamento($ID['certificato'], $pdf(), true, 9, 'Marco Referente');
$q("UPDATE pratiche_firme SET richiesta_il = DATE_SUB(NOW(), INTERVAL 9 DAY) WHERE pratica_id = {$ID['certificato']}");
// E2. Certificato di laurea: all'ufficio del corso, in attesa del pagamento delle marche da bollo (le marche sono già state caricate su Esse3)
$ID['laurea'] = $pratiche->crea($modulo('certificato'), $studente, array_merge([$r('Tipo di certificato', 'select', 'Laurea')], $anagrafica, [
    $r('Codice fiscale', 'codice_fiscale', 'RSSLCU02A01D086X'), $r('Email personale', 'email', 'luca.rossi@example.org'), $r("Laureato nell'A.A.", 'anno_accademico', '2025/2026'), $r('In data', 'date', '2026-07-10'),
    $r('Con voto', 'text', '110/110'), $r('Certificati di laurea richiesti', 'multicheck', 'Laurea, Laurea con esami e date'), $dich]));
$flusso->registraProtocollo($ID['laurea'], '3302/2026', date('Y-m-d', strtotime('-2 days')), 8, 'Anna Protocollo');
$marche->avvisaMarche($ID['laurea'], 9, 'Marco Referente');
// E3. Attestato di frequenza: aspetta la conferma dei docenti
$ID['attestato'] = $pratiche->crea($modulo('attestato_frequenza'), $studente, [$r('Matricola', 'text', '245678'), $r('Al Corso di Laurea in', 'corso_studio', 'Corso di laurea in Biologia'),
    $r('Motivo della richiesta', 'text', 'Giustificazione per il datore di lavoro'), $r('Ente o azienda', 'text', 'Azienda Calabra S.r.l.'), $r("PEC o email dell'azienda", 'email', 'azienda@pec.example.org'),
    ['etichetta' => 'Lezioni seguite', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Insegnamento', 'Docente', 'Dal', 'Al', 'Dalle ore', 'Alle ore', 'Aula'],
     'righe' => [['Biologia molecolare', 'Verdi Anna', '05/10/2026', '', '09:00', '13:00', 'Aula 3'], ['Biologia molecolare', 'Verdi Anna', '07/10/2026', '07/10/2026', '09:00', '11:00', 'Aula 3'], ['Zoologia generale', 'Neri Giulia', '09/10/2026', '', '08:30', '10:30', 'Aula 1']]], $dich]);
// F. Convalida esami: pronta per il Consiglio, portata in seduta
$ID['piano'] = $pratiche->crea($modulo('modifica_piano'), $studente, array_merge($anagrafica, [$r('Anno accademico della richiesta', 'anno_accademico', '2026/2027'),
    ['etichetta' => 'Variazioni al piano di studi', 'tipo' => 'tabella', 'valore' => '', 'colonne' => ['Insegnamento', 'CFU', 'S.S.D.', 'Variazione'], 'righe' => [['Ecologia applicata', '6', 'BIO/07', 'Inserire'], ['Zoologia generale', '9', 'BIO/05', 'Togliere']]], $dich]));
$flusso->registraProtocollo($ID['piano'], '3310/2026', date('Y-m-d', strtotime('-6 days')), 8, 'Anna Protocollo');

// ── Seduta del consiglio con verbale in firma ──
$sedute = $c->get(\App\Didattica\SedutaRepository::class);
$sid = $sedute->salva(0, ['organo' => 'Consiglio di Coordinamento del Corso di Laurea in Biologia', 'anno_accademico' => '2026/2027', 'data' => date('Y-m-d', strtotime('-4 days')), 'ora_inizio' => '15:30', 'ora_fine' => '17:00',
    'luogo' => 'Aula L3, cubo 4A', 'odg' => "Comunicazioni\nPratiche studenti\nVarie ed eventuali", 'presenze' => '', 'segretario' => 'Anna Verdi', 'coordinatore' => 'Mario Bianchi', 'consiglio_id' => 4, 'numero_verbale' => '9']);
$q("UPDATE didattica_sedute SET verbale_stato = 'segretario', segretario_email = 'anna.verdi@example.org', coordinatore_email = 'mario.bianchi@example.org' WHERE id = $sid");
$q("UPDATE pratiche SET seduta_id = $sid WHERE id = {$ID['piano']}");

// ── Risorse e prenotazioni ──
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario, mostra_in_home) VALUES (20, 'Aule e laboratori', 'aule-laboratori', 'calendario', 1, '#0f766e', 1)");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione, referente, email_notifiche) VALUES
 (21, 20, 'Aula Magna', 'aula', 'Cubo 4C, piano terra', 60, 3, 0, 90, 'tutti', 0, 1, 'Giulia Responsabile', ''),
 (22, 20, 'Laboratorio di Microscopia', 'laboratorio', 'Cubo 5B', 60, 2, 0, 60, 'personale', 1, 0, 'Giulia Responsabile', ''),
 (23, 20, 'Furgone del Dipartimento', 'veicolo', 'Parcheggio nord', 60, 8, 0, 60, 'personale', 1, 0, 'Giulia Responsabile', '')");
foreach ([21, 22, 23] as $rid) {
    $q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) SELECT $rid, g, '08:00', '18:00' FROM (SELECT 1 g UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) x");
}
$c->get(\App\Risorse\ServizioResponsabiliRisorse::class)->aggiungi(22, 'p.resp');
$c->get(\App\Risorse\ServizioResponsabiliRisorse::class)->aggiungi(23, 'p.resp');
$servizioRisorse = $c->get(\App\Risorse\ServizioRisorse::class);
$luca = $db->riga('SELECT * FROM utenti WHERE id = 5');
$mario = $db->riga('SELECT * FROM utenti WHERE id = 7');
$anna = $db->riga('SELECT * FROM utenti WHERE id = 6');
$lunedi = strtotime('monday next week');
$gg = fn(int $n) => date('Y-m-d', strtotime("+$n day", $lunedi));
$aula = $db->riga('SELECT * FROM risorse WHERE id = 21');
$lab = $db->riga('SELECT * FROM risorse WHERE id = 22');
$furgone = $db->riga('SELECT * FROM risorse WHERE id = 23');
$servizioRisorse->prenota($aula, $anna, $gg(0) . ' 09:00:00', 2, 'Lezione di Ecologia', $gg(21), [], 'settimana');
$servizioRisorse->prenota($aula, $mario, $gg(1) . ' 10:00:00', 1, 'Consiglio di Dipartimento', null);
$servizioRisorse->prenota($aula, $anna, $gg(2) . ' 15:00:00', 2, 'Seminario di ricerca', $gg(30), [], 'mese');
$servizioRisorse->prenota($lab, $anna, $gg(1) . ' 09:00:00', 2, 'Osservazione campioni di suolo', null);
$servizioRisorse->prenota($furgone, $anna, $gg(3) . ' 08:00:00', 8, 'Uscita didattica a Camigliatello', null);
$servizioRisorse->mettiInAttesa($aula, $luca, $gg(1) . ' 10:00:00', 1);
$servizioRisorse->mettiInAttesa($aula, $mario, $gg(1) . ' 10:00:00', 1);
$q("UPDATE prenotazioni_risorse SET creata_il = DATE_SUB(NOW(), INTERVAL 9 DAY) WHERE risorsa_id = 22");
$q("UPDATE risorse SET diario_attivo = 1 WHERE id = 23");
$q("INSERT INTO risorse_scadenze (risorsa_id, nome, scade_il, preavviso_giorni, note) VALUES
 (23, 'Bollo', DATE_ADD(CURDATE(), INTERVAL 12 DAY), 30, 'Bollo annuale'), (23, 'Assicurazione', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 30, 'Polizza 123456'), (22, 'Taratura microscopio', DATE_ADD(CURDATE(), INTERVAL 200 DAY), 30, '')");
// Utilizzo passato (per i numeri del cruscotto): furgone e laboratorio negli ultimi giorni
$passate = [[23, 1, '08:00', '12:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6], [23, 3, '09:00', '17:00', 'Mario', 'Bianchi', 'mario.bianchi@example.org', 7], [23, 6, '08:00', '11:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6],
            [23, 9, '10:00', '16:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6], [23, 12, '08:00', '14:00', 'Mario', 'Bianchi', 'mario.bianchi@example.org', 7], [22, 2, '09:00', '11:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6],
            [22, 4, '14:00', '16:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6], [22, 8, '09:00', '12:00', 'Mario', 'Bianchi', 'mario.bianchi@example.org', 7], [21, 2, '09:00', '11:00', 'Anna', 'Verdi', 'anna.verdi@example.org', 6],
            [21, 5, '15:00', '17:00', 'Mario', 'Bianchi', 'mario.bianchi@example.org', 7]];
foreach ($passate as $k => [$rid, $giorni, $da, $a, $nome, $cognome, $email, $uid]) {
    $d = date('Y-m-d', strtotime("-$giorni day"));
    $q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice) VALUES ($rid, $uid, '$nome', '$cognome', '$email', '$d $da:00', '$d $a:00', 'Attività didattica', 'confermata', 'RS-DEMO$k')");
}
// Convenzioni con le scuole: una in scadenza
@$conn->query("UPDATE convenzioni_scuole SET scadenza = DATE_ADD(CURDATE(), INTERVAL 25 DAY) ORDER BY id LIMIT 1");

// Id e link dell'ultimo scenario, per lo script delle schermate
$ID['modulo_tesi'] = (int) $modulo('tesi')['id'];
$ID['modulo_certificato'] = (int) $modulo('certificato')['id'];
$ID['conferma_token'] = (string) $db->valore('SELECT token FROM pratiche_conferme LIMIT 1');
$ID['conferma_attestato_token'] = (string) $db->valore('SELECT token FROM pratiche_conferme WHERE pratica_id = ? AND nome = ?', [$ID['attestato'], 'Verdi Anna']);
$ID['firma_token'] = (string) $db->valore("SELECT token FROM pratiche_firme WHERE tipo = 'direttore' LIMIT 1");
$ID['seduta'] = $sid;
$ID['lunedi'] = date('Y-m-d', $lunedi);
file_put_contents('cache/scenario_demo.json', json_encode($ID, JSON_PRETTY_PRINT));
echo "Scenario creato. Pratiche: " . json_encode($ID) . " · seduta $sid\n";
echo "Accessi: 1 admin · 5 Luca Rossi (studente) · 6 Anna Verdi (docente/relatore) · 7 Mario Bianchi (Direttore) · 8 Anna Protocollo · 9 Marco Referente (ufficio del corso) · 10 Giulia Responsabile (risorse) · 11 Chiara Internazionale · 12 Gianna Manager\n";
