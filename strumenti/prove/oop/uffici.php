<?php
// Prove degli uffici unici (App\Uffici: UfficiRepository, ServizioUffici, Direzione) e della migrazione dalle quattro tabelle di prima (v71).
// Chiamato da esegui.php: stesse variabili ($conn, $q, $giorni, $EMAIL) e funzioni (prova, sezione).
sezione("Uffici di Dipartimento e di Ateneo: anagrafe unica, Direzione, migrazione");

use App\Core\App as AppUff;
use App\Core\Database as DbUff;
use App\Uffici\Direzione;
use App\Uffici\ServizioUffici as SvUffici;
use App\Uffici\UfficiRepository;

$uf_sv = AppUff::per($conn)->get(SvUffici::class);
$uf_rep = AppUff::per($conn)->get(UfficiRepository::class);
$uf_dz = AppUff::per($conn)->get(Direzione::class);

// Copia degli uffici com'erano (le prove che seguono li usano): si rimette tutto alla fine
foreach (["DROP TABLE IF EXISTS uf97_bak_uffici", "DROP TABLE IF EXISTS uf97_bak_persone", "DROP TABLE IF EXISTS uf97_bak_imp", "CREATE TABLE uf97_bak_uffici AS SELECT * FROM uffici", "CREATE TABLE uf97_bak_persone AS SELECT * FROM uffici_persone",
          "CREATE TABLE uf97_bak_imp AS SELECT conv_direttore_nome, conv_direttore_email, direttore_assente, direttore_assente_fino FROM impostazioni_sistema WHERE id = 1"] as $sql) $q($sql);
// Pulizia dei dati di prova (persone dell'anagrafe 9701-9704, uffici «Prova uffici…»)
foreach (["DELETE FROM uffici_persone WHERE email LIKE '%@uffici97.test'", "DELETE FROM uffici WHERE nome LIKE 'Prova uffici%'", "DELETE FROM personale_ateneo WHERE id LIKE 'uf97%'",
          "DELETE FROM utenti WHERE id IN (9701, 9702)"] as $sql) $q($sql);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, gruppo, attivo, struttura) VALUES
    ('uf971', 'Direttori', 'Dario', 'dario.dir@uffici97.test', 'docenti', 1, 'DiBEST'), ('uf972', 'Vicari', 'Vera', 'vera.vice@uffici97.test', 'docenti', 1, 'DiBEST'),
    ('uf973', 'Segretari', 'Sara', 'sara.seg@uffici97.test', 'pta', 1, 'DiBEST'), ('uf974', 'Senzaemail', 'Sem', '', 'pta', 1, 'DiBEST'), ('uf975', 'Protocolli', 'Pia', 'pia.prot@uffici97.test', 'pta', 1, 'DiBEST')");

// Uffici fissi della migrazione e creazione
$dir_uff = $uf_sv->perChiave('direzione');
prova($dir_uff && $dir_uff['ambito'] === 'dipartimento' && $uf_sv->perChiave('segreteria_direzione') && $uf_sv->perChiave('convenzioni_fsl'), "uffici fissi: Direzione, segreteria di direzione, ufficio convenzioni FSL");
[$id_a, $e_a] = $uf_sv->salva(0, 'dipartimento', '  Prova   uffici  A ', 'Prova', true, 5, 'protocollo', null, false, false);
prova($e_a === null && $id_a > 0 && $uf_sv->ufficio($id_a)['nome'] === 'Prova uffici A' && $uf_sv->ufficio($id_a)['tipo'] === 'protocollo', "ufficio del Dipartimento creato (nome ripulito, tipo del flusso)");
[, $e_c] = $uf_sv->salva(0, 'dipartimento', 'Prova uffici CdS', '', true, 6, 'cdl', null, false, false);
prova($e_c !== null && str_contains($e_c, 'consiglio'), "ufficio di corso di studio senza consiglio rifiutato");
$cons_uf = (int)$conn->query("SELECT id FROM didattica_consigli ORDER BY id LIMIT 1")->fetch_assoc()['id'];
[$id_c, $e_c2] = $uf_sv->salva(0, 'dipartimento', 'Prova uffici CdS', '', true, 6, 'cdl', $cons_uf, false, false);
prova($e_c2 === null && (int)$uf_sv->ufficio($id_c)['segue_corsi'] === 1 && (int)$uf_sv->ufficio($id_c)['consiglio_id'] === $cons_uf, "ufficio di corso di studio: segue i corsi del consiglio");
[$id_at, ] = $uf_sv->salva(0, 'ateneo', 'Prova uffici Ateneo', '', true, 9, 'protocollo', $cons_uf, true, true);
$u_at = $uf_sv->ufficio($id_at);
prova($u_at['ambito'] === 'ateneo' && $u_at['tipo'] === '' && $u_at['consiglio_id'] === null && (int)$u_at['smista'] === 0, "ufficio di Ateneo: niente flusso delle pratiche");
prova($uf_sv->salva(0, 'dipartimento', '   ', '', true)[1] !== null, "senza nome rifiutato");

// Persone: solo dall'anagrafe
prova($uf_sv->aggiungiDaAnagrafe($id_a, 'inesistente') !== null && $uf_sv->aggiungiDaAnagrafe($id_a, 'uf974') !== null, "persona inesistente o senza email rifiutata (solo anagrafe)");
prova($uf_sv->aggiungiDaAnagrafe($id_a, 'uf975', 'Responsabile') === null && $uf_sv->aggiungiDaAnagrafe($id_a, 'uf975') !== null, "persona aggiunta dall'anagrafe, una volta sola");
$p_a = $uf_sv->persone($id_a)[0];
prova($p_a['email'] === 'pia.prot@uffici97.test' && $p_a['ruolo'] === 'Responsabile' && $p_a['compiti'] === 'pratiche,sedute,ricevimento' && (int)$p_a['op_id'] === (int)$p_a['id'], "ufficio nel flusso: compiti proposti e op_id (stabile) = id");
$uf_sv->aggiungiDaAnagrafe($id_c, 'uf975', '', ['sedute']);
$p_c = $uf_rep->persone($id_c)[0];
prova((int)$p_c['op_id'] === (int)$p_a['op_id'] && $p_c['compiti'] === 'sedute', "stessa persona in due uffici: stesso op_id");
$ops = AppUff::per($conn)->get(\App\Didattica\OperatoreRepository::class)->tutti();
$op_pia = array_values(array_filter($ops, fn($o) => $o['email'] === 'pia.prot@uffici97.test'))[0] ?? null;
prova($op_pia && (int)$op_pia['id'] === (int)$p_a['op_id'] && (int)$op_pia['ufficio_id'] === $id_a && json_decode($op_pia['uffici_json'], true) === [$id_c] && $op_pia['compiti'] === 'pratiche,sedute,ricevimento', "operatori della Didattica: una persona, ufficio principale e altri, compiti uniti");
$uf_sv->aggiungiDaAnagrafe($id_at, 'uf973');
$ops2 = AppUff::per($conn)->get(\App\Didattica\OperatoreRepository::class)->tutti();
prova(!in_array('sara.seg@uffici97.test', array_column($ops2, 'email'), true), "chi è in un ufficio di Ateneo o senza compiti non è un operatore della Didattica");
prova($uf_sv->emailDelUfficio($id_a) === ['pia.prot@uffici97.test'] && $uf_sv->emailDelUfficio('direzione') !== null && $uf_sv->emailDelUfficio('nonesiste') === [], "email dell'ufficio per id o per chiave");
$uf_sv->aggiornaPersona((int)$p_a['id'], 'Capo', ['pratiche'], ['Corso di laurea in Biologia']);
$p_a2 = $uf_rep->persona((int)$p_a['id']);
prova($p_a2['ruolo'] === 'Capo' && $p_a2['compiti'] === 'pratiche' && json_decode($p_a2['corsi'], true) === ['Corso di laurea in Biologia'], "ruolo, compiti e corsi aggiornati");

// Eliminazione: uffici fissi, uso
prova($uf_sv->elimina((int)$dir_uff['id']) !== null && $uf_sv->perChiave('direzione') !== null, "la Direzione non si elimina");
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, attivo, ordine, iter_json) VALUES (97001, 'Modulo prova uffici', 'Prova', 'online', '[]', 'studenti', 0, 1, '[$id_a]')");
$er = $uf_sv->elimina($id_a);
prova($er !== null && str_contains($er, 'iter di 1'), "ufficio nell'iter di un modulo: non si elimina");
$q("DELETE FROM didattica_moduli WHERE id = 97001");
prova($uf_sv->elimina($id_a) === null && $uf_sv->ufficio($id_a) === null && $uf_rep->persone($id_a) === [], "ufficio libero eliminato con le sue persone");
$uf_sv->elimina($id_c); $uf_sv->elimina($id_at);

// Direzione: Direttore e vice, assenza
$q("DELETE FROM uffici_persone WHERE ufficio_id = " . (int)$dir_uff['id']);
$q("UPDATE impostazioni_sistema SET direttore_assente = 0, direttore_assente_fino = NULL WHERE id = 1");
prova($uf_dz->direttore() === null && $uf_dz->chiFirma()['email'] === '', "senza Direttore: nessuno firma");
prova($uf_sv->aggiungiDaAnagrafe((int)$dir_uff['id'], 'uf971', 'Direttore') === null && $uf_sv->aggiungiDaAnagrafe((int)$dir_uff['id'], 'uf972', 'Vice direttore') === null, "Direttore e vice scelti dall'anagrafe");
prova($uf_sv->aggiungiDaAnagrafe((int)$dir_uff['id'], 'uf973', 'Direttore') !== null, "un solo Direttore");
$dir_p = $uf_dz->direttore();
prova($dir_p['email'] === 'dario.dir@uffici97.test' && $uf_dz->vice()['email'] === 'vera.vice@uffici97.test' && (int)$dir_p['op_id'] === 0, "la Direzione non fa parte degli operatori (senza op_id)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (9701, 'DRTDRA70A01D086A', 'Dario', 'Direttori', 'dario.dir@uffici97.test', 5), (9702, 'VCRVRE75A41D086B', 'Vera', 'Vicari', 'vera.vice@uffici97.test', 5)");
$u_dir = $conn->query("SELECT * FROM utenti WHERE id = 9701")->fetch_assoc();
$u_vice = $conn->query("SELECT * FROM utenti WHERE id = 9702")->fetch_assoc();
prova($uf_dz->chiFirma() === ['nome' => 'Direttori Dario', 'email' => 'dario.dir@uffici97.test', 'cf' => 'DRTDRA70A01D086A'] && $uf_dz->puoFirmare($u_dir) && !$uf_dz->puoFirmare($u_vice), "firma il Direttore (codice fiscale dall'utente collegato)");
prova($uf_dz->direttoreOVice($u_dir) && $uf_dz->direttoreOVice($u_vice) && !$uf_dz->direttoreOVice(['email' => 'altri@x.test']), "Direttore e vice hanno gli stessi permessi (cruscotto)");
$cru_uf = AppUff::per($conn)->get(\App\Portale\CruscottoDirettore::class);
prova($cru_uf->puoVedere($u_dir) && $cru_uf->puoVedere($u_vice) && !$cru_uf->puoVedere(['id' => 5, 'ruolo_id' => 5, 'email' => 'altri@x.test']), "Cruscotto del Direttore: Direttore e vice");
// Direttore assente
prova($uf_dz->imposta(true, '2020-01-01', 'Test') !== null, "data di rientro nel passato rifiutata");
prova($uf_dz->imposta(true, null, 'Segreteria Sara') === null && $uf_dz->assenza()['attiva'] && $uf_dz->inCarica()['email'] === 'vera.vice@uffici97.test', "Direttore assente: passa al vice");
prova($uf_dz->chiFirma()['nome'] === 'Vicari Vera' && $uf_dz->puoFirmare($u_vice) && !$uf_dz->puoFirmare($u_dir), "con il Direttore assente firma il vice");
prova($uf_dz->imposta(false, null, 'Dario') === null && $uf_dz->inCarica()['email'] === 'dario.dir@uffici97.test' && $uf_dz->puoFirmare($u_dir), "interruttore spento: torna il Direttore");
$q("UPDATE impostazioni_sistema SET direttore_assente = 1, direttore_assente_fino = CURDATE() - INTERVAL 1 DAY WHERE id = 1");
prova(!$uf_dz->assenza()['attiva'] && $uf_dz->assenza()['impostata'] && $uf_dz->inCarica()['email'] === 'dario.dir@uffici97.test', "passata la data di rientro l'assenza non vale più");
$q("UPDATE impostazioni_sistema SET direttore_assente = 0, direttore_assente_fino = NULL WHERE id = 1");
$uf_rep->togliPersona((int)$uf_dz->vice()['id']);
prova($uf_dz->imposta(true, null, 'x') !== null && !$uf_dz->assenza()['attiva'], "senza vice non si può attivare «Direttore assente»");
$uf_sv->aggiungiDaAnagrafe((int)$dir_uff['id'], 'uf972', 'Vice direttore');
// Chi può attivarlo: Direttore, vice, segreteria di direzione, amministratori
$seg_uff = $uf_sv->perChiave('segreteria_direzione');
$uf_sv->aggiungiDaAnagrafe((int)$seg_uff['id'], 'uf973');
prova($uf_dz->puoImpostareAssenza($u_dir) && $uf_dz->puoImpostareAssenza($u_vice) && $uf_dz->puoImpostareAssenza(['id' => 77, 'email' => 'sara.seg@uffici97.test', 'ruolo_id' => 5])
    && $uf_dz->puoImpostareAssenza(['id' => 1, 'ruolo_id' => 1, 'email' => 'a@b.it']) && !$uf_dz->puoImpostareAssenza(['id' => 78, 'email' => 'altri@x.test', 'ruolo_id' => 5]) && !$uf_dz->puoImpostareAssenza(null), "«Direttore assente»: lo attivano Direttore, vice, segreteria e amministratori");
$uf_sv->elimina((int)$seg_uff['id']);

// Promemoria sospesi: niente riepilogo al Direttore e agli uffici; alla riattivazione ripartono (nulla è stato segnato come mandato)
$sosp_uf = AppUff::per($conn)->get(\App\Portale\SospensionePromemoria::class);
$riep_uf = AppUff::per($conn)->get(\App\Portale\RiepilogoPersone::class);
$q("DELETE FROM riepilogo_persone");
$sett_uf = (new DateTimeImmutable())->format('o-W');
$sosp_uf->imposta(true, 'Test');
$EMAIL = [];
$riep_uf->invia();
$riga_dir = $conn->query("SELECT settimana FROM riepilogo_persone WHERE email = 'dario.dir@uffici97.test'")->fetch_assoc();
prova($sosp_uf->attiva() && $sosp_uf->stato()['da'] === 'Test' && !in_array('dario.dir@uffici97.test', array_column($EMAIL, 'a'), true) && (!$riga_dir || $riga_dir['settimana'] !== $sett_uf), "promemoria sospesi: niente riepilogo al Direttore e la settimana non è segnata");
prova(in_array('dario.dir@uffici97.test', $sosp_uf->esclusi(), true) && in_array('vera.vice@uffici97.test', $sosp_uf->esclusi(), true), "promemoria sospesi: esclusi il Direttore, il vice e le persone degli uffici");
$sosp_uf->imposta(false, 'Test');
$riep_uf->invia();
$riga_dir = $conn->query("SELECT settimana FROM riepilogo_persone WHERE email = 'dario.dir@uffici97.test'")->fetch_assoc();
prova(!$sosp_uf->attiva() && $riga_dir && $riga_dir['settimana'] === $sett_uf, "promemoria riattivati: il Direttore viene considerato di nuovo (manda se c'è qualcosa da fare)");
$q("DELETE FROM riepilogo_persone");

// Convenzioni FSL: le persone dell'ufficio ricevono gli avvisi
$fsl_uff = $uf_sv->perChiave('convenzioni_fsl');
$uf_sv->aggiungiDaAnagrafe((int)$fsl_uff['id'], 'uf975');
prova(in_array('pia.prot@uffici97.test', AppUff::per($conn)->get(\App\Fsl\ConvenzioneCompilataRepository::class)->emailAbilitati(), true), "convenzioni FSL: l'ufficio riceve l'avviso delle convenzioni caricate");

// Risorse: un ufficio come responsabile
foreach (["DELETE FROM risorse_uffici WHERE risorsa_id = 97010", "DELETE FROM risorse_responsabili WHERE risorsa_id = 97010", "DELETE FROM risorse WHERE id = 97010", "DELETE FROM pagine_eventi WHERE id = 97011"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area) VALUES (97011, 'Area prova uffici', 'area-prova-uffici', 'calendario')");
$q("INSERT INTO risorse (id, pagina_id, nome, tipo, attiva) VALUES (97010, 97011, 'Aula prova uffici', 'aula', 1)");
$rr_sv = AppUff::per($conn)->get(\App\Risorse\ServizioResponsabiliRisorse::class);
$rr_rep = AppUff::per($conn)->get(\App\Risorse\ResponsabileRisorsaRepository::class);
prova($rr_sv->aggiungiUfficio(97010, (int)$fsl_uff['id']) === null && $rr_sv->aggiungiUfficio(97010, (int)$fsl_uff['id']) !== null && $rr_sv->aggiungiUfficio(97010, 999999) !== null, "risorsa: ufficio responsabile (una volta, solo se esiste)");
prova($rr_rep->emailNotifiche(97010) === ['pia.prot@uffici97.test'], "risorsa: le email vanno alle persone dell'ufficio");
$u_pia = ['id' => 79, 'email' => 'pia.prot@uffici97.test'];
prova($rr_sv->responsabileDi($u_pia, 97010, true) && !$rr_sv->responsabileDi(['id' => 80, 'email' => 'altri@x.test'], 97010) && array_column($rr_sv->risorseDi($u_pia), 'id') === ['97010'], "risorsa: chi è nell'ufficio la gestisce da «Le mie risorse»");
$rr_sv->impostaUfficio((int)$rr_sv->elencoUffici(97010)[0]['id'], true, false);
prova(!$rr_sv->responsabileDi($u_pia, 97010, true) && $rr_sv->responsabileDi($u_pia, 97010, false), "risorsa: ufficio senza il permesso di approvare");
$q("DELETE FROM risorse_uffici WHERE risorsa_id = 97010");

// Migrazione dalle quattro tabelle di prima (v71): uffici di Dipartimento con gli stessi id, operatori con op_id = id di prima, uffici di Ateneo con id nuovi e bandi aggiornati
$q("DELETE FROM uffici_persone"); $q("DELETE FROM uffici");
foreach (["DELETE FROM didattica_uffici", "DELETE FROM ufficio_didattica", "DELETE FROM uffici_ateneo", "DELETE FROM uffici_ateneo_persone", "DELETE FROM tutorato_bandi WHERE id = 97020"] as $sql) $q($sql);
$q("INSERT INTO didattica_uffici (id, nome, chiave, tipo, ordine, smista, segue_corsi) VALUES (3, 'Vecchio protocollo', 'protocollo', 'protocollo', 1, 0, 0), (4, 'Vecchio CdS', 'cdl_1', 'cdl', 2, 0, 1)");
$q("INSERT INTO ufficio_didattica (id, persona_id, email, nominativo, ruolo, compiti, ufficio_id, uffici_json, corsi) VALUES
    (7, 'x1', 'ada@uffici97.test', 'Ada Vecchia', 'Capo', 'pratiche,sedute', 3, '[4]', '[\"Biologia\"]'), (9, 'x2', 'bea@uffici97.test', 'Bea Senzaufficio', '', 'pratiche', NULL, NULL, NULL)");
$q("INSERT INTO uffici_ateneo (id, nome, descrizione, attivo) VALUES (3, 'Vecchio pagamenti', 'Paga', 1), (4, 'Vecchio personale', '', 0)");
$q("INSERT INTO uffici_ateneo_persone (ufficio_id, persona_id, email, nominativo, struttura) VALUES (3, 'x3', 'carlo@uffici97.test', 'Carlo Pagatore', 'Ateneo'), (4, NULL, 'dora@uffici97.test', 'Dora A Mano', '')");
$q("INSERT INTO tutorato_bandi (id, titolo, ufficio_ateneo_id) VALUES (97020, 'Bando migrazione uffici', 3)");
$q("UPDATE impostazioni_sistema SET conv_direttore_nome = 'Vecchio Direttore', conv_direttore_email = 'vecchio.dir@uffici97.test' WHERE id = 1");
$mig_uf = new \App\Core\Schema\Migrazioni(DbUff::per($conn), sys_get_temp_dir());
(new ReflectionMethod($mig_uf, 'migraUffici'))->invoke($mig_uf);
$m_uff = array_column($conn->query("SELECT id, nome, chiave, ambito FROM uffici ORDER BY id")->fetch_all(MYSQLI_ASSOC), null, 'id');
prova(isset($m_uff[3], $m_uff[4]) && $m_uff[3]['chiave'] === 'protocollo' && $m_uff[3]['ambito'] === 'dipartimento' && $m_uff[4]['chiave'] === 'cdl_1', "migrazione: uffici del Dipartimento con gli stessi id");
$ada = $conn->query("SELECT GROUP_CONCAT(ufficio_id ORDER BY id) uff, MAX(op_id) op, MIN(id) pid FROM uffici_persone WHERE email = 'ada@uffici97.test'")->fetch_assoc();
prova($ada['uff'] === '3,4' && (int)$ada['op'] === 7 && (int)$ada['pid'] === 7, "migrazione: operatore con i suoi uffici e lo stesso id di prima (pratiche.assegnata_a)");
$bea = $conn->query("SELECT u.chiave, p.op_id FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE p.email = 'bea@uffici97.test'")->fetch_assoc();
prova($bea && $bea['chiave'] === 'operatore' && (int)$bea['op_id'] === 9, "migrazione: operatore senza ufficio nella segreteria didattica");
$ate = $conn->query("SELECT id, nome FROM uffici WHERE ambito = 'ateneo' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$bando = (int)$conn->query("SELECT ufficio_ateneo_id FROM tutorato_bandi WHERE id = 97020")->fetch_assoc()['ufficio_ateneo_id'];
prova(count($ate) === 2 && $ate[0]['nome'] === 'Vecchio pagamenti' && (int)$ate[0]['id'] > 4 && $bando === (int)$ate[0]['id'], "migrazione: uffici di Ateneo con id nuovi e bando di tutorato aggiornato");
$carlo = $conn->query("SELECT ufficio_id, op_id FROM uffici_persone WHERE email = 'carlo@uffici97.test'")->fetch_assoc();
prova((int)$carlo['ufficio_id'] === (int)$ate[0]['id'] && $carlo['op_id'] === null, "migrazione: persone degli uffici di Ateneo");
$dirm = $conn->query("SELECT p.ruolo, p.nominativo FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE u.chiave = 'direzione'")->fetch_assoc();
prova($dirm && $dirm['ruolo'] === 'Direttore' && $dirm['nominativo'] === 'Vecchio Direttore', "migrazione: il Direttore delle impostazioni entra nella Direzione");
(new ReflectionMethod($mig_uf, 'migraUffici'))->invoke($mig_uf);
prova((int)$conn->query("SELECT COUNT(*) n FROM uffici WHERE chiave = 'direzione'")->fetch_assoc()['n'] === 1 && (int)$conn->query("SELECT COUNT(*) n FROM uffici WHERE ambito = 'ateneo'")->fetch_assoc()['n'] === 2, "migrazione: una volta sola");

// Ripristino: uffici e persone tornano come prima delle prove
foreach (["DELETE FROM didattica_uffici", "DELETE FROM ufficio_didattica", "DELETE FROM uffici_ateneo", "DELETE FROM uffici_ateneo_persone", "DELETE FROM tutorato_bandi WHERE id = 97020",
          "DELETE FROM uffici_persone", "DELETE FROM uffici", "INSERT INTO uffici SELECT * FROM uf97_bak_uffici", "INSERT INTO uffici_persone SELECT * FROM uf97_bak_persone",
          "UPDATE impostazioni_sistema s, uf97_bak_imp b SET s.conv_direttore_nome = b.conv_direttore_nome, s.conv_direttore_email = b.conv_direttore_email, s.direttore_assente = b.direttore_assente, s.direttore_assente_fino = b.direttore_assente_fino WHERE s.id = 1",
          "DELETE FROM risorse WHERE id = 97010", "DELETE FROM pagine_eventi WHERE id = 97011", "DELETE FROM personale_ateneo WHERE id LIKE 'uf97%'", "DELETE FROM utenti WHERE id IN (9701, 9702)",
          "DROP TABLE uf97_bak_uffici", "DROP TABLE uf97_bak_persone", "DROP TABLE uf97_bak_imp"] as $sql) $q($sql);
