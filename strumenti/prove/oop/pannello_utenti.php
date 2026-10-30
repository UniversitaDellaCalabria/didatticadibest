<?php
// Prove dell'elenco utenti del pannello (admin/utenti.php): la scheda di ogni persona si carica solo quando si apre, così la pagina resta leggera. Chiamato da esegui.php.
sezione("Pannello utenti: schede caricate all'apertura");

$src = (string) file_get_contents(__DIR__ . '/../../../admin/utenti.php');
$elenco = substr($src, (int) strpos($src, '<div id="listaUtenti">'), (int) strpos($src, '<!-- /listaUtenti -->') - (int) strpos($src, '<div id="listaUtenti">'));
prova(str_contains($src, "isset(\$_GET['scheda'])") && str_contains($src, '$corpo_utente'), "utenti: la scheda di una persona ha un suo indirizzo (?scheda=ID)");
prova(!str_contains($elenco, 'salva_abilitazioni') && !str_contains($elenco, 'select2-sec-role') && !str_contains($elenco, 'html_perimetro'), "utenti: l'elenco non contiene più i moduli di ogni persona (anagrafica, gruppi, abilitazioni)");
prova(str_contains($elenco, 'data-scheda="<?php echo $uid; ?>"'), "utenti: ogni riga ha il segnaposto da riempire all'apertura");
prova(str_contains($src, "show.bs.collapse") && str_contains($src, 'iniziaSelect2(c)') && str_contains($src, "encodeURIComponent(c.dataset.scheda)"), "utenti: il browser scarica la scheda alla prima apertura e avvia i menu a tendina");
prova(strpos($src, "isset(\$_GET['scheda'])") > strpos($src, '$perimetri = []') && strpos($src, "isset(\$_GET['scheda'])") < strpos($src, '<style>'), "utenti: la scheda si scrive dopo aver calcolato i permessi e prima della pagina intera");

// Chi non ha l'email: invito in area personale e filtro nel pannello
$src_ap = (string) file_get_contents(__DIR__ . '/../../../area_personale.php');
$src_pr = (string) file_get_contents(__DIR__ . '/../../../profilo.php');
prova(str_contains($src_ap, "trim((string)(\$user_info['email'] ?? '')) === ''") && str_contains($src_ap, 'profilo.php#email') && str_contains($src_ap, 'Aggiungi la tua email'), "email mancante: l'area personale invita ad aggiungerla");
prova(str_contains($src_ap, '$vedi_come === null && trim'), "email mancante: l'invito non compare nell'anteprima «Vedi come» dell'amministratore");
prova(str_contains($src_pr, 'id="email"'), "email mancante: il link porta alla scheda dell'email nel profilo");
prova(str_contains($src, 'id="soloSenzaMail"') && str_contains($src, 'data-senzamail') && str_contains($src, "card.dataset.senzamail === '1'"), "utenti: filtro «solo chi non ha l'email»");
