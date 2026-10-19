<?php
// Prove dei verbali pubblici in sola visualizzazione: chi può scaricare (amministratori e persone dell'ufficio «Download dei verbali» scelte dall'anagrafe),
// accesso con le credenziali di Ateneo, permesso monouso del visualizzatore e registro. Chiamato da esegui.php (variabili $conn, $q e funzioni prova, sezione).
sezione("Verbali pubblici: sola visualizzazione con la password, download con le credenziali di Ateneo");

use App\Core\App as AppVd;
use App\Didattica\ServizioVerbaliPubblici as VerbaliVd;

foreach (["DELETE FROM uffici_persone WHERE email LIKE '%@verbalivd.test'", "DELETE FROM personale_ateneo WHERE id LIKE 'vd98%'", "DELETE FROM utenti WHERE id IN (9801, 9802, 9803)",
          "DELETE FROM didattica_sedute WHERE id = 98101", "DELETE FROM verbali_accessi_log WHERE utente LIKE '%@verbalivd.test'"] as $sql) $q($sql);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo, struttura) VALUES ('vd981', 'Abilitato', 'Aldo', 'aldo@verbalivd.test', 'docenti', 1, 'DiBEST'), ('vd982', 'Escluso', 'Elsa', 'elsa@verbalivd.test', 'pta', 1, 'DiBEST')");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (9801, 'VDABLD70A01D086X', 'Aldo', 'Abilitato', 'aldo@verbalivd.test', 5), (9802, 'VDESLE70A41D086Y', 'Elsa', 'Escluso', 'elsa@verbalivd.test', 5), (9803, 'VDADMN70A01D086Z', 'Anna', 'Admin', 'admin@verbalivd.test', 1)");
$u_ab = $conn->query("SELECT * FROM utenti WHERE id = 9801")->fetch_assoc();
$u_es = $conn->query("SELECT * FROM utenti WHERE id = 9802")->fetch_assoc();
$u_ad = $conn->query("SELECT * FROM utenti WHERE id = 9803")->fetch_assoc();

$vd = AppVd::per($conn)->get(VerbaliVd::class);
$sv_vd = AppVd::per($conn)->get(\App\Uffici\ServizioUffici::class);
$rep_vd = AppVd::per($conn)->get(\App\Didattica\AccessoVerbaliRepository::class);
$uff_vd = $sv_vd->perChiave('verbali_scarico');
prova($uff_vd && $uff_vd['ambito'] === 'dipartimento' && \App\Uffici\ServizioUffici::fisso($uff_vd) && $sv_vd->elimina((int)$uff_vd['id']) !== null, "l'ufficio «Download dei verbali» c'è ed è fisso (non si elimina)");
prova(!$vd->puoScaricare($u_ab) && !$vd->puoScaricare($u_es) && !$vd->puoScaricare(null), "prima di essere abilitati, con le credenziali di Ateneo non si scarica");
prova($vd->puoScaricare($u_ad), "gli amministratori scaricano");
prova($sv_vd->aggiungiDaAnagrafe((int)$uff_vd['id'], 'inesistente', 'Download dei verbali', []) !== null && $sv_vd->aggiungiDaAnagrafe((int)$uff_vd['id'], 'vd981', 'Download dei verbali', []) === null, "si abilita solo chi è nell'anagrafe di Ateneo");
$m_vd = $sv_vd->persone((int)$uff_vd['id'])[0];
prova($m_vd['compiti'] === '' && $m_vd['op_id'] === null, "l'abilitato non diventa operatore della Didattica (nessun compito, nessun op_id)");
prova($vd->puoScaricare($u_ab) && !$vd->puoScaricare($u_es) && $vd->puoScaricare(['id' => 55, 'email' => 'aldo@VERBALIVD.test']), "abilitato: scarica; chi non è nell'elenco no");
$acc_sso = $vd->accessoDaCredenziali($u_ab);
prova($acc_sso && $acc_sso['ambiti_json'] === '["*"]' && (int)$acc_sso['id'] === 0 && str_contains($acc_sso['etichetta'], 'Aldo Abilitato') && $vd->accessoDaCredenziali($u_es) === null && $vd->accessoDaCredenziali(null) === null, "accesso con le credenziali di Ateneo: tutti i verbali, solo per gli abilitati");

// Un verbale pubblicabile con il suo PDF
$dir_vd = RADICE_SITO . '/uploads/verbali/'; if (!is_dir($dir_vd)) mkdir($dir_vd, 0755, true);
file_put_contents($dir_vd . 'prova_vd.pdf', "%PDF-1.4\nverbale prova download\n%%EOF");
$cons_vd = (int)array_key_first(consigli_didattica($conn));
$q("INSERT INTO didattica_sedute (id, consiglio_id, organo, data, anno_accademico, numero_verbale, verbale_stato, verbale_pdf, pubblicabile) VALUES (98101, $cons_vd, 'Consiglio prova', '2026-09-10', '2025/2026', '9', 'firmato', 'uploads/verbali/prova_vd.pdf', 1)");
prova(in_array(98101, array_map('intval', array_column($vd->verbali($acc_sso), 'id')), true) && $vd->dati($acc_sso, 98101) !== null && $vd->dati($acc_sso, 999) === null, "l'abilitato vede il verbale pubblicabile");

// Permesso monouso del visualizzatore
$t1 = $vd->tokenVista(98101);
prova(strlen($t1) === 32 && !$vd->consumaToken('non-valido', 98101) && !$vd->consumaToken($t1, 5) && $vd->consumaToken($t1, 98101) && !$vd->consumaToken($t1, 98101), "permesso del visualizzatore: una sola lettura, solo per quel verbale");
$t2 = $vd->tokenVista(98101);
$_SESSION[VerbaliVd::CHIAVE_TOKEN][$t2]['t'] = time() - VerbaliVd::VALIDITA_TOKEN - 5;
prova(!$vd->consumaToken($t2, 98101), "permesso scaduto: non vale");

// Registro: lettura e scaricamento con l'email di chi è entrato con le credenziali di Ateneo
$f_l = $vd->file($acc_sso, 98101, 'visualizza');
$f_s = $vd->file($acc_sso, 98101, 'scarica');
$reg_vd = array_values(array_filter($rep_vd->registro(80), fn($r) => $r['utente'] === 'aldo@verbalivd.test'));
$az_vd = array_column($reg_vd, 'azione');
prova($f_l && $f_s && in_array('visualizza', $az_vd, true) && in_array('scarica', $az_vd, true) && $reg_vd[0]['etichetta'] === 'aldo@verbalivd.test', "registro: letture e scaricamenti con l'email di chi è entrato con le credenziali di Ateneo");

// Chi è tolto dall'elenco non scarica più
$sv_vd->togliPersona((int)$m_vd['id']);
prova(!$vd->puoScaricare($u_ab) && $vd->accessoDaCredenziali($u_ab) === null, "tolto dall'elenco: non scarica più");

foreach (["DELETE FROM uffici_persone WHERE email LIKE '%@verbalivd.test'", "DELETE FROM personale_ateneo WHERE id LIKE 'vd98%'", "DELETE FROM utenti WHERE id IN (9801, 9802, 9803)",
          "DELETE FROM didattica_sedute WHERE id = 98101", "DELETE FROM verbali_accessi_log WHERE utente LIKE '%@verbalivd.test'"] as $sql) $q($sql);
@unlink($dir_vd . 'prova_vd.pdf');
