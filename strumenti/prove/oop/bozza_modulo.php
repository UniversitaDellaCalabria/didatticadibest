<?php
// Prove della bozza automatica dei moduli lunghi delle convenzioni (BozzaModulo, salva_bozza.php, ui_bozza_automatica).
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Convenzioni: bozza salvata in automatico");

use App\Portale\BozzaModulo as BM;

$q("DELETE FROM bozze_moduli WHERE chiave LIKE '%bozzaprova9897%'");
$tok_bm = 'bozzaprova9897' . str_repeat('a', 10);
prova(BM::chiave('tesi', $tok_bm) === 'tesi:' . $tok_bm && BM::chiave('altro', $tok_bm) === null && BM::chiave('tesi', 'corto') === null && BM::chiave('tesi', "x'; DROP--" . $tok_bm) === null, "chiave: solo tipi noti e link personali ben formati");
$p_bm = BM::pulisci(['csrf_token' => 'x', 'tipo' => 'tesi', 'token' => 't', 'salva_dati' => '1', 'password' => 'p', 'denominazione' => ' Bio Srl ', 'nota' => str_repeat('z', 5000), 'lista' => ['a'], 'ragione sociale' => 'no']);
prova(array_keys($p_bm) === ['denominazione', 'nota'] && strlen($p_bm['nota']) === 2000, "pulisci: via codice di sicurezza, pulsanti, password, liste e nomi strani; testi tagliati", json_encode(array_keys($p_bm)));

$bm = \App\Core\App::per($conn)->get(BM::class);
$ch_bm = BM::chiave('tesi', $tok_bm);
prova($bm->leggi($ch_bm) === null && $bm->salva($ch_bm, ['denominazione' => 'Bio Srl', 'csrf_token' => 'x']) === true, "salva: la prima bozza");
$l_bm = $bm->leggi($ch_bm);
prova($l_bm !== null && $l_bm['campi'] === ['denominazione' => 'Bio Srl'] && $l_bm['quando'] !== '', "leggi: i campi salvati, senza il codice di sicurezza");
$bm->salva($ch_bm, ['denominazione' => 'Bio Nuova Srl']);
prova($bm->leggi($ch_bm)['campi']['denominazione'] === 'Bio Nuova Srl', "salva: la bozza nuova sostituisce la vecchia");
prova($bm->salva($ch_bm, ['csrf_token' => 'x']) === false && $bm->salva($ch_bm, array_fill_keys(array_map(fn ($i) => "c$i", range(1, 60)), str_repeat('y', 2000))) === false, "salva: vuota o troppo grande, rifiutata");
$bm->elimina($ch_bm);
prova($bm->leggi($ch_bm) === null, "elimina: la bozza sparisce");
$q("INSERT INTO bozze_moduli (chiave, dati, aggiornata_il) VALUES ('tesi:vecchiabozzaprova9897', '{\"a\":\"b\"}', NOW() - INTERVAL 40 DAY)");
$bm->salva($ch_bm, ['x' => 'y']);
prova((int) $conn->query("SELECT COUNT(*) FROM bozze_moduli WHERE chiave = 'tesi:vecchiabozzaprova9897'")->fetch_row()[0] === 0, "salva: le bozze di oltre 30 giorni si cancellano");
$bm->elimina($ch_bm);

require_once dirname(__DIR__, 3) . '/inc/ui.php';
$js_bm = ui_bozza_automatica('formX', 'tesi', $tok_bm, ['campi' => ['denominazione' => 'Bio </script> Srl'], 'quando' => '2026-11-01 10:30:00']);
prova(str_contains($js_bm, 'salva_bozza.php') && str_contains($js_bm, '30000') && str_contains($js_bm, '01/11/2026') && !str_contains($js_bm, 'Bio </script>'), "ui: ripropone la bozza con l'ora e non si lascia rompere da testo con </script>");
$rad_bm = dirname(__DIR__, 3);
$ep_bm = file_get_contents($rad_bm . '/salva_bozza.php');
prova(str_contains($ep_bm, 'Csrf') && str_contains($ep_bm, 'convenzioni_tesi') && str_contains($ep_bm, 'convenzioni_compilate') && str_contains($ep_bm, "'POST'"), "salva_bozza.php: solo POST con codice di sicurezza e link personale esistente");
prova(str_contains(file_get_contents($rad_bm . '/convenzione_tesi.php'), 'ui_bozza_automatica') && str_contains(file_get_contents($rad_bm . '/convenzione_online.php'), 'ui_bozza_automatica') && str_contains(file_get_contents($rad_bm . '/convenzione_online.php'), '->elimina($bz_cc)'), "le due convenzioni usano la bozza e la cancellano al salvataggio");
