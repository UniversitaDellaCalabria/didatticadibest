<?php
// Prove delle misure del pannello Sistema (MisureSistema): email non affidabili, compiti fermi, giorni medi di firma, registri vuoti. Dati con id 9897xx.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Sistema: le quattro misure");

$pulisci_ms = ["DELETE FROM utenti WHERE id IN (989700, 989701, 989702)", "DELETE FROM tutorato_registro WHERE incarico_id IN (989710, 989711)", "DELETE FROM tutorato_incarichi WHERE id IN (989710, 989711, 989712)", "DELETE FROM convenzioni_tesi WHERE id IN (989720, 989721)"];
foreach ($pulisci_ms as $sql) $q($sql);
$ms = \App\Core\App::per($conn)->get(\App\Sistema\MisureSistema::class);
$base_ms = [$ms->utentiSenzaEmailAffidabile(), $ms->compitiFermi(), $ms->giorniMediFirmaDirettore(), $ms->incarichiConRegistroVuoto()];

$q("INSERT INTO utenti (id, codice_fiscale, nome, cognome, email, email_personalizzata, ruolo_id) VALUES (989700, 'PROVA97AA0000A00', 'A', 'Senza', NULL, 0, 3), (989701, 'PROVA97BB0000B00', 'B', 'Nonverificata', 'b9897@prova.it', 1, 3), (989702, 'PROVA97CC0000C00', 'C', 'Buona', 'c9897@prova.it', 0, 3)");
prova($ms->utentiSenzaEmailAffidabile() === $base_ms[0] + 2, "email: contano chi non ha l'email e chi l'ha scelta senza verificarla, non chi ha quella di Ateneo");

$q("INSERT INTO tutorato_incarichi (id, bando_id, codice, stato, confermata_il, firmata_docente_il, firmata_direttore_il) VALUES
    (989710, 0, 'MS-1', 'confermata', DATE_SUB(NOW(), INTERVAL 10 DAY), NULL, NULL),
    (989711, 0, 'MS-2', 'firmata', DATE_SUB(NOW(), INTERVAL 20 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY), NULL),
    (989712, 0, 'MS-3', 'confermata', NOW(), NULL, NULL)");
$f = $ms->compitiFermi();
prova($f['tutorato_docente']['n'] === $base_ms[1]['tutorato_docente']['n'] + 1 && $f['tutorato_direttore']['n'] === $base_ms[1]['tutorato_direttore']['n'] + 1, "fermi: solo i compiti più vecchi di 7 giorni, per gruppo (quello di oggi non conta)", json_encode($f));

$q("INSERT INTO convenzioni_tesi (id, token, stato, email, da_firmare_il, firmata_direttore_il) VALUES (989720, 'tokms1', 'firmata_direttore', 'ms1@esempio.invalid', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)), (989721, 'tokms2', 'da_firmare', 'ms2@esempio.invalid', DATE_SUB(NOW(), INTERVAL 8 DAY), NULL)");
$g = $ms->giorniMediFirmaDirettore();
prova($g['n'] === $base_ms[2]['n'] + 1 && $g['giorni'] !== null, "giorni di firma: entra la convenzione firmata, non quella ancora da firmare");
prova($ms->compitiFermi()['convenzioni_tesi_direttore']['n'] === $base_ms[1]['convenzioni_tesi_direttore']['n'] + 1, "fermi: la convenzione da firmare da 8 giorni è contata");

$q("UPDATE tutorato_incarichi SET stato = 'protocollata' WHERE id IN (989711)");
$q("INSERT INTO tutorato_registro (incarico_id, data, ore) VALUES (989710, CURDATE(), 1)");
$q("UPDATE tutorato_incarichi SET stato = 'firmata' WHERE id = 989710");
prova($ms->incarichiConRegistroVuoto() === $base_ms[3] + 1, "registro vuoto: l'incarico senza ore conta, quello con una riga no");

$sis_ms = file_get_contents(dirname(__DIR__, 3) . '/admin/sistema.php');
prova(str_contains($sis_ms, 'MisureSistema::class') && str_contains($sis_ms, 'id="misure"'), "sistema.php: il riquadro «Misure» è nella pagina");
foreach ($pulisci_ms as $sql) $q($sql);

// Scenario per profilo (7.1, parte statica): ogni compito di «Da fare per te» porta a una pagina che esiste, e ogni riquadro speciale ha il suo file.
sezione("Profili: ogni compito e ogni tessera portano a una pagina che esiste");
$radice_ms = dirname(__DIR__, 3);
$src_df = (string) file_get_contents($radice_ms . '/src/Portale/DaFarePersona.php');
preg_match_all("/'link' => '([a-z_\/]+\.php)/", $src_df, $m_df);
$mancanti_ms = array_values(array_filter(array_unique($m_df[1]), fn ($f) => !is_file($radice_ms . '/' . $f)));
prova(count($m_df[1]) >= 15 && $mancanti_ms === [], "«Da fare per te»: i link dei compiti puntano tutti a pagine esistenti (" . count(array_unique($m_df[1])) . " pagine)", implode(', ', $mancanti_ms));
$senza_file_ms = [];
foreach (\App\Portale\Area\CatalogoRiquadri::sistema() as $chiave_ms => $r_ms) {
    if (($r_ms['tipo'] ?? '') === \App\Portale\Area\CatalogoRiquadri::SPECIALE && !is_file($radice_ms . '/inc/area/' . $chiave_ms . '.php')) {
        $senza_file_ms[] = $chiave_ms;
    }
}
prova($senza_file_ms === [], "area personale: ogni riquadro speciale ha il suo file in inc/area", implode(', ', $senza_file_ms));

sezione("Database: contatore delle query");
$db_ms = \App\Core\Database::per($conn);
$n0_ms = \App\Core\Database::numeroQuery();
$db_ms->valore('SELECT 1');
$db_ms->righe('SELECT 1 UNION SELECT 2');
$db_ms->comando('SELECT 1');
prova(\App\Core\Database::numeroQuery() === $n0_ms + 3, "contatore: ogni query conta una volta (valore, righe, comando)");
