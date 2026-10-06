<?php
// Prove del modulo Auth, utenti e sistema (App\Auth, App\Sistema): le funzioni di inc/ come facciate (stessi risultati di prima).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione).
sezione("Modulo Auth e sistema: facciate di inc/");

// Sessione, token CSRF e messaggi flash
$_SESSION = [];
$tok = csrf_token();
prova(strlen($tok) === 64 && csrf_token() === $tok && $_SESSION['csrf_token'] === $tok, "csrf_token(): uno per sessione, salvato in \$_SESSION");
ob_start(); csrf_field(); $campo = ob_get_clean();
prova($campo === '<input type="hidden" name="csrf_token" value="' . $tok . '">', "csrf_field() stampa il campo hidden");
prova(csrf_verify($tok) === true, "csrf_verify() con il token giusto");
flash_set('Salvato');
prova($_SESSION['_flash'] === ['msg' => 'Salvato', 'type' => 'success'], "flash_set(): messaggio e tipo in sessione");
$f = flash_get();
prova($f['msg'] === 'Salvato' && !isset($_SESSION['_flash']) && flash_get() === null, "flash_get(): si legge una volta sola");
flash_set('Errore <b>', 'danger');
$fh = flash_html();
prova(str_contains($fh, 'alert-danger') && str_contains($fh, 'Errore &lt;b&gt;') && flash_html() === '', "flash_html(): avviso Bootstrap con testo protetto, poi vuoto");
prova(h(null) === '' && h('<a>"\'') === '&lt;a&gt;&quot;&#039;', "h(): escaping");

// Limite di richieste
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$q("DELETE FROM rate_limit_attempts");
$ok1 = check_rate_limit($conn, 'prova_auth', 2, 300); $ok2 = check_rate_limit($conn, 'prova_auth', 2, 300); $ok3 = check_rate_limit($conn, 'prova_auth', 2, 300);
prova($ok1 && $ok2 && !$ok3, "check_rate_limit(): blocca dopo il massimo di tentativi");
prova((int)db_valore($conn, "SELECT COUNT(*) FROM rate_limit_attempts WHERE ip_hash = ?", ['203.0.113.9']) === 0, "l'IP non è salvato in chiaro");
$q("DELETE FROM rate_limit_attempts");

// Email dagli attributi SAML
prova(estrai_email_saml(['mail' => ['a@gmail.com', 'b@unical.it', 'c@studenti.unical.it']], 'studente') === 'c@studenti.unical.it'
   && estrai_email_saml(['mail' => ['a@gmail.com', 'b@unical.it']], 'dipendente') === 'b@unical.it'
   && estrai_email_saml(['mail' => ['b@unical.it', 'A@Gmail.com']], 'esterno') === 'a@gmail.com'
   && tipo_utente_saml('', '123') === 'dipendente', "estrai_email_saml() e tipo_utente_saml()");

// Utenti, ruoli, amministratori
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (9101, 'Prova', 'AdminA', 'Admin.A@Prova.it', 1, ''), (9102, 'Prova', 'AdminB', 'admin.b@prova.it', 5, '1,3'), (9103, 'Prova', 'Senza', NULL, 1, ''), (9104, 'Prova', 'Docente', 'doc@prova.it', 4, '')");
$amm = email_amministratori($conn);
prova(in_array('admin.a@prova.it', $amm, true) && in_array('admin.b@prova.it', $amm, true) && !in_array('doc@prova.it', $amm, true), "email_amministratori(): ruolo principale o secondario 1, minuscole");
prova(count(get_ruoli($conn)) >= 5 && get_ruoli($conn)[0]['id'] <= get_ruoli($conn)[1]['id'], "get_ruoli(): in ordine di id");
prova(aggiorna_email_utente($conn, 9104, 'admin.b@prova.it') === 'Questa email è già associata a un altro account.'
   && aggiorna_email_utente($conn, 9104, 'nuova@prova.it') === true
   && db_riga($conn, "SELECT email, email_personalizzata FROM utenti WHERE id = 9104") === ['email' => 'nuova@prova.it', 'email_personalizzata' => 1], "aggiorna_email_utente()");

// Gestori e perimetri
$q("INSERT INTO pagine_eventi (id, titolo, slug, gestore_utente_id, permessi_gestori_json) VALUES (9190, 'Area auth', 'area-auth', 9104, '{\"9102\":[\"full\"]}')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, gestori_utenti_ids) VALUES (9191, 9190, 'Evento A', 'evento', '9101'), (9192, 9190, 'Progetto A', 'progetto', '')");
prova(assegna_ambito($conn, 9101, 'progetti', 9190, 9101) && ha_ambito($conn, 9101, 'progetti', 9190) && !ha_ambito($conn, 9101, 'eventi', 9190), "assegna_ambito()/ha_ambito()");
prova(!assegna_ambito($conn, 9101, 'eventi', 0) && !assegna_ambito($conn, 9101, 'inesistente', 9190), "assegna_ambito() rifiuta area mancante e tipo sconosciuto");
$gest = get_gestori_ids_area($conn, 9190);
prova(count(array_diff([9104, 9102, 9101], $gest)) === 0, "get_gestori_ids_area(): area, attività e perimetri");
prova(attivita_da_ambiti($conn, 9101, 9190) === [9192] && sql_attivita_ambiti($conn, 9101, 9190) === "(e.tipo = 'progetto')", "attività comprese nel perimetro «progetti»");
prova(utente_gestisce_attivita($conn, 9101, 9191) && utente_gestisce_attivita($conn, 9101, 9192) && !utente_gestisce_attivita($conn, 9103, 9191) && utente_ha_abilitazioni($conn, 9102), "utente_gestisce_attivita() e utente_ha_abilitazioni()");
revoca_ambito($conn, 9101, null, 9190);
prova(!ha_ambito($conn, 9101, 'progetti', 9190), "revoca_ambito(): la cache dei perimetri si aggiorna");
prova(get_notifiche_gestori_attive($conn, 9190) === null, "notifiche gestori: mai configurate = tutti");
set_notifica_gestore($conn, 9190, 9102, false);
$att = get_notifiche_gestori_attive($conn, 9190);
prova(is_array($att) && !in_array(9102, $att, true) && in_array(9104, $att, true), "set_notifica_gestore(): spegne un gestore");
prova(get_email_gestori_evento($conn, 9191) === ['doc@prova.it', 'Admin.A@Prova.it'] || count(get_email_gestori_evento($conn, 9191)) === 2, "get_email_gestori_evento(): rispetta le notifiche spente");
prova(ev_autorizzato($conn, 9191, 9190, '') && !ev_autorizzato($conn, 9191, 1, '') && ev_autorizzato($conn, 9191, 9190, ' AND e.id = 9191 ') && !ev_autorizzato($conn, 9192, 9190, ' AND e.id = 9191 '), "ev_autorizzato() con il filtro dei permessi");
prova(normalizza_lista_email('A@x.it; b@y.it,a@x.it rotto', 10, $scartati) === ['a@x.it', 'b@y.it'] && $scartati === ['rotto'], "normalizza_lista_email()");

// Registro accessi
$_SERVER['HTTP_USER_AGENT'] = 'ProvaBrowser/1.0';
registra_accesso_sso($conn, 9101, 'Admin.A@Prova.it', 'Prova', 'AdminA', 'sso');
$la = db_riga($conn, "SELECT * FROM log_accessi WHERE utente_id = 9101 ORDER BY id DESC LIMIT 1");
prova($la && $la['ip'] === '203.0.113.9' && $la['user_agent'] === 'ProvaBrowser/1.0' && $la['tipo'] === 'sso', "registra_accesso_sso(): IP e browser della richiesta");

// Controllo del sito, report settimanale, backup
$EMAIL = [];
$esiti = controllo_sito($conn);
prova($esiti[0] === ['ok' => true, 'voce' => 'Database raggiungibile'], "controllo_sito(): database raggiungibile");
$stato = esegui_controllo_sito($conn, false);
prova(isset($stato['problemi'], $stato['firma']) && is_file(RADICE_SITO . '/cache/controllo_sito.json') && !$EMAIL, "esegui_controllo_sito() senza avvisi non manda email");
@unlink(RADICE_SITO . '/cache/controllo_sito.json');
$marker = RADICE_SITO . '/cache/report_email_' . date('o-W') . '.ok';
@unlink($marker);
$EMAIL = [];
$r = invia_report_email_settimanale($conn, true);
prova($r === true && count($EMAIL) >= 2 && str_contains($EMAIL[0]['oggetto'], 'Riepilogo settimanale email Didattica DiBEST'), "invia_report_email_settimanale(): una email a ogni amministratore");
@unlink($marker);
prova(is_array(stato_backup()), "stato_backup() restituisce un array");
$zf = tempnam(sys_get_temp_dir(), 'bk'); file_put_contents($zf, str_repeat('dati ', 100)); $enc = $zf . '.enc'; $err = null;
prova(cifra_openssl_aes($zf, $enc, 'una-password-lunga', $err) && str_starts_with((string)file_get_contents($enc), 'Salted__'), "cifra_openssl_aes(): formato openssl enc");
@unlink($zf); @unlink($enc);
prova(env_valore('CHIAVE_CHE_NON_ESISTE_MAI') === null, "env_valore(): chiave assente = null");

$q("DELETE FROM eventi WHERE id IN (9191, 9192)"); $q("DELETE FROM pagine_eventi WHERE id = 9190");
$q("DELETE FROM abilitazioni_ambito WHERE utente_id IN (9101, 9102, 9103, 9104)"); $q("DELETE FROM log_accessi WHERE utente_id = 9101");
$q("DELETE FROM utenti WHERE id IN (9101, 9102, 9103, 9104)");
