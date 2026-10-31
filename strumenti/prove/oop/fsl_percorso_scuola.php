<?php
// Prove di PercorsoScuola: i passi e l'unica azione di una prenotazione FSL vista dalla scuola (regole pure, senza database).
// Chiamato da esegui.php: stesse funzioni (prova, sezione).
sezione("FSL: percorso di una prenotazione (PercorsoScuola)");

use App\Fsl\Costanti;
use App\Fsl\PercorsoScuola;

$ps_base = ['id' => 7, 'codice' => 'FSL-AB12', 'stato' => 'confermata', 'token' => 'tok123', 'convenzione' => 'no', 'iter' => '', 'documenti' => null, 'al' => '2026-12-10', 'attestati' => true, 'attestato_inviato' => false];
$ps_oggi = '2026-10-31';
$ps_stati = static fn (array $r): array => array_column($r['passi'], 'stato', 'chiave');

// Senza convenzione: il passo corrente è la convenzione, con un solo pulsante che porta al caricamento
$r = PercorsoScuola::per($ps_base, $ps_oggi);
prova(array_keys($ps_stati($r)) === ['programma', 'prenotazione', 'convenzione', 'attivita', 'attestati'] && $ps_stati($r)['convenzione'] === 'qui' && $ps_stati($r)['attivita'] === '', "PercorsoScuola: senza convenzione il passo corrente è la convenzione");
prova($r['azione'] !== null && $r['azione']['testo'] === 'Carica la convenzione firmata' && $r['azione']['link'] === 'convenzione_online.php?t=tok123#firmata', "PercorsoScuola: il pulsante porta al caricamento con il link personale");
prova(array_filter($ps_stati($r), static fn ($s): bool => $s === 'qui') === ['convenzione' => 'qui'] && !$r['fatto'], "PercorsoScuola: un solo passo «qui»");

// Convenzione già stipulata: il passo è fatto; senza token il pulsante rimanda alla scheda della scuola
$r = PercorsoScuola::per(['convenzione' => 'si'] + $ps_base, $ps_oggi);
prova($ps_stati($r)['convenzione'] === 'fatto' && $ps_stati($r)['attivita'] === 'qui' && $r['azione'] === null && $r['attesa'] !== '', "PercorsoScuola: convenzione già in vigore, si aspetta l'attività");
$r = PercorsoScuola::per(['token' => ''] + $ps_base, $ps_oggi);
prova($r['azione']['link'] === 'miei_eventi.php#programma-fsl', "PercorsoScuola: senza link personale si rimanda alla scheda del programma FSL");

// Iter della convenzione firmata
$r = PercorsoScuola::per(['iter' => Costanti::ITER_CARICATA] + $ps_base, $ps_oggi);
prova($r['azione'] === null && str_contains($r['attesa'], 'ricevuta') && $ps_stati($r)['convenzione'] === 'qui', "PercorsoScuola: convenzione ricevuta, nessuna azione per la scuola");
$r = PercorsoScuola::per(['iter' => Costanti::ITER_RIFIUTATA] + $ps_base, $ps_oggi);
prova($r['azione'] !== null && str_starts_with($r['azione']['testo'], 'Carica di nuovo'), "PercorsoScuola: convenzione rifiutata, si ricarica");
$r = PercorsoScuola::per(['iter' => Costanti::ITER_DA_FIRMARE] + $ps_base, $ps_oggi);
prova($r['azione'] === null && str_contains($r['attesa'], 'Direttore'), "PercorsoScuola: in firma al Direttore");
$r = PercorsoScuola::per(['iter' => Costanti::ITER_PROTOCOLLATA] + $ps_base, $ps_oggi);
prova($ps_stati($r)['convenzione'] === 'fatto' && $ps_stati($r)['attivita'] === 'qui', "PercorsoScuola: convenzione protocollata, passo fatto");

// Elenco studenti e autorizzazione: compare solo se l'attività li chiede, con la scadenza
$senza = ['elenco' => false, 'autorizzazione' => false, 'completi' => false, 'scadenza' => '2026-11-20', 'scaduti' => false];
$r = PercorsoScuola::per(['convenzione' => 'si', 'documenti' => $senza] + $ps_base, $ps_oggi);
prova($ps_stati($r)['elenco'] === 'qui' && $r['azione']['testo'] === 'Carica elenco e autorizzazione' && $r['azione']['link'] === 'elenco_studenti.php?code=FSL-AB12', "PercorsoScuola: mancano elenco e autorizzazione");
$nota = array_column($r['passi'], 'nota', 'chiave')['elenco'];
prova(str_contains($nota, '20/11/2026'), "PercorsoScuola: la scadenza è nella nota del passo");
$r = PercorsoScuola::per(['convenzione' => 'si', 'documenti' => ['elenco' => true] + $senza] + $ps_base, $ps_oggi);
prova($r['azione']['testo'] === "Carica l'autorizzazione", "PercorsoScuola: manca solo l'autorizzazione");
$r = PercorsoScuola::per(['convenzione' => 'si', 'documenti' => ['elenco' => true, 'autorizzazione' => true, 'completi' => true] + $senza] + $ps_base, $ps_oggi);
prova($ps_stati($r)['elenco'] === 'fatto' && $ps_stati($r)['attivita'] === 'qui', "PercorsoScuola: documenti completi, si passa all'attività");

// Prenotazione non ancora confermata
$r = PercorsoScuola::per(['stato' => 'da_approvare'] + $ps_base, $ps_oggi);
prova($ps_stati($r)['prenotazione'] === 'qui' && $r['azione'] === null && $r['attesa'] !== '', "PercorsoScuola: da approvare, la scuola aspetta");
$r = PercorsoScuola::per(['stato' => 'richiesta_conferma'] + $ps_base, $ps_oggi);
prova($r['azione']['link'] === 'miei_eventi.php?conferma_posto=7', "PercorsoScuola: posto disponibile, un clic per confermarlo");
$r = PercorsoScuola::per(['stato' => 'in_attesa'] + $ps_base, $ps_oggi);
prova(str_contains($r['attesa'], 'lista'), "PercorsoScuola: in lista d'attesa");

// Dopo l'attività: attestati
$fin = ['convenzione' => 'si', 'al' => '2026-10-10'] + $ps_base;
$r = PercorsoScuola::per($fin, $ps_oggi);
prova($ps_stati($r)['attivita'] === 'fatto' && $ps_stati($r)['attestati'] === 'qui' && $r['azione'] === null, "PercorsoScuola: attività finita, attestati in arrivo");
$r = PercorsoScuola::per(['attestato_inviato' => true] + $fin, $ps_oggi);
prova($r['fatto'] && $r['azione'] !== null && $r['azione']['link'] === 'attestati_gruppo.php?code=FSL-AB12', "PercorsoScuola: tutto fatto, il pulsante scarica gli attestati");
$r = PercorsoScuola::per(['attestati' => false] + $fin, $ps_oggi);
prova(!isset($ps_stati($r)['attestati']) && $r['fatto'] && $r['azione'] === null, "PercorsoScuola: attività senza attestati, il passo non compare");

// Il codice nel link è ripulito
$r = PercorsoScuola::per(['codice' => 'A B&C', 'convenzione' => 'si', 'documenti' => $senza] + $ps_base, $ps_oggi);
prova($r['azione']['link'] === 'elenco_studenti.php?code=A+B%26C', "PercorsoScuola: il codice nel link è codificato");

// ServizioPercorsiScuola::dati(): dalla riga della prenotazione alla forma di PercorsoScuola
$ps_pr = ['id' => 5, 'codice_prenotazione' => 'FSL-ZZ99', 'stato' => 'confermata', 'evento_tipo' => 'progetto', 'attestati' => 1, 'per_scuole' => 1, 'progetto_fine' => '2026-12-20', 'data_turno' => '2026-11-05', 'attestato_inviato' => 0];
$ps_conv = ['token' => 'abc', 'convenzione' => 'no', 'iter' => Costanti::ITER_CARICATA];
$ps_d = \App\Fsl\ServizioPercorsiScuola::dati($ps_pr, $ps_conv, null);
prova($ps_d['token'] === 'abc' && $ps_d['iter'] === Costanti::ITER_CARICATA && $ps_d['al'] === '2026-12-20' && $ps_d['attestati'] === true && $ps_d['attestato_inviato'] === false, "ServizioPercorsiScuola::dati(): un progetto finisce a fine progetto, ha gli attestati per le scuole");
$ps_d = \App\Fsl\ServizioPercorsiScuola::dati(['evento_tipo' => 'evento', 'per_scuole' => 0] + $ps_pr, null, null);
prova($ps_d['al'] === '2026-11-05' && $ps_d['token'] === '' && $ps_d['iter'] === '', "ServizioPercorsiScuola::dati(): un evento finisce nel giorno del turno; senza compilazione niente link");
$ps_d = \App\Fsl\ServizioPercorsiScuola::dati(['attestati' => 0] + $ps_pr, null, null);
prova($ps_d['attestati'] === false && !isset($ps_stati(PercorsoScuola::per($ps_d, '2026-10-31'))['attestati']), "ServizioPercorsiScuola::dati(): senza attestati il passo non compare");
prova(\App\Core\App::per($conn)->get(\App\Fsl\ServizioPercorsiScuola::class)->perUtente(0, 'nessuno@esempio.invalid') === [], "ServizioPercorsiScuola::perUtente(): il container la costruisce e senza prenotazioni FSL l'elenco è vuoto");

// Il riquadro «fsl_scuola» dell'area personale: nel catalogo, per le scuole (e l'amministratore), con il file che lo disegna
$ps_riq = \App\Portale\Area\CatalogoRiquadri::sistema()['fsl_scuola'] ?? null;
prova($ps_riq !== null && $ps_riq['tipo'] === \App\Portale\Area\CatalogoRiquadri::SPECIALE && $ps_riq['profili'] === [\App\Portale\Area\Profili::SCUOLA, \App\Portale\Area\Profili::AMMINISTRATORE] && is_file(__DIR__ . '/../../../inc/area/fsl_scuola.php'), "riquadro fsl_scuola: nel catalogo di sistema per Scuola e Amministratore, disegnato da inc/area/fsl_scuola.php");
