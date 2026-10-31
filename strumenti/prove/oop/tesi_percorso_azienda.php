<?php
// Prove della tessera «Le convenzioni della tua azienda»: passi e pulsante (PercorsoAzienda), elenco della persona (ServizioConvenzioniAzienda) e lettura dal database.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Convenzioni di tesi: tessera dell'azienda (PercorsoAzienda)");

use App\ConvenzioniTesi\Costanti;
use App\ConvenzioniTesi\PercorsoAzienda;
use App\ConvenzioniTesi\ServizioConvenzioniAzienda;

$pa_oggi = '2026-10-31';
$pa_c = static fn (string $stato, array $x = []): array => $x + ['id' => 5, 'stato' => $stato, 'token' => 'tk&1', 'email' => 'az@esempio.invalid', 'azienda' => 'Azienda Prova', 'valida_al' => ''];
$pa_st = static fn (array $r): array => array_column($r['passi'], 'stato', 'chiave');

$r = PercorsoAzienda::per($pa_c(Costanti::INVITATA), $pa_oggi);
prova($pa_st($r)['dati'] === 'qui' && $r['azione']['testo'] === "Inserisci i dati dell'azienda" && $r['azione']['link'] === 'convenzione_tesi.php?t=tk%261', "PercorsoAzienda: invitata, si parte dai dati (link con il token codificato)");
prova(!isset($pa_st($r)['modifiche']) && array_keys($pa_st($r)) === ['dati', 'firma', 'ufficio', 'stipulata'], "PercorsoAzienda: il passo delle modifiche compare solo quando ci sono");

$r = PercorsoAzienda::per($pa_c(Costanti::COMPILATA), $pa_oggi);
prova($pa_st($r)['dati'] === 'fatto' && $pa_st($r)['firma'] === 'qui' && str_starts_with($r['azione']['testo'], 'Scarica, firma e carica'), "PercorsoAzienda: compilata, tocca alla firma e al caricamento");

$r = PercorsoAzienda::per($pa_c(Costanti::MODIFICHE), $pa_oggi);
prova($pa_st($r)['modifiche'] === 'qui' && $r['azione'] === null && $r['attesa'] !== '', "PercorsoAzienda: modifiche proposte, si aspetta il Dipartimento");
$r = PercorsoAzienda::per($pa_c(Costanti::RISPOSTA), $pa_oggi);
prova($pa_st($r)['modifiche'] === 'qui' && $r['azione']['testo'] === 'Vedi la risposta alle modifiche', "PercorsoAzienda: risposta data, un clic per vederla");

$r = PercorsoAzienda::per($pa_c(Costanti::RIFIUTATA), $pa_oggi);
prova($pa_st($r)['firma'] === 'qui' && str_starts_with($r['azione']['testo'], 'Carica di nuovo'), "PercorsoAzienda: file rifiutato, si ricarica");

foreach ([Costanti::CARICATA, Costanti::IN_CARICO, Costanti::DA_FIRMARE, Costanti::FIRMATA] as $st) {
    $r = PercorsoAzienda::per($pa_c($st), $pa_oggi);
    prova($pa_st($r)['firma'] === 'fatto' && $pa_st($r)['ufficio'] === 'qui' && $r['azione'] === null && $r['attesa'] !== '', "PercorsoAzienda: «{$st}», la firma è fatta e si aspetta il Dipartimento");
}

$r = PercorsoAzienda::per($pa_c(Costanti::PROTOCOLLATA, ['valida_al' => '2029-01-31']), $pa_oggi);
prova(array_values(array_unique($pa_st($r))) === ['fatto'] && $r['azione'] === null && !$r['scade_presto'] && $r['passi'][3]['nota'] === 'scade il 31/01/2029', "PercorsoAzienda: stipulata e valida, tutto fatto e la scadenza nell'ultimo passo");
$r = PercorsoAzienda::per($pa_c(Costanti::PROTOCOLLATA, ['valida_al' => '2026-12-15']), $pa_oggi);
prova($r['scade_presto'] && $r['azione']['testo'] === 'Rinnova la convenzione', "PercorsoAzienda: scade entro 60 giorni, compare «Rinnova»");
$r = PercorsoAzienda::per($pa_c(Costanti::PROTOCOLLATA, ['valida_al' => '2026-10-01']), $pa_oggi);
prova(str_contains($r['azione']['testo'], 'scaduta') && $r['passi'][3]['nota'] === 'scaduta il 01/10/2026', "PercorsoAzienda: scaduta, si può rinnovare");

// Elenco: le stipulate vecchie o già rinnovate non compaiono; prima quelle con qualcosa da fare
$el = ServizioConvenzioniAzienda::elenco([
    $pa_c(Costanti::COMPILATA, ['id' => 9, 'email' => 'a@esempio.invalid']),
    $pa_c(Costanti::PROTOCOLLATA, ['id' => 8, 'email' => 'a@esempio.invalid', 'valida_al' => '2026-11-01']),
    $pa_c(Costanti::PROTOCOLLATA, ['id' => 7, 'email' => 'b@esempio.invalid', 'valida_al' => '2029-01-01']),
    $pa_c(Costanti::PROTOCOLLATA, ['id' => 6, 'email' => 'c@esempio.invalid', 'valida_al' => '2024-01-01']),
], $pa_oggi);
prova(array_column($el, 'id') === [9, 7], "ServizioConvenzioniAzienda::elenco(): niente stipulata già rinnovata o scaduta da oltre un anno; prima chi ha qualcosa da fare");
prova(ServizioConvenzioniAzienda::elenco([$pa_c(Costanti::INVITATA, ['azienda' => ''])], $pa_oggi)[0]['azienda'] === 'az@esempio.invalid', "ServizioConvenzioniAzienda::elenco(): senza nome dell'azienda si mostra l'email");

// Lettura dal database: solo le convenzioni dell'email della persona
$pa_email = 'prova.azienda.9896@esempio.invalid';
$q("DELETE FROM convenzioni_tesi WHERE LOWER(email) IN ('$pa_email', 'altra.9896@esempio.invalid')");
$rep_pa = \App\Core\App::per($conn)->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class);
$id_pa = $rep_pa->crea($pa_email, 'Azienda Prova 9896', Costanti::ORIGINE_INVITO, '');
$rep_pa->crea('altra.9896@esempio.invalid', 'Altra Azienda', Costanti::ORIGINE_INVITO, '');
$svc_pa = \App\Core\App::per($conn)->get(ServizioConvenzioniAzienda::class);
$tile = $svc_pa->perUtente(['id' => 1, 'email' => strtoupper($pa_email)]);
prova(count($tile) === 1 && $tile[0]['id'] === $id_pa && $tile[0]['azienda'] === 'Azienda Prova 9896' && $tile[0]['percorso']['azione'] !== null, "ServizioConvenzioniAzienda::perUtente(): trova la convenzione dell'email (senza badare alle maiuscole) e non quelle delle altre aziende");
prova($svc_pa->perUtente(['id' => 1, 'email' => '']) === [] && $svc_pa->perUtente(null) === [] && $svc_pa->perUtente(['id' => 1, 'email' => 'nessuno.9896@esempio.invalid']) === [], "ServizioConvenzioniAzienda::perUtente(): senza email o senza convenzioni l'elenco è vuoto");
$q("DELETE FROM convenzioni_tesi WHERE LOWER(email) IN ('$pa_email', 'altra.9896@esempio.invalid')");

$pa_riq = \App\Portale\Area\CatalogoRiquadri::sistema()['convenzioni_azienda'] ?? null;
prova($pa_riq !== null && $pa_riq['tipo'] === \App\Portale\Area\CatalogoRiquadri::SPECIALE && $pa_riq['profili'] === [\App\Portale\Area\Profili::AZIENDA, \App\Portale\Area\Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/convenzioni_azienda.php'), "riquadro convenzioni_azienda: nel catalogo per Azienda e Amministratore, disegnato da inc/area/convenzioni_azienda.php");
