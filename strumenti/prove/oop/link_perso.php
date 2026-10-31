<?php
// Prove di «Ho perso il link» (3.A2): la scuola riceve di nuovo il link con la sua email; l'azienda già stipulata riceve lo stesso link invece di una nuova convenzione.
// Chiamato da esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione).
sezione("Ho perso il link: scuole e aziende");

use App\ConvenzioniTesi\Costanti as CT;

$lp_cont = \App\Core\App::per($conn);
$lp_email = 'scuola.9897@esempio.invalid';
$q("DELETE FROM convenzioni_compilate WHERE LOWER(email) = '$lp_email'");

// Scuole
$lp_rep = $lp_cont->get(\App\Fsl\ConvenzioneCompilataRepository::class);
$lp_svc = $lp_cont->get(\App\Fsl\ServizioConvenzioneOnline::class);
$EMAIL = [];
prova($lp_svc->rimandaLink('non-una-email') === 0 && $lp_svc->rimandaLink($lp_email) === 0 && count($EMAIL) === 0, "scuole: email non valida o senza convenzione, non parte niente");
$lp_cc = $lp_rep->crea(bin2hex(random_bytes(16)), null, 989701, strtoupper($lp_email));
prova($lp_cc !== null && count($lp_rep->perEmail($lp_email)) === 1, "scuole: la compilazione si ritrova dall'email (senza badare alle maiuscole)");
$EMAIL = [];
$n_lp = $lp_svc->rimandaLink(' ' . strtoupper($lp_email) . ' ');
prova($n_lp === 1 && count($EMAIL) === 1 && $EMAIL[0]['a'] === $lp_email && str_contains($EMAIL[0]['corpo'], 'convenzione_online.php?t=' . $lp_cc['token']), "scuole: parte una sola email, a quell'indirizzo, con il link personale della compilazione");
$lp_p = $lp_cont->get(\App\Email\InvioEmail::class)->prepara('fsl_link_ripreso', ['scuola' => 'Liceo <b>X</b>'], 'https://esempio.invalid/c?t=1');
prova(str_contains($lp_p['corpo'], 'https://esempio.invalid/c?t=1') && !str_contains($lp_p['corpo'], '<b>X</b>') && $lp_p['oggetto'] !== '', "scuole: il modello dell'email mette il link e ripulisce il nome della scuola");
$q("DELETE FROM convenzioni_compilate WHERE LOWER(email) = '$lp_email'");

// Aziende: una convenzione stipulata e valida non ne apre una seconda
$lp_az = 'azienda.9897@esempio.invalid';
$q("DELETE FROM convenzioni_tesi WHERE LOWER(email) = '$lp_az'");
$lp_tr = $lp_cont->get(\App\ConvenzioniTesi\ConvenzioneTesiRepository::class);
$lp_ts = $lp_cont->get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class);
$lp_id = $lp_tr->crea($lp_az, 'Azienda 9897', CT::ORIGINE_PUBBLICA, '');
$lp_tr->impostaStato($lp_id, CT::PROTOCOLLATA);
$q("UPDATE convenzioni_tesi SET valida_al = '" . date('Y-m-d', strtotime('+2 years')) . "' WHERE id = $lp_id");
$EMAIL = [];
[$lp_err, $lp_id2] = $lp_ts->avvia($lp_az, 'Azienda 9897', CT::ORIGINE_PUBBLICA);
prova($lp_err === null && $lp_id2 === $lp_id && count($lp_tr->perEmail([$lp_az])) === 1 && count($EMAIL) === 1, "aziende: convenzione stipulata e valida, si rimanda lo stesso link e non se ne crea un'altra");
$q("UPDATE convenzioni_tesi SET valida_al = '" . date('Y-m-d', strtotime('-1 day')) . "' WHERE id = $lp_id");
[$lp_err, $lp_id3] = $lp_ts->avvia($lp_az, 'Azienda 9897', CT::ORIGINE_PUBBLICA);
prova($lp_err === null && $lp_id3 !== $lp_id && count($lp_tr->perEmail([$lp_az])) === 2, "aziende: convenzione scaduta, la richiesta apre una convenzione nuova");
[$lp_err, $lp_id4] = $lp_ts->avvia($lp_az, 'Azienda 9897', CT::ORIGINE_PUBBLICA);
prova($lp_id4 === $lp_id3 && count($lp_tr->perEmail([$lp_az])) === 2, "aziende: convenzione ancora aperta, si rimanda quel link");
$q("DELETE FROM convenzioni_tesi WHERE LOWER(email) = '$lp_az'");
