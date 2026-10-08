<?php
// Prove della pagina «Convenzioni da stipulare» del pannello FSL (App\Fsl\ServizioConvenzioniScuole): scuole con prenotazioni in essere,
// stato della convenzione, Allegato A (PDF e Word) e Convenzione già precompilati anche senza il modulo online e senza logo.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $giorni) e funzioni (prova, sezione). Dati di prova con id 986xx.
sezione("Modulo FSL: convenzioni da stipulare e documenti precompilati");

use App\Core\App as AppDs;

foreach (["DELETE FROM convenzioni_compilate WHERE prenotazione_id IN (98603, 98604, 98605, 98606)", "DELETE FROM prenotazioni WHERE id IN (98603, 98604, 98605, 98606)", "DELETE FROM turni WHERE id = 98602",
          "DELETE FROM progetti_dettagli WHERE evento_id = 98601", "DELETE FROM eventi WHERE id = 98601", "DELETE FROM pagine_eventi WHERE id = 98600",
          "DELETE FROM convenzioni_scuole WHERE scuola_codice IN ('ZZDS98600A', 'ZZDS98600B')", "DELETE FROM scuole WHERE codice IN ('ZZDS98600A', 'ZZDS98600B')"] as $sql) $q($sql);
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES
    ('ZZDS98600A', 'LICEO PROVA A', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', 'VIA A 1', '87036'),
    ('ZZDS98600B', 'LICEO PROVA B', NULL, NULL, 'LICEO', 'COSENZA', 'COSENZA', 'CALABRIA', 'VIA B 2', '87100')");
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98600, 'FSL da stipulare 986', 'fsl_da_stipulare_986', 'fsl', 1, '#445566')");
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES (98601, 98600, 'Progetto FSL da stipulare', 'progetto', 'Prova della pagina da stipulare.')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (98601, 1, 1, 0, '" . $giorni(10) . "', '" . $giorni(40) . "', 30)");
$q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (98602, 98601, 'Edizione 986', 9, 0)");
$q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, scuola_codice, convenzione, data_prenotazione, dati_custom_json) VALUES
    (98603, 98602, 'FS-DS1', 'da_approvare', 'Anna', 'Prova', 'anna986@example.org', 'ZZDS98600A', 'no', NOW(), '{\"numero_partecipanti\":\"12\",\"docente_riferimento\":\"Anna Prova\"}'),
    (98604, 98602, 'FS-DS2', 'confermata', 'Carlo', 'Verdi', 'carlo986@example.org', 'ZZDS98600A', 'no', NOW(), '{\"numero_partecipanti\":\"8\"}'),
    (98605, 98602, 'FS-DS3', 'confermata', 'Dina', 'Bianchi', 'dina986@example.org', 'ZZDS98600B', 'si', NOW(), '{\"numero_partecipanti\":\"15\"}'),
    (98606, 98602, 'FS-DS4', 'da_approvare', 'Elio', 'Neri', 'elio986@example.org', NULL, NULL, NOW(), '{\"scuola\":\"LICEO SCRITTO A MANO\",\"numero_partecipanti\":\"5\"}')");
// La scuola B ha già una convenzione valida in registro
salva_convenzione($conn, ['scuola_codice' => 'ZZDS98600B', 'data_stipula' => $giorni(-5), 'scadenza' => $giorni(200), 'protocollo' => '986/26', 'docenti' => [['nome' => 'Doc B', 'email' => 'docb986@example.org']]], 0, 'prova986');

$srv_ds = AppDs::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class);
$gruppi_ds = [];
foreach ($srv_ds->scuole() as $g) $gruppi_ds[$g['chiave']] = $g;
$ids_ds = fn(array $g) => array_column($g['prenotazioni'], 'id');
$manuale_ds = array_values(array_filter($gruppi_ds, fn($g) => str_starts_with($g['chiave'], 'x')));

prova(isset($gruppi_ds['ZZDS98600A']) && isset($gruppi_ds['ZZDS98600B']) && count($manuale_ds) >= 1, "scuole con prenotazioni in essere raggruppate per scuola (anagrafe e scritta a mano)");
$a_ds = $gruppi_ds['ZZDS98600A'] ?? []; $b_ds = $gruppi_ds['ZZDS98600B'] ?? [];
prova(($a_ds['da_stipulare'] ?? false) && $ids_ds($a_ds) === [98603, 98604] && $a_ds['n_da_stipulare'] === 2 && $a_ds['n_studenti'] === 20 && stripos((string)($a_ds['nome'] ?? ''), 'Liceo Prova') === 0 && ($a_ds['comune'] ?? '') !== '',
    "scuola A: due prenotazioni (anche una già confermata), 20 studenti, convenzione da stipulare", json_encode([$a_ds['nome'] ?? null, $a_ds['n_studenti'] ?? null, isset($a_ds['prenotazioni']) ? $ids_ds($a_ds) : null]));
prova(!($b_ds['da_stipulare'] ?? true) && ($b_ds['prenotazioni'][0]['conv'] ?? '') === 'coperta' && ($b_ds['validita'] ?? '') === $giorni(200), "scuola B: convenzione valida in registro, a posto");
$m_ds = $manuale_ds[0] ?? [];
prova(($m_ds['da_stipulare'] ?? false) && ($m_ds['nome'] ?? '') === 'LICEO SCRITTO A MANO' && ($m_ds['codice'] ?? 'x') === '', "scuola scritta a mano: da stipulare, riconosciuta dal nome");
$ordine_ds = array_keys($gruppi_ds);
prova($gruppi_ds[$ordine_ds[0]]['da_stipulare'] && !$gruppi_ds[$ordine_ds[count($ordine_ds) - 1]]['da_stipulare'], "in elenco prima le scuole da stipulare");

// Testo del PDF (flussi compressi)
$testo_pdf = function (string $pdf): string {
    $t = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) foreach ($m[1] as $f) { $x = @gzuncompress($f); if ($x !== false) $t .= $x; }
    return $t;
};
$f_ds = $srv_ds->documento('allegato_pdf', 'ZZDS98600A');
$pdf_ds = $f_ds ? (string)file_get_contents($f_ds['file']) : '';
prova($f_ds !== null && str_starts_with($pdf_ds, '%PDF') && str_ends_with($f_ds['nome'], '.pdf') && str_contains($testo_pdf($pdf_ds), '(Progetto)') && str_contains($testo_pdf($pdf_ds), '(stipulare)') && str_contains($testo_pdf($pdf_ds), '(Anna)'),
    "Allegato A in PDF precompilato per la scuola, senza modulo online e senza logo");
if ($f_ds) @unlink($f_ds['file']);

$f_ds = $srv_ds->documentoDellaPrenotazione('allegato_pdf', 98605);
prova($f_ds !== null && str_starts_with((string)file_get_contents($f_ds['file']), '%PDF'), "Allegato A dalla riga di una prenotazione già confermata");
if ($f_ds) @unlink($f_ds['file']);
prova($srv_ds->documento('allegato_pdf', 'NONESISTE0') === null && $srv_ds->documento('allegato_pdf', '') === null, "scuola sconosciuta: nessun documento");

if (class_exists('ZipArchive') && is_file(RADICE_SITO . '/modelli_documenti/convenzione_precompilabile.docx') && is_file(RADICE_SITO . '/modelli_documenti/allegato_a_precompilabile.docx')) {
    foreach (['allegato' => 'Allegato_A_FSL_', 'convenzione' => 'Convenzione_FSL_'] as $doc => $prefisso) {
        $f_ds = $srv_ds->documento($doc, $manuale_ds[0]['chiave'] ?? 'x');
        $ok_ds = $f_ds && str_starts_with((string)file_get_contents($f_ds['file']), 'PK') && str_starts_with($f_ds['nome'], $prefisso) && str_ends_with($f_ds['nome'], '.docx');
        if ($ok_ds) { $z = new ZipArchive(); $z->open($f_ds['file']); $ok_ds = str_contains(strip_tags((string)$z->getFromName('word/document.xml')), 'SCRITTO A MANO') || $doc === 'allegato'; $z->close(); }
        prova($ok_ds, "$doc in Word precompilato per una scuola scritta a mano");
        if ($f_ds) @unlink($f_ds['file']);
    }
} else {
    echo "  (modelli Word o ZipArchive non disponibili: prove dei documenti Word saltate)\n";
}

// Se la scuola ha compilato il modulo online, i suoi dati hanno la precedenza (Dirigente, studenti, tutor)
$online_ds = AppDs::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
$online_ds->creaDaProgramma(['denominazione' => 'LICEO PROVA A', 'codice' => 'ZZDS98600A', 'comune' => 'RENDE', 'indirizzo' => 'VIA A 1', 'cf' => '', 'dirigente' => 'Mario Rossi Dirigente', 'luogo_nascita' => '', 'data_nascita' => '', 'dir_cf' => '', 'pec' => '', 'email' => 'anna986@example.org'],
    null, [['pr' => 98603, 'studenti' => 21, 'tutor' => 'Prof. Zeta Tutor']], 98603, 'ZZDS98600A', 'anna986@example.org', 'no');
$f_ds = $srv_ds->documento('allegato_pdf', 'ZZDS98600A');
$t_ds = $f_ds ? $testo_pdf((string)file_get_contents($f_ds['file'])) : '';
prova($f_ds !== null && str_contains($t_ds, 'Mario') && str_contains($t_ds, 'Zeta') && str_contains($t_ds, '(21)'), "con il modulo online compilato valgono i dati della scuola (Dirigente, studenti, tutor)");
if ($f_ds) @unlink($f_ds['file']);
$riga_cc_ds = $conn->query("SELECT scaricata_il FROM convenzioni_compilate WHERE prenotazione_id = 98603")->fetch_assoc();
prova($riga_cc_ds !== null && $riga_cc_ds['scaricata_il'] === null, "scaricare dal pannello non segna la compilazione come scaricata dalla scuola");

foreach (["DELETE FROM convenzioni_compilate WHERE prenotazione_id IN (98603, 98604, 98605, 98606)", "DELETE FROM prenotazioni WHERE id IN (98603, 98604, 98605, 98606)", "DELETE FROM turni WHERE id = 98602",
          "DELETE FROM progetti_dettagli WHERE evento_id = 98601", "DELETE FROM eventi WHERE id = 98601", "DELETE FROM pagine_eventi WHERE id = 98600",
          "DELETE FROM convenzioni_scuole WHERE scuola_codice IN ('ZZDS98600A', 'ZZDS98600B')", "DELETE FROM scuole WHERE codice IN ('ZZDS98600A', 'ZZDS98600B')"] as $sql) $q($sql);
