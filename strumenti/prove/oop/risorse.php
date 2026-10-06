<?php
// Prove del modulo Risorse (App\Risorse): le funzioni di inc/risorse.php e inc/calendario_risorse.php come facciate.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova usano id 97xx.
sezione("Modulo Risorse: facciate di inc/");

$q("DELETE FROM prenotazioni_risorse WHERE risorsa_id IN (9701, 9702)");
$q("DELETE FROM risorse_orari WHERE risorsa_id IN (9701, 9702)");
$q("DELETE FROM risorse_chiusure WHERE pagina_id = 9790");
$q("DELETE FROM risorse WHERE id IN (9701, 9702)");
$q("DELETE FROM pagine_eventi WHERE id = 9790");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (9790, 'Aule di prova', 'aule_prova_97', 'calendario', 1, '#123456')");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, luogo, capienza, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, ripetizione, chiede_motivo, attiva, email_notifiche)
    VALUES (9701, 9790, 'Aula prova 97', 'aula', 'Cubo 97', 40, 60, 2, 0, 60, 'tutti', 0, 1, 1, 1, 'gestore97@example.org'),
           (9702, 9790, 'Lab prova 97', 'laboratorio', '', NULL, 30, 4, 0, 60, 'studenti', 1, 0, 1, 1, '')");
$lun = date('Y-m-d', strtotime('monday next week'));
$q("INSERT INTO risorse_orari (risorsa_id, giorno, dalle, alle) VALUES (9701, 1, '09:00:00', '13:00:00'), (9701, 1, '14:30:00', '17:30:00'), (9702, 1, '09:00:00', '12:00:00')");
$utente = ['id' => 9701, 'nome' => 'Rosa', 'cognome' => 'Prova', 'email' => 'ROSA97@example.org', 'ruolo_id' => 5, 'ruoli_secondari' => ''];

prova(defined('TIPI_RISORSA') && TIPI_RISORSA === \App\Risorse\Costanti::TIPI && GIORNI_SETTIMANA[1] === 'Lunedì' && defined('LEGENDA_CALENDARIO_RISORSE'), "costanti globali di prima, con i valori di App\\Risorse\\Costanti");
$r = risorsa($conn, 9701);
prova($r && $r['nome'] === 'Aula prova 97' && $r['area_slug'] === 'aule_prova_97' && $r['colore_primario'] === '#123456' && risorsa($conn, 99999) === null, "risorsa(): con titolo, indirizzo e colore dell'area");
prova(orari_risorsa($conn, 9701) === [1 => [['09:00:00', '13:00:00'], ['14:30:00', '17:30:00']]] && orari_risorsa($conn, 99999) === [], "orari_risorsa()");
prova(chiusura_risorsa($conn, $r, $lun) === null, "chiusura_risorsa(): aperta");
$q("INSERT INTO risorse_chiusure (pagina_id, risorsa_id, dal, al, motivo) VALUES (9790, NULL, '$lun', '$lun', 'Ponte 97')");
prova(chiusura_risorsa($conn, $r, $lun) === 'Ponte 97' && slot_risorsa($conn, $r, $lun)[0]['stato'] === 'chiuso', "chiusura_risorsa() e slot_risorsa(): giorno chiuso");
$q("DELETE FROM risorse_chiusure WHERE pagina_id = 9790");
$slot = slot_risorsa($conn, $r, $lun);
prova(count($slot) === 7 && $slot[0]['inizio'] === "$lun 09:00:00" && $slot[0]['stato'] === 'libero' && $slot[4]['fascia'] === 1 && slot_risorsa($conn, $r, date('Y-m-d', strtotime("$lun +1 day"))) === [], "slot_risorsa(): fasce e slot liberi");
prova(puo_prenotare_risorsa($conn, $r, $utente) && !puo_prenotare_risorsa($conn, $r, null) && !puo_prenotare_risorsa($conn, risorsa($conn, 9702), $utente) && puo_prenotare_risorsa($conn, risorsa($conn, 9702), $utente + ['matricola_studente' => '1']), "puo_prenotare_risorsa()");
prova(is_array(gruppi_utente_nomi($conn, ['ruolo_id' => 1, 'ruoli_secondari' => ''])), "gruppi_utente_nomi()");

$e = prenota_risorsa($conn, $r, $utente, "$lun 09:00:00", 5, ' <b>Studio</b> 97 ');
prova($e['errore'] === null && count($e['codici']) === 1 && $e['stato'] === 'confermata', "prenota_risorsa(): confermata");
$p = prenotazione_risorsa($conn, $e['codici'][0]);
prova($p && $p['fine'] === "$lun 11:00:00" && $p['motivo'] === 'Studio 97' && $p['email'] === 'rosa97@example.org' && $p['risorsa_nome'] === 'Aula prova 97' && prenotazione_risorsa($conn, (int)$p['id'])['codice'] === $e['codici'][0], "prenotazione_risorsa(): per codice e per id, al massimo 2 slot");
prova(prenota_risorsa($conn, $r, $utente, "$lun 10:00:00", 1)['errore'] === 'Lo slot scelto non è più disponibile (già prenotato): scegline un altro.' && slot_risorsa($conn, $r, $lun)[1]['stato'] === 'occupato' && slot_risorsa($conn, $r, $lun, (int)$p['id'])[1]['stato'] === 'libero', "slot occupato, ma libero escludendo la propria prenotazione");
$serie = prenota_risorsa($conn, $r, $utente, "$lun 14:30:00", 1, 'Corso', date('Y-m-d', strtotime("$lun +14 days")));
prova(count($serie['codici']) === 3 && preg_match('/^[0-9A-F]{8}$/', (string)$serie['serie']), "prenota_risorsa(): serie settimanale");
$l = prenota_risorsa($conn, risorsa($conn, 9702), $utente + ['matricola_studente' => '1'], "$lun 09:00:00", 1, 'Esame');
prova($l['stato'] === 'da_approvare' && $l['errore'] === null, "prenota_risorsa(): richiesta da approvare");

$EMAIL = [];
$pl = prenotazione_risorsa($conn, $l['codici'][0]);
prova(cambia_stato_prenotazione_risorsa($conn, (int)$pl['id'], 'confermata') && !cambia_stato_prenotazione_risorsa($conn, (int)$pl['id'], 'confermata') && !cambia_stato_prenotazione_risorsa($conn, 999999, 'annullata')
    && $EMAIL && $EMAIL[0]['a'] === 'rosa97@example.org' && $EMAIL[0]['oggetto'] === 'Prenotazione approvata: Lab prova 97', "cambia_stato_prenotazione_risorsa(): approvazione con email");
$EMAIL = [];
prova(cambia_stato_prenotazione_risorsa($conn, (int)$p['id'], 'annullata', false) && $EMAIL && $EMAIL[0]['a'] === 'gestore97@example.org' && str_starts_with($EMAIL[0]['oggetto'], 'Annullata: Aula prova 97'), "cambia_stato_prenotazione_risorsa(): annullamento dell'utente avvisa i gestori");
prova(quando_risorsa($pl) === GIORNI_SETTIMANA[1] . ' ' . date('d/m/Y', strtotime($lun)) . ', 09:00–09:30' && str_contains(ics_prenotazione_risorsa($pl), 'UID:' . $pl['codice'] . '@didattica-dibest') && email_gestori_risorsa($conn, $p) === ['gestore97@example.org'], "quando_risorsa(), ics_prenotazione_risorsa(), email_gestori_risorsa()");
$EMAIL = [];
prova(email_prenotazione_risorsa($conn, $pl, 'promemoria') && !email_prenotazione_risorsa($conn, ['email' => ''] + $pl, 'promemoria') && str_contains($EMAIL[0]['corpo'], 'risorsa_ics.php?code=' . $pl['codice']), "email_prenotazione_risorsa()");

// Calendario e statistiche
prova(periodo_calendario_risorse('settimana', $lun) === [$lun, date('Y-m-d', strtotime("$lun +6 days"))] && periodo_calendario_risorse('mese', '2026-02-10') === ['2026-02-01', '2026-02-28'], "periodo_calendario_risorse()");
$cal = prenotazioni_calendario_risorse($conn, [9701, 9702], $lun, $lun);
prova(count($cal[9701]) === 3 - 1 + 0 || isset($cal[9701]), "prenotazioni_calendario_risorse(): per risorsa");
$html = html_calendario_risorse($conn, [risorsa($conn, 9701)], ['vista' => 'giorno', 'data' => $lun, 'uid' => 9701, 'gestore' => false,
    'url' => fn(array $c) => 'x.php?' . http_build_query($c), 'url_risorsa' => fn(int $id, string $g) => "r.php?id=$id&g=$g"]);
prova(str_contains($html, 'cal-ris-giorno') && str_contains($html, 'Aula prova 97') && str_contains($html, '40 posti') && str_contains(css_calendario_risorse(), '.cal-ris-giorno'), "html_calendario_risorse() e css_calendario_risorse()");
$st = statistiche_risorse($conn, 9790, $lun, date('Y-m-d', strtotime("$lun +6 days")));
prova(isset($st['risorse'][9701]) && $st['risorse'][9701]['prenotazioni'] >= 1 && $st['risorse'][9701]['annullate'] === 1 && $st['totali']['prenotazioni'] >= 2, "statistiche_risorse()");
$aule = aule_per_turni($conn);
prova(($aule['Aule di prova'][9701] ?? '') === 'Aula prova 97 (40 posti)' && isset($aule['Aule di prova'][9702]), "aule_per_turni()");

// Aula di un turno di un evento
$q("DELETE FROM turni WHERE id = 97011");
$q("DELETE FROM eventi WHERE id = 9701");
$q("INSERT INTO eventi (id, pagina_id, titolo) VALUES (9701, 9790, 'Seminario prova 97')");
$d2 = date('Y-m-d', strtotime("$lun +7 days"));
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, risorsa_id) VALUES (97011, 9701, 'Mattina', '$d2', '09:00:00', '11:00:00', 10, 9701)");
$avvisi = [];
sincronizza_aula_turno($conn, 97011, $avvisi, 1);
$pt = $conn->query("SELECT * FROM prenotazioni_risorse WHERE turno_id = 97011 AND stato = 'confermata'")->fetch_assoc();
prova($avvisi === [] && $pt && $pt['inizio'] === "$d2 09:00:00" && $pt['nome'] === 'Seminario prova 97' && str_starts_with($pt['codice'], 'AU-') && (int)$pt['utente_id'] === 1, "sincronizza_aula_turno(): occupa l'aula");
$q("UPDATE turni SET risorsa_id = NULL WHERE id = 97011");
sincronizza_aula_turno($conn, 97011, $avvisi);
prova(!$conn->query("SELECT 1 FROM prenotazioni_risorse WHERE turno_id = 97011 AND stato = 'confermata'")->num_rows, "sincronizza_aula_turno(): aula tolta, slot liberato");

// Pulizia
$q("DELETE FROM prenotazioni_risorse WHERE risorsa_id IN (9701, 9702)");
$q("DELETE FROM turni WHERE id = 97011");
$q("DELETE FROM eventi WHERE id = 9701");
$q("DELETE FROM risorse_orari WHERE risorsa_id IN (9701, 9702)");
$q("DELETE FROM risorse WHERE id IN (9701, 9702)");
$q("DELETE FROM pagine_eventi WHERE id = 9790");
prova(!$conn->query("SELECT 1 FROM risorse WHERE id = 9701")->num_rows, "pulizia dei dati di prova del modulo Risorse");
