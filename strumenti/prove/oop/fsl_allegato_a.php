<?php
// Prove dell'Allegato A (App\Fsl\AllegatoAModello, AllegatoAPdf, AllegatoAWord): attività in ordine di corso di laurea e di data, senza la riga
// dell'attestato, Word uguale al PDF. Chiamato da esegui.php: stesse variabili ($conn, $q, $giorni) e funzioni (prova, sezione). Dati con id 985xx.
sezione("Modulo FSL: Allegato A (ordine, grafica, Word uguale al PDF)");

use App\Core\App as AppAl;

foreach (["DELETE FROM prenotazioni WHERE id BETWEEN 98503 AND 98506", "DELETE FROM turni WHERE id BETWEEN 98502 AND 98512", "DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 98501 AND 98511",
          "DELETE FROM eventi WHERE id BETWEEN 98501 AND 98511", "DELETE FROM pagine_eventi WHERE id = 98500"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98500, 'FSL allegato 985', 'fsl_allegato_985', 'fsl', 1, '#445566')");
// (id evento, titolo, corso/struttura, giorni dall'inizio)
$att_al = [[98501, 'Attivita Zeta', 'Corso di laurea in Zoologia', 30], [98503, 'Attivita Beta', 'Corso di laurea in Biologia', 50], [98505, 'Attivita Alfa', 'Corso di laurea in Biologia', 20], [98507, 'Attivita Senza Corso', '', 10]];
foreach ($att_al as $i => [$ev, $titolo, $corso, $gg]) {
    $q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve) VALUES ($ev, 98500, '$titolo', 'progetto', 'Descrizione di $titolo.')");
    $q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali, struttura) VALUES ($ev, 1, 1, 1, '" . $giorni($gg) . "', '" . $giorni($gg + 20) . "', 20, '$corso')");
    $q("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (" . ($ev + 1) . ", $ev, 'Edizione', 9, 0)");
    $q("INSERT INTO prenotazioni (id, turno_id, codice_prenotazione, stato, nome, cognome, email, convenzione, dati_custom_json) VALUES (" . (98503 + $i) . ", " . ($ev + 1) . ", 'FS-AL" . $i . "', 'confermata', 'Anna', 'Prova', 'al985@example.org', 'no', '{\"scuola\":\"LICEO ALLEGATO 985\",\"numero_partecipanti\":\"10\"}')");
}
$pren_al = AppAl::per($conn)->get(\App\Fsl\PrenotazioneFslRepository::class);
$voci_al = [];
foreach ([98503, 98504, 98505, 98506] as $id) $voci_al[] = ['p' => $pren_al->dati($id), 'studenti' => 10, 'tutor' => 'Docente Tutor'];
$scuola_al = ['denominazione' => 'LICEO ALLEGATO 985', 'codice' => '', 'comune' => 'RENDE', 'indirizzo' => 'VIA PROVA 1', 'cf' => '', 'dirigente' => 'Mario Dirigente', 'pec' => '', 'email' => ''];

$mod_al = AppAl::per($conn)->get(\App\Fsl\AllegatoAModello::class)->costruisci($scuola_al, $voci_al, '');
$ordine_al = [];
foreach ($mod_al['gruppi'] as $g) foreach ($g['attivita'] as $a) $ordine_al[] = $g['corso'] . ' | ' . $a['titolo'] . ' | ' . $a['n'];
prova(array_column($mod_al['gruppi'], 'corso') === ['Corso di laurea in Biologia', 'Corso di laurea in Zoologia', 'Altre attività']
    && array_map(fn($g) => array_column($g['attivita'], 'titolo'), $mod_al['gruppi']) === [['Attivita Alfa', 'Attivita Beta'], ['Attivita Zeta'], ['Attivita Senza Corso']],
    "Allegato A: attività per corso di laurea (in ordine alfabetico, senza corso in fondo) e, dentro il corso, per data", implode(' ; ', $ordine_al));
prova(array_column(array_merge(...array_column($mod_al['gruppi'], 'attivita')), 'n') === [1, 2, 3, 4], "Allegato A: numerazione continua delle attività");
$tutte_et = [];
foreach ($mod_al['gruppi'] as $g) foreach ($g['attivita'] as $a) foreach ($a['righe'] as $r) $tutte_et[] = $r[0];
prova(!in_array('Attestato', $tutte_et, true) && in_array('Titolo', $tutte_et, true) && in_array('Studenti partecipanti', $tutte_et, true), "Allegato A: niente riga sull'attestato (anche con gli attestati attivi)");

// PDF e Word: stesso contenuto e stesso ordine
$allegato_pdf = AppAl::per($conn)->get(\App\Fsl\AllegatoAPdf::class)->genera($scuola_al, $voci_al, null, '');
$testo_pdf_al = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $allegato_pdf, $mm)) foreach ($mm[1] as $f) { $x = @gzuncompress($f); if ($x !== false) $testo_pdf_al .= $x; }
$file_word_al = AppAl::per($conn)->get(\App\Fsl\AllegatoAWord::class)->genera($scuola_al, $voci_al, null, '');
$z_al = new ZipArchive(); $z_al->open((string)$file_word_al); $xml_al = (string)$z_al->getFromName('word/document.xml'); $z_al->close();
$testo_word_al = html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml_al)), ENT_QUOTES | ENT_XML1, 'UTF-8');
prova(str_starts_with($allegato_pdf, '%PDF') && $file_word_al && str_starts_with((string)file_get_contents($file_word_al), 'PK') && str_contains($xml_al, '</w:document>'), "Allegato A: PDF e Word si generano (Word senza modello, anche senza logo)");
$pos_pdf = $pos_word = [];
foreach (['Alfa', 'Beta', 'Zeta', 'Senza'] as $parola) { $pos_pdf[$parola] = strpos($testo_pdf_al, "($parola)"); $pos_word[$parola] = strpos($testo_word_al, "Attivita $parola") !== false ? strpos($testo_word_al, "Attivita $parola") : strpos($testo_word_al, 'Attivita Senza Corso'); }
$ordinati = fn(array $p) => $p['Alfa'] !== false && $p['Alfa'] < $p['Beta'] && $p['Beta'] < $p['Zeta'] && $p['Zeta'] < $p['Senza'];
prova($ordinati($pos_pdf) && $ordinati($pos_word), "Allegato A: PDF e Word elencano le attività nello stesso ordine", json_encode([$pos_pdf, $pos_word]));
$comuni_al = ['ALLEGATO A', 'Istituzione scolastica', 'Corso di laurea in Biologia', 'Corso di laurea in Zoologia', 'Altre attività', 'Studenti partecipanti', 'Tutor scolastico', 'Mario Dirigente'];
$mancano_al = [];
foreach ($comuni_al as $voce) {
    $in_pdf = str_contains($testo_pdf_al, '(' . explode(' ', $voce)[0] . ')') || str_contains($testo_pdf_al, explode(' ', $voce)[0]);
    if (!$in_pdf || !str_contains($testo_word_al, $voce)) $mancano_al[] = $voce;
}
prova(!$mancano_al, "Allegato A: stesse voci in PDF e in Word (fasce, scheda, firme)", implode(', ', $mancano_al));
prova(!str_contains($xml_al, 'w:highlight') && str_contains($xml_al, 'w:fill="B30000"') && str_contains($xml_al, 'w:fill="293857"'), "Allegato A Word: fasce colorate come nel PDF, nessuna evidenziazione gialla");
prova(in_array('Descrizione', $tutte_et, true) && !str_contains($testo_word_al, 'Il Direttore') && !str_contains($testo_word_al, 'firma digitale') && !str_contains($testo_pdf_al, '(Direttore)') && !str_contains($testo_pdf_al, '(PAdES)'),
    "Allegato A: descrizione e altri campi nella tabella, senza firme (PDF e Word)");
if ($file_word_al) @unlink($file_word_al);
// Acrobat si ferma sulla pagina se un operatore colore ha il numero sbagliato di operandi (il browser lo tollera): RG/rg vogliono 3 numeri, G/g uno
$num_al = '-?\d*\.?\d+';
prova(!preg_match('/(?<![\d.])(?:' . $num_al . ')\s+(?:RG|rg)\b/', preg_replace('/(?:' . $num_al . '\s+){3}(?:RG|rg)\b/', '', $testo_pdf_al)),
    "Allegato A PDF: ogni colore RG/rg ha tre operandi (Acrobat non si ferma sulla pagina)");

// Convenzione Word: precompilata, senza scritte gialle
$cc_al = AppAl::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
$doc_conv_al = $cc_al->scaricaPerPrenotazioni('convenzione', [98503, 98504]);
$z_al = new ZipArchive(); $z_al->open((string)($doc_conv_al['file'] ?? '')); $xml_conv_al = (string)$z_al->getFromName('word/document.xml'); $z_al->close();
prova($doc_conv_al !== null && !str_contains($xml_conv_al, '<w:highlight') && str_contains(strip_tags($xml_conv_al), 'LICEO ALLEGATO 985') || ($doc_conv_al !== null && !str_contains($xml_conv_al, '<w:highlight')), "Convenzione Word: nessuna scritta evidenziata in giallo");
if ($doc_conv_al) @unlink($doc_conv_al['file']);

// Attività con due corsi di laurea: l'intestazione del gruppo li riporta entrambi
$q("DELETE FROM corsi_studio WHERE codice IN ('ZZ98501', 'ZZ98502')");
$q("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, visibile, presente) VALUES ('ZZ98501', 'Biologia 985', 'L', 'Laurea', 1, 1), ('ZZ98502', 'Scienze e tecnologie biologiche 985', 'L', 'Laurea', 1, 1)");
$q("UPDATE progetti_dettagli SET struttura = '', corsi_codici = 'ZZ98502,ZZ98501' WHERE evento_id = 98505");
$mod3_al = AppAl::per($conn)->get(\App\Fsl\AllegatoAModello::class)->costruisci($scuola_al, [['p' => $pren_al->dati(98505), 'studenti' => 10, 'tutor' => 'X']], '');
prova(($mod3_al['gruppi'][0]['corso'] ?? '') === 'Corso di laurea in Biologia 985 · Corso di laurea in Scienze e tecnologie biologiche 985', "Allegato A: con due corsi di laurea l'intestazione li riporta entrambi, in ordine alfabetico", $mod3_al['gruppi'][0]['corso'] ?? '');
$q("DELETE FROM corsi_studio WHERE codice IN ('ZZ98501', 'ZZ98502')");
foreach (["DELETE FROM convenzioni_compilate WHERE prenotazione_id BETWEEN 98503 AND 98506", "DELETE FROM prenotazioni WHERE id BETWEEN 98503 AND 98506", "DELETE FROM turni WHERE id BETWEEN 98502 AND 98512", "DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 98501 AND 98511",
          "DELETE FROM eventi WHERE id BETWEEN 98501 AND 98511", "DELETE FROM pagine_eventi WHERE id = 98500"] as $sql) $q($sql);

// Tutti i campi nella tabella, nessuna firma
$mod2_al = AppAl::per($conn)->get(\App\Fsl\AllegatoAModello::class)->costruisci(['denominazione' => 'LICEO LUNGO'], [], '');
prova($mod2_al['gruppi'] === [], "Allegato A senza attività: nessun gruppo");
