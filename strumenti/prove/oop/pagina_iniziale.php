<?php
// Prove della pagina dopo l'accesso (PaginaIniziale): area personale per tutti, Cruscotto per il Direttore e il vice che lo scelgono. Dati con id 9899xx.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Dopo l'accesso: area personale o Cruscotto del Direttore");

$dir_pi = $conn->query("SELECT up.* FROM uffici_persone up JOIN uffici u ON u.id = up.ufficio_id WHERE u.chiave = 'direzione'")->fetch_all(MYSQLI_ASSOC);
$q("DELETE FROM uffici_persone WHERE ufficio_id IN (SELECT id FROM uffici WHERE chiave = 'direzione')");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) SELECT id, 'direttore9899@example.org', 'Prof. Direttore Prova', 'Direttore' FROM uffici WHERE chiave = 'direzione'");
$q("DELETE FROM utenti WHERE id IN (989900, 989901)");
$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, ruolo_id) VALUES (989900, 'PROVA99AA0000A00', 'Dir', 'Prova', 'direttore9899@example.org', 4), (989901, 'PROVA99BB0000B00', 'Altro', 'Prof', 'altro9899@example.org', 4)");
$pi = \App\Core\App::per($conn)->get(\App\Portale\PaginaIniziale::class);
$dir_u = ['id' => 989900, 'email' => 'direttore9899@example.org'];
$alt_u = ['id' => 989901, 'email' => 'altro9899@example.org'];

prova($pi->puoScegliere($dir_u) && !$pi->puoScegliere($alt_u) && !$pi->puoScegliere(null), "scelta: la offre solo al Direttore e al vice");
prova($pi->dopoAccesso($dir_u) === 'area_personale.php' && $pi->dopoAccesso(null) === 'area_personale.php', "senza scelta: area personale, anche per il Direttore");
$pi->imposta(989900, true);
prova($pi->sceltaCruscotto(989900) && $pi->dopoAccesso($dir_u) === 'cruscotto_direttore.php', "scelto il Cruscotto: il Direttore ci arriva dopo l'accesso");
$pi->imposta(989901, true);
prova($pi->dopoAccesso($alt_u) === 'area_personale.php', "la scelta non vale per chi non è (più) il Direttore o il vice");
$pi->imposta(989900, false);
prova(!$pi->sceltaCruscotto(989900) && $pi->dopoAccesso($dir_u) === 'area_personale.php', "tolta la scelta: di nuovo l'area personale");

$sso_pi = file_get_contents(dirname(__DIR__, 3) . '/saml_login.php');
$prof_pi = file_get_contents(dirname(__DIR__, 3) . '/profilo.php');
prova(str_contains($sso_pi, 'PaginaIniziale') && str_contains($prof_pi, 'name="al_cruscotto"') && str_contains($prof_pi, 'puoScegliere'), "accesso e profilo: usano PaginaIniziale");

$q("DELETE FROM utenti WHERE id IN (989900, 989901)");
$q("DELETE FROM uffici_persone WHERE ufficio_id IN (SELECT id FROM uffici WHERE chiave = 'direzione')");
foreach ($dir_pi as $r) {
    $q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo) VALUES (" . (int) $r['ufficio_id'] . ", '" . $conn->real_escape_string((string) $r['email']) . "', '" . $conn->real_escape_string((string) $r['nominativo']) . "', '" . $conn->real_escape_string((string) $r['ruolo']) . "')");
}

// Moduli lunghi: avviso se si lascia la pagina con modifiche non salvate (6.2)
sezione("Moduli lunghi: avviso prima di lasciare la pagina");
$ui_pi = file_get_contents(dirname(__DIR__, 3) . '/inc/ui.php');
$tutti_pi = true;
foreach (['convenzione_online.php' => 'formConvenzione', 'dichiarazione_tutorato.php' => 'formDichiarazione1', 'convenzione_tesi.php' => 'formDati'] as $pag_pi => $id_pi) {
    $src_pi = file_get_contents(dirname(__DIR__, 3) . '/' . $pag_pi);
    $tutti_pi = $tutti_pi && str_contains($src_pi, "ui_avviso_modifiche('$id_pi')") && str_contains($src_pi, 'id="' . $id_pi . '"');
}
prova($tutti_pi && function_exists('ui_avviso_modifiche') || str_contains($ui_pi, 'function ui_avviso_modifiche'), "moduli lunghi: ognuno ha il suo avviso, con l'id del modulo che esiste");
require_once dirname(__DIR__, 3) . '/inc/ui.php';
$js_pi = ui_avviso_modifiche('formX');
prova(str_contains($js_pi, '"formX"') && str_contains($js_pi, 'beforeunload') && str_contains($js_pi, "'submit'"), "avviso: spento all'invio del modulo, attivo con le modifiche");
