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

foreach (["DELETE FROM tutorato_incarichi WHERE bando_id = 9895", "DELETE FROM tutorato_bandi WHERE id = 9895"] as $sql) $q($sql);
