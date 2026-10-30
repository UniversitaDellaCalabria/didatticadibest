<?php
// Prove di «Entra come…» (App\Auth\SimulazioneAccesso e la guardia inc/simulazione_guardia.php). Chiamato da esegui.php. Dati di prova con id 9961x.
sezione("Simulazione di accesso (Entra come…)");

use App\Auth\SimulazioneAccesso;
use Tests\Doppi\AuthSessioneInMemoria;

require_once __DIR__ . '/../../../tests/Doppi/AuthSessioneInMemoria.php';
$cont_sm = \App\Core\App::per($conn);
$db_sm = $cont_sm->get(\App\Core\Database::class);
$q("DELETE FROM utenti WHERE id BETWEEN 99601 AND 99605");
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, codice_fiscale) VALUES
    (99601, 'Amm', 'Inistratore', 'amm-sm996@example.org', 1, '', 'AMMSM996'), (99602, 'Altro', 'Amministratore', 'alt-sm996@example.org', 5, '1', 'ALTSM996'),
    (99603, 'Stu', 'Dente', 'stu-sm996@example.org', 3, '', 'STUSM996'), (99604, 'Gestore', 'Area', 'ges-sm996@example.org', 2, '', 'GESSM996'), (99605, 'Os', 'Pite', 'osp-sm996@example.org', 5, '', 'OSPSM996')");
$nuova = function (array $dati = []) use ($cont_sm): array {
    $s = new AuthSessioneInMemoria();
    $s->dati = $dati + ['utente_id' => 99601, 'utente_cf' => 'AMMSM996', 'utente_nome' => 'Amm Inistratore', 'utente_email' => 'amm-sm996@example.org', 'utente_ruolo_id' => 1, 'utente_ruoli_secondari' => '', 'auth_meta' => ['x' => 1]];

    return [$s, new SimulazioneAccesso($s, $cont_sm->get(\App\Auth\UtenteRepository::class), new \App\Infrastructure\Audit\AuditLog($cont_sm->get(\App\Core\Database::class)))];
};

[$s1, $sim] = $nuova();
prova($sim->puoSimulare() && !$sim->attiva() && $sim->stato() === null, "simulazione: un amministratore può cominciare, e all'inizio non c'è niente di attivo");
prova($sim->avvia(99603, 'Studente', '127.0.0.1') === null && $sim->attiva(), "simulazione: l'amministratore entra come uno studente");
prova($s1->dati['utente_id'] === 99603 && $s1->dati['utente_ruolo_id'] === 3 && $s1->dati['utente_email'] === 'stu-sm996@example.org' && $s1->dati['utente_nome'] === 'Stu Dente' && !isset($s1->dati['auth_meta']), "simulazione: la sessione prende l'identità della persona (id, ruolo, email, nome)");
$st = $sim->stato();
prova($st !== null && $st['target_id'] === 99603 && $st['profili'] === 'Studente' && $st['fino'] > time() + 3000 && $sim->idAmministratore() === 99601, "simulazione: lo stato dice come chi, per quanto, e chi è l'amministratore vero");
prova(!$sim->puoSimulare() && $sim->avvia(99604, '', '') !== null, "simulazione: da dentro una simulazione non se ne comincia un'altra");
$sim->termina('127.0.0.1');
prova(!$sim->attiva() && $s1->dati['utente_id'] === 99601 && $s1->dati['utente_ruolo_id'] === 1 && $s1->dati['auth_meta'] === ['x' => 1] && !isset($s1->dati['simula']), "simulazione: «Torna a te» rimette tutta l'identità vera");
$reg = (int) $db_sm->valore("SELECT COUNT(*) FROM log_attivita WHERE utente_id = 99601 AND azione LIKE 'Simulazione di accesso:%'");
prova($reg === 2, "simulazione: inizio e fine sono nel registro delle operazioni, a nome dell'amministratore vero", (string) $reg);

[$s2, $sim2] = $nuova();
prova($sim2->avvia(99602, '', '') !== null && $sim2->avvia(99601, '', '') !== null && $sim2->avvia(99999, '', '') !== null && $s2->dati['utente_id'] === 99601, "simulazione: non si simula un altro amministratore (anche secondario), se stessi o chi non esiste");
[$s3, $sim3] = $nuova(['utente_id' => 99603, 'utente_ruolo_id' => 3, 'utente_ruoli_secondari' => '']);
prova(!$sim3->puoSimulare() && $sim3->avvia(99604, '', '') !== null && $s3->dati['utente_id'] === 99603, "simulazione: uno studente non può simulare nessuno");
[$s4, $sim4] = $nuova(['utente_id' => 99602, 'utente_ruolo_id' => 5, 'utente_ruoli_secondari' => '1']);
prova($sim4->puoSimulare(), "simulazione: vale anche per chi è amministratore come ruolo secondario");
[$s5, $sim5] = $nuova();
$sim5->avvia(99604, 'Gestore', '');
$s5->dati['simula']['fino'] = time() - 5;
prova(!$sim5->attiva() && $sim5->stato() === null, "simulazione: scaduta, non è più attiva");

// La guardia (inc/simulazione_guardia.php) si prova in un processo a parte: blocca con exit
$guardia = realpath(__DIR__ . '/../../../inc/simulazione_guardia.php');
$esegui_g = function (array $sessione, array $server, array $get = []) use ($guardia): array {
    $f = sys_get_temp_dir() . '/guardia_prova_' . getmypid() . '.php';
    file_put_contents($f, '<?php $_SESSION = ' . var_export($sessione, true) . '; $_SERVER = ' . var_export($server, true) . '; $_GET = ' . var_export($get, true) . "; require " . var_export($guardia, true) . "; echo 'PASSATO|' . json_encode(\$_SESSION['utente_id'] ?? null) . '|' . (isset(\$_SESSION['simula']) ? 'sim' : 'nosim');");
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);

    return [str_contains((string) $out, 'PASSATO'), (string) $out];
};
$sim_attiva = ['simula' => ['origine' => ['utente_id' => 99601, 'utente_ruolo_id' => 1], 'nome' => 'Stu Dente', 'fino' => time() + 600], 'utente_id' => 99603, 'utente_ruolo_id' => 3];
[$passa, $out] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/eventi/area_personale.php']);
prova($passa, "guardia: una pagina letta in visione passa", $out);
[$passa, $out] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/master_template.php']);
prova(!$passa && str_contains($out, 'bloccata') && str_contains($out, 'simula_esci.php'), "guardia: un invio di moduli (POST) è bloccato e offre «Torna a te»");
[$passa, $out] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/assistenza_ajax.php', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
prova(!$passa && str_contains($out, '"ok":false'), "guardia: una richiesta AJAX bloccata riceve la risposta in JSON");
[$passa] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/eventi/area_personale.php'], ['conferma_posto' => 'x']);
prova(!$passa, "guardia: un indirizzo che esegue un'azione (conferma_posto) è bloccato anche se è un GET");
[$passa] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/eventi/admin/iscritti.php'], ['csrf_token' => 'x', 'id' => 3]);
prova(!$passa, "guardia: i link con il token CSRF (azioni dell'admin) sono bloccati");
[$passa] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/simula_esci.php']);
prova($passa, "guardia: l'uscita dalla simulazione passa");
[$passa] = $esegui_g($sim_attiva, ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/esci.php']);
prova($passa, "guardia: il logout passa");
[$passa, $out] = $esegui_g(['simula' => ['origine' => ['utente_id' => 99601, 'utente_ruolo_id' => 1], 'nome' => 'x', 'fino' => time() - 10], 'utente_id' => 99603, 'utente_ruolo_id' => 3], ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/x.php']);
prova($passa && str_contains($out, 'PASSATO|99601|nosim'), "guardia: a simulazione scaduta si torna all'amministratore e la richiesta prosegue", $out);
[$passa, $out] = $esegui_g(['simula' => ['nome' => 'x', 'fino' => time() + 100], 'utente_id' => 99603], ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/eventi/x.php']);
prova($passa && str_contains($out, 'PASSATO|null|nosim'), "guardia: una simulazione rovinata (senza identità di partenza) esce dalla sessione invece di lasciare l'identità di un altro", $out);
[$passa] = $esegui_g(['utente_id' => 99601], ['REQUEST_METHOD' => 'POST', 'SCRIPT_NAME' => '/eventi/x.php']);
prova($passa, "guardia: senza simulazione non cambia niente");

// La barra: compare solo durante la simulazione
$barra = realpath(__DIR__ . '/../../../inc/barra_simulazione.php');
$html_b = function (array $sessione) use ($barra): string {
    $_SESSION = $sessione; $_SERVER['SCRIPT_NAME'] = '/eventi/index.php';
    ob_start(); include $barra; $o = (string) ob_get_clean(); $_SESSION = [];

    return $o;
};
$b = $html_b($sim_attiva + ['csrf_token' => 'tok']);
prova(str_contains($b, 'Stu Dente') && str_contains($b, 'simula_esci.php') && str_contains($b, 'Torna a te') && str_contains($b, 'value="tok"'), "barra: durante la simulazione dice come chi si guarda e offre «Torna a te»");
prova($html_b(['utente_id' => 99601]) === '' && $html_b(['simula' => ['fino' => time() - 1, 'nome' => 'x']]) === '', "barra: senza simulazione (o scaduta) non scrive niente");
$q("DELETE FROM log_attivita WHERE utente_id = 99601 AND azione LIKE 'Simulazione di accesso:%'");
$q("DELETE FROM utenti WHERE id BETWEEN 99601 AND 99605");
