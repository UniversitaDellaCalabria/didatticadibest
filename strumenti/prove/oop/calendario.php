<?php
// Prove del calendario personale, del riepilogo settimanale con l'agenda e della ricerca unica (App\Portale: CalendarioPersonale, RiepilogoAgenda,
// RiepilogoPersone, RicercaGlobale) e delle pagine di libreria comune (inc/ui.php). Chiamato da esegui.php. Dati di prova con id 996xx.
sezione("Calendario personale, riepilogo con l'agenda, ricerca unica");

use App\Portale\CalendarioPersonale;
use App\Portale\RicercaGlobale;
use App\Portale\RiepilogoAgenda;
use App\Portale\RiepilogoPersone;

$cont_cal = \App\Core\App::per($conn);
$cal = $cont_cal->get(CalendarioPersonale::class);
$area_cal = (int)$conn->query("SELECT id FROM pagine_eventi WHERE visibile = 1 AND IFNULL(tipo_area, '') <> 'calendario' ORDER BY id LIMIT 1")->fetch_assoc()['id'];

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Dati: una persona con un evento tra 2 giorni, una lista d'attesa, un posto da confermare, un'aula prenotata e un ricevimento
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$q("DELETE FROM prenotazioni WHERE id BETWEEN 996100 AND 996120");
$q("DELETE FROM turni WHERE evento_id = 99610");
$q("DELETE FROM eventi WHERE id = 99610");
$q("DELETE FROM prenotazioni_risorse WHERE codice LIKE 'RS-CAL9%'");
$q("DELETE FROM risorse WHERE id IN (99610, 99611)");
$q("DELETE FROM calendario_token WHERE utente_id IN (99601, 99602)");
$q("DELETE FROM riepilogo_persone WHERE email IN ('cal99@prova.it', 'doc99@prova.it')");
$q("DELETE FROM utenti WHERE id IN (99601, 99602)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (99601, 'PROVA99C01X000Y', 'Eva', 'Calendario', 'cal99@prova.it', 5), (99602, 'PROVA99C02X000Y', 'Doc', 'Ente', 'doc99@prova.it', 5)");
$q("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo, ruolo_accesso_id, archiviato, richiede_prenotazione) VALUES (99610, $area_cal, 'Open day Calendario 99', 'Aula Magna', 'evento', 0, 0, 1)");
$gg = fn(string $q_) => date('Y-m-d', strtotime($q_));
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES
    (996101, 99610, 'Mattina', '" . $gg('+2 days') . "', '10:00:00', '12:00:00', 50),
    (996102, 99610, 'Pomeriggio', '" . $gg('+3 days') . "', '15:00:00', '16:00:00', 50),
    (996103, 99610, 'Senza orario', '" . $gg('+4 days') . "', NULL, NULL, 50),
    (996104, 99610, 'Lontano', '" . $gg('+200 days') . "', '10:00:00', '12:00:00', 50)");
$prc = function (int $id, int $turno, string $stato, string $scad = 'NULL') use ($conn) {
    $conn->query("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, data_prenotazione, scadenza_conferma) VALUES ($id, $turno, 99601, 'PR-CAL-$id', '$stato', 1, 'Eva', 'Calendario', 'cal99@prova.it', '', NOW(), " . ($scad === 'NULL' ? 'NULL' : "'$scad'") . ")");
};
$prc(996110, 996101, 'confermata');
$prc(996111, 996102, 'in_attesa');
$prc(996112, 996103, 'richiesta_conferma', $gg('+1 day') . ' 18:00:00');
// L'offerta di un posto ora è anche un compito di «Da fare» (per email): qui serve una persona con la sola agenda, quindi l'email della prenotazione è un'altra
$q("UPDATE prenotazioni SET email = 'altra99@prova.it' WHERE id = 996112");
$prc(996113, 996104, 'confermata');
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, durata_slot, max_slot, attiva, persona_id) VALUES (99610, $area_cal, 'Laboratorio Cal 99', 'laboratorio', 'Cubo 4B', 60, 2, 1, NULL), (99611, $area_cal, 'Ricevimento Doc 99', 'sportello', 'Studio 12', 30, 1, 1, NULL)");
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice) VALUES
    (99610, 99601, 'Eva', 'Calendario', 'cal99@prova.it', '" . $gg('+1 day') . " 09:00:00', '" . $gg('+1 day') . " 11:00:00', 'Esperimento', 'confermata', 'RS-CAL90001'),
    (99610, 99601, 'Eva', 'Calendario', 'cal99@prova.it', '" . $gg('+5 days') . " 09:00:00', '" . $gg('+5 days') . " 10:00:00', 'Altro', 'da_approvare', 'RS-CAL90002'),
    (99610, 99601, 'Eva', 'Calendario', 'cal99@prova.it', '" . $gg('+2 days') . " 09:00:00', '" . $gg('+2 days') . " 10:00:00', 'Annullata', 'annullata', 'RS-CAL90003')");

$utente_cal = ['id' => 99601, 'email' => 'cal99@prova.it', 'persona_id' => ''];
$voci_cal = $cal->voci($utente_cal, $gg('today'), $gg('+30 days'));
$per_tipo = [];
foreach ($voci_cal as $v) $per_tipo[$v['tipo']][] = $v;

prova(count($per_tipo['evento'] ?? []) === 2 && count($per_tipo['aula'] ?? []) === 2 && count($per_tipo['scadenza'] ?? []) === 1 && count($voci_cal) === 5, "calendario: eventi, aule e scadenze; fuori dalla finestra e annullate non compaiono", json_encode(array_column($voci_cal, 'titolo')));
$ordinata = true;
for ($i = 1; $i < count($voci_cal); $i++) if ($voci_cal[$i - 1]['inizio'] > $voci_cal[$i]['inizio']) $ordinata = false;
prova($ordinata && $voci_cal[0]['tipo'] === 'scadenza' || $voci_cal[0]['tipo'] === 'aula', "calendario: le voci sono in ordine di tempo");
$mattina = array_values(array_filter($voci_cal, fn($v) => $v['titolo'] === 'Open day Calendario 99 – Mattina'))[0] ?? null;
$senza_ora = array_values(array_filter($voci_cal, fn($v) => str_contains($v['titolo'], 'Senza orario')))[0] ?? null;
prova($mattina && substr($mattina['inizio'], 11, 5) === '10:00' && substr($mattina['fine'], 11, 5) === '12:00' && !$mattina['tutto_giorno'] && $mattina['luogo'] === 'Aula Magna' && $mattina['stato'] === 'ok', "calendario: un turno ha orari, luogo e stato");
prova($senza_ora === null, "calendario: il posto da confermare è una scadenza (non un evento in programma)");
$scad = $per_tipo['scadenza'][0];
prova(str_starts_with($scad['titolo'], 'Conferma il posto: Open day') && $scad['stato'] === 'azione' && substr($scad['inizio'], 11, 5) === '18:00', "calendario: la scadenza di conferma è in rosso all'ora indicata");
$attesa = array_values(array_filter($voci_cal, fn($v) => str_contains($v['titolo'], 'Pomeriggio')))[0] ?? null;
prova($attesa && $attesa['stato'] === 'attesa' && $attesa['stato_et'] === "In lista d'attesa", "calendario: la lista d'attesa è in ambra");
$da_appr = array_values(array_filter($per_tipo['aula'], fn($v) => $v['stato'] === 'attesa'))[0] ?? null;
prova($da_appr !== null && $da_appr['stato_et'] === 'In attesa di approvazione' && $per_tipo['aula'][0]['luogo'] === 'Cubo 4B', "calendario: aule confermate e in attesa di approvazione");
prova($cal->voci(['id' => 99602, 'email' => 'doc99@prova.it'], $gg('today'), $gg('+30 days')) === [], "calendario: ognuno vede solo le sue voci");

// Il ricevimento: gli appuntamenti presi dagli studenti allo sportello del docente
$q("UPDATE risorse SET persona_id = 'cal99-doc' WHERE id = 99611");
$q("INSERT INTO prenotazioni_risorse (risorsa_id, utente_id, nome, cognome, email, inizio, fine, motivo, stato, codice) VALUES
    (99611, 99601, 'Eva', 'Calendario', 'cal99@prova.it', '" . $gg('+2 days') . " 14:00:00', '" . $gg('+2 days') . " 14:30:00', 'Tesi', 'confermata', 'RS-CAL90004')");
$vr = $cal->voci(['id' => 99602, 'email' => 'doc99@prova.it', 'persona_id' => 'cal99-doc'], $gg('today'), $gg('+30 days'));
prova(count($vr) === 1 && $vr[0]['tipo'] === 'ricevimento' && $vr[0]['titolo'] === 'Ricevimento: Eva Calendario' && $vr[0]['luogo'] === 'Studio 12', "calendario: il docente vede gli appuntamenti presi al suo sportello");
prova(count(array_filter($cal->voci($utente_cal, $gg('today'), $gg('+30 days')), fn($v) => $v['tipo'] === 'ricevimento')) === 0, "calendario: chi prenota da un docente non ha un «ricevimento» suo");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Il file .ics
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$ics = $cal->ics($voci_cal, 'Prova');
prova(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n") && substr_count($ics, 'BEGIN:VEVENT') === count($voci_cal) && substr_count($ics, 'END:VEVENT') === count($voci_cal), ".ics: un VEVENT per voce");
prova(str_contains($ics, 'SUMMARY:Open day Calendario 99 – Mattina') && str_contains($ics, 'LOCATION:Aula Magna') && str_contains($ics, 'STATUS:TENTATIVE') && str_contains($ics, 'STATUS:CONFIRMED') && preg_match('/DTSTART:\d{8}T\d{6}Z/', $ics) === 1, ".ics: titolo, luogo, stato e orari in UTC");
prova(count(array_unique(array_column($voci_cal, 'uid'))) === count($voci_cal) && str_contains($ics, 'UID:risorsa-RS-CAL90001@'), ".ics: ogni voce ha un identificativo stabile");
$ics_g = $cal->ics([['tipo' => 'evento', 'titolo' => 'Giornata, "intera"; prova', 'inizio' => $gg('+2 days') . ' 00:00:00', 'fine' => $gg('+2 days') . ' 00:00:00', 'tutto_giorno' => true, 'luogo' => '', 'link' => 'miei_eventi.php', 'stato' => 'ok', 'stato_et' => 'Confermata', 'uid' => 'g1']], 'X');
prova(str_contains($ics_g, 'DTSTART;VALUE=DATE:' . str_replace('-', '', $gg('+2 days'))) && str_contains($ics_g, 'DTEND;VALUE=DATE:' . str_replace('-', '', $gg('+3 days'))) && str_contains($ics_g, 'SUMMARY:Giornata\\, "intera"\\; prova'), ".ics: gli eventi senza orario sono di un'intera giornata e i testi sono protetti");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Il link segreto di abbonamento
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$t1 = $cal->token(99601);
prova(preg_match('/^[a-f0-9]{40}$/', $t1) === 1 && $cal->token(99601) === $t1 && $cal->utentePerToken($t1) === 99601 && $cal->token(99602) !== $t1, "abbonamento: un codice per persona, sempre lo stesso finché non si rigenera");
$t2 = $cal->rigenera(99601);
prova($t2 !== $t1 && $cal->utentePerToken($t1) === null && $cal->utentePerToken($t2) === 99601 && $cal->utentePerToken('nonvalido') === null && $cal->utentePerToken(str_repeat('0', 40)) === null, "abbonamento: il vecchio link smette di funzionare, quelli sbagliati non danno niente");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Riepilogo settimanale con l'agenda: spento di partenza, lo accende l'amministratore
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$sw_cal = $cont_cal->get(RiepilogoAgenda::class);
$q("UPDATE impostazioni_sistema SET riepilogo_agenda = 0 WHERE id = 1");
prova(!$sw_cal->attivo() && $sw_cal->stato()['attivo'] === false, "riepilogo con agenda: è spento di partenza");
$riep_cal = $cont_cal->get(RiepilogoPersone::class);
$q("DELETE FROM riepilogo_persone");
$EMAIL = [];
$riep_cal->invia();
prova(!in_array('cal99@prova.it', array_column($EMAIL, 'a'), true), "riepilogo con agenda: spento, chi ha solo l'agenda non riceve niente");
prova($riep_cal->messaggio('cal99@prova.it', str_repeat('a', 40)) === null, "riepilogo con agenda: spento, non c'è nulla da fare quindi nessun messaggio");
$sw_cal->imposta(true, 'Prova');
$st = $sw_cal->stato();
prova($sw_cal->attivo() && $st['attivo'] && $st['da'] === 'Prova' && $st['il'] !== null, "riepilogo con agenda: l'amministratore lo accende (chi e quando)");
$m_cal = $riep_cal->messaggio('cal99@prova.it', str_repeat('a', 40));
prova($m_cal !== null && str_contains($m_cal[0], 'La tua settimana') && str_contains($m_cal[0], '6 appuntamenti') && str_contains($m_cal[1], 'Ricevimento Doc 99') && str_contains($m_cal[1], 'Open day Calendario 99') && str_contains($m_cal[1], 'Laboratorio Cal 99') && str_contains($m_cal[1], 'Non voglio più riceverla'), "riepilogo con agenda: «La tua settimana» con gli appuntamenti e il link per disiscriversi", (string)($m_cal[0] ?? '') . ' ' . substr(strip_tags((string)($m_cal[1] ?? '')), 0, 600));
prova(!str_contains($m_cal[1] ?? '', 'Lontano') && str_contains($m_cal[1] ?? '', 'Conferma il posto'), "riepilogo con agenda: solo i sette giorni a venire, con le scadenze");
$EMAIL = [];
$q("DELETE FROM riepilogo_persone");
$riep_cal->invia();
$a_cal = array_filter($EMAIL, fn($e) => $e['a'] === 'cal99@prova.it');
prova(count($a_cal) === 1, "riepilogo con agenda: acceso, parte anche a chi ha solo l'agenda (studenti compresi)", json_encode(array_column($EMAIL, 'a')));
$EMAIL = [];
$riep_cal->invia();
prova(!in_array('cal99@prova.it', array_column($EMAIL, 'a'), true), "riepilogo con agenda: una sola email a settimana");
$tok_cal = $conn->query("SELECT token FROM riepilogo_persone WHERE email = 'cal99@prova.it'")->fetch_assoc()['token'];
$riep_cal->imposta($tok_cal, false);
$q("UPDATE riepilogo_persone SET settimana = '' WHERE email = 'cal99@prova.it'");
$EMAIL = [];
$riep_cal->invia();
prova(!in_array('cal99@prova.it', array_column($EMAIL, 'a'), true), "riepilogo con agenda: chi si è disiscritto non lo riceve più");
$sw_cal->imposta(false, 'Prova');
prova(!$sw_cal->attivo(), "riepilogo con agenda: l'amministratore lo spegne");
$q("DELETE FROM riepilogo_persone");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// La ricerca unica
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$ric = $cont_cal->get(RicercaGlobale::class);
prova($ric->cerca('', true) === [] && $ric->cerca('a', true) === [] && $ric->cerca('   ', true) === [], "ricerca: meno di due lettere non cerca");
$q("DELETE FROM comunicazioni WHERE titolo LIKE 'Zoologia prova ricerca%'");
$q("INSERT INTO comunicazioni (tipo, titolo, sommario, testo, pubblicata, creato_il) VALUES ('notizia', 'Zoologia prova ricerca 99', '', '<p>Un testo con <b>formichiere</b> dentro</p>', 1, NOW()), ('pagina', 'Zoologia prova ricerca bozza', '', 'formichiere', 0, NOW())");
$g_ric = $ric->cerca('formichiere', false);
$chiavi_ric = array_column($g_ric, 'chiave');
prova($chiavi_ric === ['notizie'] && count($g_ric[0]['voci']) === 1 && $g_ric[0]['voci'][0]['titolo'] === 'Zoologia prova ricerca 99' && str_contains($g_ric[0]['voci'][0]['testo'], 'formichiere') && !str_contains($g_ric[0]['voci'][0]['testo'], '<b>'), "ricerca: notizie pubblicate (non le bozze) con un estratto senza HTML", json_encode($chiavi_ric));
prova(RicercaGlobale::totale($g_ric) === 1 && RicercaGlobale::totale([]) === 0, "ricerca: il totale somma i gruppi");
$g_ev = $ric->cerca('Open day Calendario 99', false);
prova(in_array('eventi', array_column($g_ev, 'chiave'), true) && str_contains($g_ev[0]['voci'][0]['link'], '?evento=99610'), "ricerca: gli eventi hanno il collegamento alla loro pagina");
$g_gu = $ric->cerca('marca da bollo', false);
prova(in_array('guide', array_column($g_gu, 'chiave'), true) && str_contains(array_values(array_filter($g_gu, fn($g) => $g['chiave'] === 'guide'))[0]['voci'][0]['link'], 'guida.php?r=studente#s'), "ricerca: le guide «Come si fa» portano alla sezione giusta");
prova(RicercaGlobale::contiene('<b>Ciao</b> mondo bello', 'MONDO ciao') && !RicercaGlobale::contiene('Ciao mondo', 'ciao luna') && RicercaGlobale::estratto('<p>' . str_repeat('x ', 100) . 'ago ' . str_repeat('y ', 100) . '</p>', 'ago', 60) !== '' && mb_strlen(RicercaGlobale::estratto(str_repeat('abc ', 200), 'abc', 60)) <= 62, "ricerca: tutte le parole devono comparire; l'estratto è corto");
$q("DELETE FROM didacticus_voci WHERE titolo = 'Voce prova ricerca 99'");
$q("INSERT INTO didacticus_voci (argomento, titolo, domande, parole_chiave, risposta, solo_login, attiva) VALUES ('altro', 'Voce prova ricerca 99', 'come si usa il tucano', 'tucano', 'Il tucano si usa così', 1, 1)");
prova(!in_array('didacticus', array_column($ric->cerca('tucano', false), 'chiave'), true) && in_array('didacticus', array_column($ric->cerca('tucano', true), 'chiave'), true), "ricerca: le risposte di Didacticus riservate si vedono solo dopo l'accesso");
$q("DELETE FROM didacticus_voci WHERE titolo = 'Voce prova ricerca 99'");
$q("DELETE FROM comunicazioni WHERE titolo LIKE 'Zoologia prova ricerca%'");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// La libreria comune dell'interfaccia (inc/ui.php)
// ---------------------------------------------------------------------------------------------------------------------------------------------------
require_once __DIR__ . '/../../../inc/ui.php';
prova(ui_stato('ok', 'Fatto') === '<span class="ui-stato ok">Fatto</span>' && str_contains(ui_stato('boh', 'x'), 'ui-stato neutro') && str_contains(ui_stato('azione', '<b>'), '&lt;b&gt;') && str_contains(ui_stato('info', 'x', 'fa-bell'), 'fa-bell'), "ui_stato(): cinque colori fissi, uno sconosciuto diventa neutro, testo protetto");
$v_ui = ui_vuoto('fa-ticket', 'Niente <qui>', 'Testo', 'index.php', 'Vai', '#0d6efd');
prova(str_contains($v_ui, 'ui-vuoto') && str_contains($v_ui, '&lt;qui&gt;') && str_contains($v_ui, 'href="index.php"') && !str_contains(ui_vuoto('fa-x', 'T'), '<a ') && !str_contains(ui_vuoto('fa-x', 'T', 'x', 'index.php'), '<a '), "ui_vuoto(): il pulsante c'è solo con indirizzo ed etichetta");
$i_ui = ui_intestazione('Titolo');
prova(str_contains($i_ui, '<h1>Titolo</h1>') && str_contains($i_ui, 'href="area_personale.php"') && !str_contains(ui_intestazione('T', ''), 'ui-indietro'), "ui_intestazione(): titolo e «torna a»");
prova(str_contains(ui_tema_script(), "tema_dibest") && str_contains(ui_tema_pulsante(), 'uiTemaCambia()'), "tema scuro: script e pulsante");
$css_ui = (string)file_get_contents(__DIR__ . '/../../../assets/css/portale-ui.css');
prova(preg_match_all('/--ui-(ok|attesa|azione|info|neutro)-bg:/', $css_ui) === 10 && str_contains($css_ui, 'html[data-tema="scuro"] body.ui-scuro-ok'), "stile comune: i cinque stati hanno i colori chiaro e scuro");

// pulizia
$q("DELETE FROM prenotazioni WHERE id BETWEEN 996100 AND 996120");
$q("DELETE FROM turni WHERE evento_id = 99610");
$q("DELETE FROM eventi WHERE id = 99610");
$q("DELETE FROM prenotazioni_risorse WHERE codice LIKE 'RS-CAL9%'");
$q("DELETE FROM risorse WHERE id IN (99610, 99611)");
$q("DELETE FROM calendario_token WHERE utente_id IN (99601, 99602)");
$q("DELETE FROM utenti WHERE id IN (99601, 99602)");
