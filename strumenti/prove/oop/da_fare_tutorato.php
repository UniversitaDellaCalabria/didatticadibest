<?php
// Prove di «Da fare per te» e del Cruscotto per il tutorato: lettere da firmare del Direttore e incarico da accettare per il tutor (anche senza email).
// Chiamato da esegui.php. Dati di prova con bando 9895.
sezione("Da fare per te: tutorato (Direttore e tutor)");

foreach (["DELETE FROM tutorato_incarichi WHERE bando_id = 9895", "DELETE FROM tutorato_bandi WHERE id = 9895"] as $sql) $q($sql);
$q("INSERT INTO tutorato_bandi (id, titolo, direttore_nome, direttore_email, direttore_cf) VALUES (9895, 'Bando da fare 9895', 'Dir Prova', 'dir9895@example.org', 'BNCMRA60A01D086X')");
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, docente_email, attivita, stato, token_studente, token_direttore, firmata_docente_il) VALUES
    (9895, 'TU-DF1', 'Bianchi', 'Anna', 'BNCNNA00A41D086X', 'anna9895@example.org', 'doc9895@example.org', 'Aiuto allo studio', 'inviata', 'tokstu9895aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NULL, NULL),
    (9895, 'TU-DF2', 'Verdi', 'Luca', 'VRDLCU00A01D086X', 'luca9895@example.org', 'doc9895@example.org', 'Laboratorio', 'firmata_docente', NULL, 'tokdir9895bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW() - INTERVAL 3 DAY),
    (9895, 'TU-DF3', 'Neri', 'Gino', 'NREGNI00A01D086X', 'gino9895@example.org', 'doc9895@example.org', 'Altro', 'confermata', NULL, NULL, NULL)");

$daFare = \App\Core\App::per($conn)->get(\App\Portale\DaFarePersona::class);
$per = fn(array $u, string $chiave) => array_values(array_filter($daFare->gruppi($u), fn($g) => $g['chiave'] === $chiave));

$gd = $per(['email' => 'DIR9895@example.org'], 'tutorato_lettere_direttore');
prova(count($gd) === 1 && $gd[0]['n'] === 1 && str_contains($gd[0]['righe'][0]['link'], 'firma_incarico.php?t=tokdir9895bbbb') && str_contains($gd[0]['righe'][0]['testo'], 'Verdi'), "da fare: al Direttore del bando compare la lettera firmata dal docente", json_encode($gd));
prova($per(['email' => 'altro9895@example.org'], 'tutorato_lettere_direttore') === [] && $per(['email' => 'doc9895@example.org'], 'tutorato_lettere_direttore') === [], "da fare: a chi non è il Direttore non compare la lettera da firmare");

$gt = $per(['email' => '', 'codice_fiscale' => 'bncnna00a41d086x'], 'tutorato_accettazione');
prova(count($gt) === 1 && $gt[0]['n'] === 1 && str_contains($gt[0]['righe'][0]['link'], 'incarico.php?t=tokstu9895aaaa'), "da fare: il tutor senza email vede «Incarico da accettare» (dal codice fiscale)", json_encode($gt));
prova($per(['email' => 'anna9895@example.org', 'codice_fiscale' => ''], 'tutorato_accettazione') === [] && $per(['email' => '', 'codice_fiscale' => 'ZZZZZZ00A00A000A'], 'tutorato_accettazione') === [], "da fare: l'incarico da accettare compare solo con il codice fiscale della lettera");
prova($daFare->gruppi(['email' => '', 'codice_fiscale' => '']) === [], "da fare: senza email né codice fiscale non compare niente");

$cr = \App\Core\App::per($conn)->get(\App\Portale\CruscottoDirettore::class);
$gc = array_values(array_filter($cr->gruppi(), fn($g) => $g['chiave'] === 'tutorato_firma'));
prova(count($gc) === 1 && $gc[0]['n'] >= 1 && in_array('Tutor Verdi Luca', array_column($gc[0]['righe'], 'testo'), true), "cruscotto del Direttore: le lettere di tutorato da firmare ci sono", json_encode($gc));

$q("UPDATE tutorato_incarichi SET stato = 'firmata', token_direttore = NULL WHERE bando_id = 9895 AND codice = 'TU-DF2'");
prova($per(['email' => 'dir9895@example.org'], 'tutorato_lettere_direttore') === [], "da fare: lettera firmata dal Direttore, non compare più");


// Numero «Da fare» della barra: calcolato una volta, tenuto 5 minuti in sessione, azzerato alla chiusura di un compito, separato per persona
$q("DELETE FROM utenti WHERE id IN (98951, 98952)");
$q("INSERT INTO utenti (id, nome, cognome, email, codice_fiscale, ruolo_id, ruoli_secondari) VALUES (98951, 'Anna', 'Bianchi', 'anna9895@example.org', 'BNCNNA00A41D086X', 5, ''), (98952, 'Altro', 'Utente', 'altro9895@example.org', 'ZZZZZZ00A00A000A', 5, '')");
$sess_df = new class implements \App\Auth\Sessione {
    /** @var array<string, mixed> */
    public array $d = [];
    public function leggi(string $chiave): mixed { return $this->d[$chiave] ?? null; }
    public function scrivi(string $chiave, mixed $valore): void { $this->d[$chiave] = $valore; }
    public function togli(string $chiave): void { unset($this->d[$chiave]); }
    public function rigenera(): void {}
};
$cont_df = new \App\Portale\ContatoreDaFare($sess_df, \App\Core\App::per($conn)->get(\App\Auth\UtenteRepository::class), $daFare);
prova($cont_df->numero(98951, 1000) === 1, "contatore: il tutor con un incarico da accettare ha 1 cosa da fare");
$q("UPDATE tutorato_incarichi SET stato = 'confermata' WHERE bando_id = 9895 AND codice = 'TU-DF1'");
prova($cont_df->numero(98951, 1100) === 1, "contatore: entro i 5 minuti resta il valore in sessione (nessun nuovo calcolo)");
$cont_df->azzera();
prova($cont_df->numero(98951, 1100) === 0, "contatore: dopo l'azzeramento (compito chiuso) si ricalcola subito");
$q("UPDATE tutorato_incarichi SET stato = 'inviata' WHERE bando_id = 9895 AND codice = 'TU-DF1'");
prova($cont_df->numero(98951, 1100 + \App\Portale\ContatoreDaFare::DURATA + 1) === 1, "contatore: scaduti i 5 minuti si ricalcola da solo");
prova($cont_df->numero(98952, 1100 + \App\Portale\ContatoreDaFare::DURATA + 2) === 0 && $cont_df->numero(0, 5000) === 0, "contatore: un'altra persona (o nessuno) non eredita il numero della sessione");

// Pagina dell'elenco completo e accesso diretto
$grandi_df = $daFare->gruppi(['email' => '', 'codice_fiscale' => 'BNCNNA00A41D086X'], 200);
prova(count($grandi_df) === 1 && $grandi_df[0]['n'] === 1, "da fare: il limite di righe per gruppo è un parametro (la pagina elenco ne chiede di più)");
$p_df = (string) file_get_contents(__DIR__ . '/../../../da_fare.php');
$ar_df = (string) file_get_contents(__DIR__ . '/../../../inc/area/da_fare.php');
$sl_df = (string) file_get_contents(__DIR__ . '/../../../saml_login.php');
prova(str_contains($p_df, 'middleware.php') && str_contains($p_df, 'gruppi($user_info ?? null, 200)') && str_contains($ar_df, 'da_fare.php?g='), "pagina «Da fare»: richiede l'accesso, legge fino a 200 righe e l'area personale rimanda lì per le altre");
prova(str_contains($sl_df, "\$redirect = 'area_personale.php'") && str_contains($sl_df, "strpos(\$redirect_raw, '//') !== 0"), "accesso: senza pagina di partenza si va all'area personale e il controllo anti open-redirect resta");
$q("DELETE FROM utenti WHERE id IN (98951, 98952)");
foreach (["DELETE FROM tutorato_incarichi WHERE bando_id = 9895", "DELETE FROM tutorato_bandi WHERE id = 9895"] as $sql) $q($sql);
