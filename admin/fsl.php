<?php
// fsl.php - Formazione Scuola Lavoro (solo amministratori): pannello unico
//   Convenzioni (prima schermata «Convenzioni con le scuole»: cosa devo fare adesso; da lì: iter, scuole senza convenzione, registro, scheda scuola)
//   Riepilogo    anno scolastico di tutte le attività con "Attività di Formazione Scuola Lavoro" (qualunque area)
//   Da stipulare scuole con prenotazioni in essere e convenzione da stipulare; Allegato A e Convenzione già precompilati da scaricare
//   Convenzioni  registro per scuola: file firmati, validità, docenti dell'Allegato A; registrazione e modifica
//   Verifica     iscrizioni (anche confermate) senza una convenzione che copre il periodo dell'attività
//   Valutazioni  schede di valutazione della struttura ospitante compilate dai docenti
// Tabelle esportabili in CSV.
require_once 'admin_header.php';

// Amministratori e abilitati alla FSL (tutto) o alle sole convenzioni
if (!$puo_fsl_convenzioni) nega_accesso();

function admin_redirect($url) { echo "<script>window.location.replace(" . json_encode($url) . ");</script>"; exit; }

$SCHEDE = ['home' => ['Convenzioni con le scuole', 'fa-file-signature'], 'riepilogo' => ['Riepilogo', 'fa-chart-column'], 'iter' => ['Convenzioni da seguire', 'fa-route'], 'stipulare' => ['Da stipulare', 'fa-file-circle-exclamation'], 'convenzioni' => ['Convenzioni', 'fa-file-signature'],
           'verifica' => ['Verifica iscrizioni', 'fa-list-check'], 'valutazioni' => ['Valutazioni', 'fa-star'], 'report' => ['Report FSL', 'fa-file-excel'], 'scuola' => ['Scheda scuola', 'fa-school']];
// Abilitati alle sole convenzioni: solo quella scheda
if (!$puo_fsl) $SCHEDE = array_intersect_key($SCHEDE, ['home' => 1, 'iter' => 1, 'stipulare' => 1, 'convenzioni' => 1, 'scuola' => 1]);
$tab = isset($SCHEDE[$_GET['tab'] ?? '']) ? $_GET['tab'] : array_key_first($SCHEDE);

// ── Azioni: registro delle convenzioni e verifica delle iscrizioni ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? '');
    $registro_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioRegistroConvenzioni::class);
    // Iter della convenzione caricata dalla scuola: presa in carico, rifiuto con motivo, ricarico finale con protocollo
    // Scheda «Da stipulare»: richiesta della convenzione o messaggio ai docenti delle prenotazioni della scuola
    if (isset($_POST['st_chiedi']) || isset($_POST['st_scrivi'])) {
        $chiave_st = (string)($_POST['st_chiedi'] ?? $_POST['st_scrivi']);
        $dest_st = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class)->destinatari($chiave_st);
        $op_st = new \App\Iscritti\Operatore((int)($_SESSION['utente_id'] ?? 0), (string)($_SESSION['utente_email'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto'));
        $testo_st = trim((string)($_POST['testo'] ?? ''));
        if (!$dest_st) {
            flash_set('Nessuna prenotazione di questa scuola ha un indirizzo email a cui scrivere.', 'danger');
        } elseif (isset($_POST['st_scrivi']) && $testo_st === '') {
            flash_set('Scrivi il messaggio da mandare alla scuola.', 'danger');
        } else {
            $ok_st = 0;
            foreach ($dest_st as $d_st) {
                $ok_st += isset($_POST['st_chiedi'])
                    ? (int)\App\Core\App::per($conn)->get(\App\Iscritti\ServizioIscritti::class)->richiediConvenzione($d_st['id'])
                    : (int)\App\Core\App::per($conn)->get(\App\Iscritti\ServizioMessaggi::class)->inviaDaIscritti($d_st['id'], $testo_st, $op_st);
            }
            registra_log_audit($conn, isset($_POST['st_chiedi']) ? 'Richiesta della convenzione FSL alla scuola (da Da stipulare)' : 'Messaggio alla scuola (da Da stipulare)', ['Scuola' => $chiave_st, 'Destinatari' => $ok_st]);
            flash_set($ok_st
                ? (isset($_POST['st_chiedi']) ? 'Richiesta della convenzione inviata a ' : 'Messaggio mandato a ') . $ok_st . ($ok_st === 1 ? ' docente: ' : ' docenti: ') . implode(', ', array_map(fn($x) => $x['docente'] . ' (' . $x['email'] . ')', $dest_st)) . '.'
                : 'Email non inviata: controlla gli indirizzi (dettagli in Sistema → registro invii).', $ok_st ? 'success' : 'danger');
        }
        admin_redirect("fsl.php?p_id=$filtro_p&tab=stipulare" . (($_POST['mostra'] ?? '') === 'tutte' ? '&mostra=tutte' : '') . '&r=' . time());
    }
    // Comunicazione con la scuola: promemoria «carica il PDF» (a una scuola o a tutte) e messaggi liberi
    if (isset($_POST['cv_letto'])) {
        \App\Core\App::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class)->segnaLetti((int)$_POST['cv_letto'], 'ufficio');
        admin_redirect("fsl.php?p_id=$filtro_p&tab=iter&r=" . time() . '#cv' . (int)$_POST['cv_letto']);
    }
    if (isset($_POST['cv_ricorda']) || isset($_POST['cv_ricorda_tutte']) || isset($_POST['cv_messaggio'])) {
        $msg_cv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioMessaggiConvenzione::class);
        $autore_m = trim((string)($_SESSION['utente_nome'] ?? '')) ?: (string)($_SESSION['utente_email'] ?? 'Ufficio');
        if (isset($_POST['cv_ricorda_tutte'])) {
            [$partiti_m, $saltati_m] = $msg_cv->ricordaATutte($autore_m);
            registra_log_audit($conn, "Promemoria della convenzione FSL a tutte le scuole in attesa", ["Mandati" => $partiti_m, "Saltati" => $saltati_m]);
            flash_set($partiti_m . ($partiti_m === 1 ? ' promemoria mandato' : ' promemoria mandati') . ($saltati_m ? " ($saltati_m scuole saltate: già avvisate nelle ultime 24 ore o senza email)" : '') . '.', $partiti_m ? 'success' : 'warning');
        } elseif (isset($_POST['cv_ricorda'])) {
            $id_m = (int)$_POST['cv_ricorda'];
            $err_m = $msg_cv->ricordaPdf($id_m, $autore_m);
            if (!$err_m) registra_log_audit($conn, "Promemoria della convenzione FSL alla scuola", ["Compilazione" => $id_m]);
            flash_set($err_m ?? "Promemoria mandato: la scuola riceve l'email con il link per caricare il PDF firmato.", $err_m ? 'danger' : 'success');
        } else {
            $id_m = (int)$_POST['cv_messaggio'];
            $err_m = $msg_cv->scriviAllaScuola($id_m, (string)($_POST['testo'] ?? ''), $autore_m);
            if (!$err_m) registra_log_audit($conn, "Messaggio alla scuola sulla convenzione FSL", ["Compilazione" => $id_m, "Testo" => mb_substr((string)($_POST['testo'] ?? ''), 0, 200)]);
            flash_set($err_m ?? "Messaggio mandato: la scuola lo riceve per email e può rispondere dalla sua pagina.", $err_m ? 'danger' : 'success');
        }
        admin_redirect("fsl.php?p_id=$filtro_p&tab=iter&r=" . time() . (isset($id_m) && !isset($_POST['cv_ricorda_tutte']) ? '#cv' . $id_m : ''));
    }
    if (isset($_POST['cv_invia_direttore'])) {
        $id_i = (int)$_POST['cv_invia_direttore'];
        $err_i = \App\Core\App::per($conn)->get(\App\Fsl\ServizioFirmaDirettore::class)->invia($id_i);
        if (!$err_i) registra_log_audit($conn, "Convenzione FSL mandata al Direttore per la firma", ["Compilazione" => $id_i]);
        flash_set($err_i ?? "Mandata al Direttore: ha ricevuto l'email con il link per firmare. Quando firma ti avvisiamo.", $err_i ? 'danger' : 'success');
        admin_redirect("fsl.php?p_id=$filtro_p&tab=iter&r=" . time());
    }
    if (isset($_POST['cv_prendi']) || isset($_POST['cv_rifiuta']) || isset($_POST['cv_completa'])) {
        $iter_cv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneFirmata::class);
        $autore_cv = (string)($_SESSION['utente_email'] ?? '');
        if (isset($_POST['cv_prendi'])) {
            $id_i = (int)$_POST['cv_prendi'];
            $es_i = $iter_cv->protocolloIngresso($id_i, (string)($_POST['prot_ingresso'] ?? ''), (string)($_POST['prot_ingresso_data'] ?? ''), $autore_cv);
            $err_i = $es_i['errore'];
            if (!$err_i) registra_log_audit($conn, "Convenzione FSL protocollata in entrata", ["Compilazione" => $id_i, "Protocollo in entrata" => (string)($_POST['prot_ingresso'] ?? '')]);
            flash_set($err_i ?? ("Protocollo in entrata registrato: la scuola è stata avvisata. " . ($es_i['direttore'] === null ? "Il Direttore ha ricevuto l'email con il link per firmare." : $es_i['direttore'])), $err_i ? 'danger' : ($es_i['direttore'] === null ? 'success' : 'warning'));
        } elseif (isset($_POST['cv_rifiuta'])) {
            $id_i = (int)$_POST['cv_rifiuta'];
            $err_i = $iter_cv->rifiuta($id_i, (string)($_POST['motivo'] ?? ''));
            if (!$err_i) registra_log_audit($conn, "Convenzione FSL rifiutata", ["Compilazione" => $id_i, "Motivo" => mb_substr((string)($_POST['motivo'] ?? ''), 0, 200)]);
            flash_set($err_i ?? "Convenzione rifiutata: la scuola ha ricevuto il motivo per email e può caricare un altro file.", $err_i ? 'danger' : 'warning');
        } else {
            $id_i = (int)$_POST['cv_completa'];
            $pdf_i = $iter_cv->contenutoDaUpload($_FILES['file_definitivo'] ?? null);
            $esito_i = $iter_cv->completa($id_i, (string)$pdf_i, $_POST, $autore_cv);
            if ($esito_i['errore']) {
                flash_set($esito_i['errore'], 'danger');
            } else {
                registra_log_audit($conn, "Convenzione FSL protocollata e registrata", ["Compilazione" => $id_i, "Convenzione" => $esito_i['convenzione_id'], "Protocollo" => (string)($_POST['protocollo'] ?? ''), "Prenotazioni confermate" => $esito_i['prenotazioni']]);
                flash_set("Convenzione registrata. " . ($esito_i['prenotazioni'] ? $esito_i['prenotazioni'] . " prenotazioni della scuola in attesa sono state confermate (le persone sono state avvisate per email). " : '') . "La scuola ha ricevuto l'avviso che la convenzione è attiva.");
            }
        }
        admin_redirect("fsl.php?p_id=$filtro_p&tab=iter&r=" . time());
    }
    // Protocollo di una convenzione compilata online dalla scuola: compare nei documenti Word generati
    if (isset($_POST['conv_online_prot'])) {
        \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->salvaProtocollo((int)$_POST['conv_online_prot'], (string)($_POST['protocollo'] ?? ''), (string)($_POST['protocollo_data'] ?? ''));
        flash_set("Protocollo salvato: compare nei documenti della convenzione.");
        admin_redirect("fsl.php?p_id=$filtro_p&tab=iter&r=" . time());
    }
    // Registro delle convenzioni: registrazione o modifica (con i file firmati e i docenti dell'Allegato A)
    if (isset($_POST['conv_salva'])) {
        $esito_cv = $registro_fsl->salvaDalPannello($_POST, $_FILES, (string)($_SESSION['utente_email'] ?? ''));
        if (!$esito_cv->salvata) {
            flash_set("Convenzione non salvata: scegli la scuola dall'elenco dell'anagrafe e controlla le date (\"valida fino al\" non può precedere \"valida dal\").", 'danger');
            admin_redirect("fsl.php?p_id=$filtro_p&tab=convenzioni" . ($esito_cv->id > 0 ? "&conv_mod={$esito_cv->id}" : '') . "&r=" . time() . "#convForm");
        }
        $cod_cv = $esito_cv->codiceScuola; $n_cv = $esito_cv->prenotazioniAggiornate; $errori_file = $esito_cv->fileNonCaricati;
        $s_cv = scuola_per_codice($conn, $cod_cv);
        registra_log_audit($conn, $esito_cv->modifica ? "Convenzione modificata" : "Convenzione registrata", ["Scuola" => $cod_cv, "Valida dal" => $_POST['data_stipula'] ?? '', "Valida fino al" => $_POST['scadenza'] ?? '', "Prenotazioni aggiornate" => $n_cv]);
        flash_set("Convenzione " . ($esito_cv->modifica ? "aggiornata" : "registrata") . " per " . etichetta_scuola($s_cv) . "."
                  . ($n_cv ? " $n_cv prenotazioni della scuola coperte dalla convenzione (chi era in attesa è stato avvisato per email)." : '')
                  . ($errori_file ? " File non caricati (serve un PDF; la convenzione deve essere firmata digitalmente in PAdES, i .p7m CAdES non sono accettati):" . implode(', ', $errori_file) . "." : ''), $errori_file ? 'warning' : 'success');
    }
    if (isset($_POST['conv_elimina'])) {
        $id_cv = (int)$_POST['conv_elimina'];
        $vecchia = $registro_fsl->elimina($id_cv);
        if ($vecchia) {
            registra_log_audit($conn, "Convenzione eliminata dal registro", ["ID" => $id_cv, "Scuola" => $vecchia['scuola_codice']]);
            flash_set("Convenzione eliminata dal registro con i suoi file. Premi \"Verifica ora\" per aggiornare le iscrizioni della scuola.", 'warning');
        }
    }
    if (isset($_POST['conv_verifica']) && $puo_fsl) {
        $v = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioni::class)->verifica();
        registra_log_audit($conn, "Verifica convenzioni FSL", $v);
        flash_set("Verifica completata: {$v['coperte']} iscrizioni coperte da una convenzione, {$v['da_stipulare']} senza convenzione valida per il periodo"
                  . ($v['nuove_da_stipulare'] ? " ({$v['nuove_da_stipulare']} segnate ora come \"da stipulare\")" : '')
                  . ($v['senza_codice'] ? ", {$v['senza_codice']} con la scuola scritta a mano (abbinala all'anagrafe per verificarla)" : '') . ".",
                  $v['da_stipulare'] || $v['senza_codice'] ? 'warning' : 'success');
    }
    admin_redirect("fsl.php?p_id=$filtro_p&tab=" . (isset($_POST['conv_verifica']) ? 'verifica' : 'convenzioni') . (($_GET['vista'] ?? '') === 'archivio' ? '&vista=archivio' : '') . "&r=" . time());
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$d = fn($x) => $x ? date('d/m/Y', strtotime($x)) : '—';

$pannello_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioPannelloFsl::class);
// Scuole con prenotazioni FSL in essere (scheda «Da stipulare»): convenzione da stipulare e documenti precompilati
$scuole_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class)->scuole();
$n_scuole_da_stipulare = count(array_filter($scuole_fsl, fn($g) => $g['da_stipulare']));

// Anno scolastico: dal 1° settembre al 31 agosto (default: quello in corso)
$anno_corr = $pannello_fsl->annoCorrente();
$anno = (int)($_GET['anno'] ?? $anno_corr);
if ($anno < 2015 || $anno > $anno_corr + 1) $anno = $anno_corr;
$dal_a = "$anno-09-01"; $al_a = ($anno + 1) . "-08-31";

// Report FSL dell'anno scolastico (ore per scuola, per attività e per studente) con il file Excel
if ($tab === 'report' && $puo_fsl) {
    $rep_fsl = \App\Core\App::per($conn)->get(\App\Fsl\ReportFsl::class);
    $dati_fsl_rep = $rep_fsl->dati($dal_a, $al_a);
    if (isset($_GET['xlsx']) || isset($_GET['scarica'])) {
        registra_log_audit($conn, "Report FSL esportato", ["Anno scolastico" => "$anno/" . ($anno + 1), "Formato" => $_GET['scarica'] ?? 'xlsx']);
        while (ob_get_level() > 0) ob_end_clean();
        \App\Infrastructure\Documenti\FogliEsportabili::scaricaDa((string)($_GET['scarica'] ?? 'xlsx'), 'Report FSL ' . $anno . '/' . ($anno + 1), 'Report_FSL_' . $anno . '_' . ($anno + 1), fn() => $rep_fsl->esporta($dati_fsl_rep, "$anno/" . ($anno + 1)));
    }
}

// Iscrizioni alle attività FSL che iniziano nell'anno scolastico (esclusi annullamenti, rifiuti, scadute), numeri per scuola e per attività, schede di valutazione
['righe' => $righe, 'conf' => $conf, 'per_scuola' => $per_scuola, 'per_att' => $per_att, 'n_studenti' => $n_studenti, 'n_conv_mancanti' => $n_conv_mancanti,
 'valutazioni' => $valutazioni, 'somme' => $somme, 'rip' => $rip, 'n_inviti' => $n_inviti] = $pannello_fsl->riepilogo($dal_a, $al_a);
$media = fn($arr) => $arr ? number_format(array_sum($arr) / count($arr), 1, ',', '') : '—';
$anni = range($anno_corr + 1, max(2020, $anno_corr - 5));
$oggi_f = date('Y-m-d');
$chiavi_fsl = array_flip(array_column($scuole_fsl, 'chiave'));
$perc = \App\Fsl\PercorsoFsl::contatori($righe, $oggi_f);

// Registro delle convenzioni raggruppato per scuola (la più recente per prima), diviso in due viste:
// in vigore (valide, in scadenza, non ancora valide) e archivio (scadute: ci passano da sole il giorno dopo la scadenza)
$vista_cv = ($_GET['vista'] ?? '') === 'archivio' ? 'archivio' : 'vigore';
['vigore' => $conv_vigore, 'archivio' => $conv_archivio] = $pannello_fsl->registro();
$conv_per_scuola = $vista_cv === 'archivio' ? $conv_archivio : $conv_vigore;
// Iscrizioni senza convenzione valida: da stipulare (attività FSL o richiesta dai gestori) e scuole scritte a mano nelle attività FSL
[$iscr_da_stipulare, $iscr_ora_coperte] = $pannello_fsl->daStipulare();
// Modifica di una convenzione, oppure nuova con scuola e periodo proposti (pulsante "Registra" della verifica)
$conv_mod = !empty($_GET['conv_mod']) ? $pannello_fsl->convenzione((int)$_GET['conv_mod']) : null;
$conv_nuova_s = !$conv_mod && !empty($_GET['conv_nuova']) ? scuola_per_codice($conn, (string)$_GET['conv_nuova']) : null;
$conv_rinnova = !$conv_mod && !empty($_GET['conv_rinnova']) ? $pannello_fsl->convenzione((int)$_GET['conv_rinnova']) : null;
if ($conv_rinnova) $conv_nuova_s = scuola_per_codice($conn, $conv_rinnova['scuola_codice']);
$data_get = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? $_GET[$k] : null;
[$conv_def_dal, $conv_def_al] = periodo_nuova_convenzione($data_get('dal'), $data_get('al'));
$oggi_cv = date('Y-m-d'); $tra60_cv = date('Y-m-d', strtotime('+60 days'));
// Convenzioni compilate online dalle scuole (convenzione_online.php), con la fase in cui si trovano (scheda «Convenzioni da seguire»)
$cv_online = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class)->recenti();
$n_conv_seguire = count(array_filter($cv_online, fn($c) => in_array($c['stato_iter'] ?? '', [\App\Fsl\Costanti::ITER_CARICATA, \App\Fsl\Costanti::ITER_IN_CARICO, \App\Fsl\Costanti::ITER_FIRMATA], true)));
// Dove si trova ogni convenzione caricata dalle scuole: ognuna è in una di queste fasi (la fase dipende da stato_iter)
$fasi_cv = ['attesa' => [], 'caricata' => [], 'dirfirma' => [], 'firmata' => [], 'rifiutata' => [], 'fatte' => []];
foreach ($cv_online as $co) {
    $it = (string)($co['stato_iter'] ?? '');
    $fasi_cv[match (true) {
        $it === 'caricata_scuola' => 'caricata',
        in_array($it, ['in_carico', 'da_firmare'], true) => 'dirfirma',
        $it === 'firmata_direttore' => 'firmata',
        $it === 'rifiutata' => 'rifiutata',
        $it === 'protocollata' => 'fatte',
        default => 'attesa',
    }][] = $co;
}
$n_compiti_tuoi = count($fasi_cv['caricata']) + count($fasi_cv['firmata']) + $n_scuole_da_stipulare;
// Stato di una convenzione del registro: [sfondo, testo, etichetta]
$stato_cv = fn(array $c): array => ($c['scadenza'] !== null && $c['scadenza'] < $oggi_cv) ? ['#fee2e2', '#991b1b', 'Scaduta']
    : (($c['data_stipula'] !== null && $c['data_stipula'] > $oggi_cv) ? ['#e0f2fe', '#0c4a6e', 'Non ancora valida']
    : (($c['scadenza'] !== null && $c['scadenza'] <= $tra60_cv) ? ['#fef3c7', '#92400e', 'In scadenza'] : ['#f0fdf4', '#166534', 'Valida']));
// Tabelle del riepilogo e delle valutazioni scaricabili in Excel, PDF e CSV (menu «Scarica»)
if (isset($_GET['scarica']) && in_array($tab, ['riepilogo', 'valutazioni'], true) && $puo_fsl) {
    $anno_txt = $anno . '/' . ($anno + 1);
    $tabelle_fsl = [
        'attivita' => fn() => ['Attività FSL ' . $anno_txt, ['Attività', 'Area', 'Dal', 'Al', 'Scuole', 'Confermate', 'Studenti', 'Presenze', 'Senza convenzione', 'Valutazioni ricevute', 'Media'],
            array_map(fn($a) => [$a['titolo'], $a['area'], $d($a['dal']), $d($a['al']), count($a['scuole']), (int)($a['confermate'] ?? 0), (int)($a['studenti'] ?? 0), (int)($a['presenze'] ?? 0), (int)($a['conv_mancanti'] ?? 0), count($a['voti'] ?? []) . '/' . (int)($a['val_inviate'] ?? 0), $media($a['voti'] ?? [])], $per_att), [4, 2.5, 1.3, 1.3, 1, 1.2, 1.1, 1.1, 1.4, 1.4, 1]],
        'scuole' => fn() => ['Scuole FSL ' . $anno_txt, ['Scuola', 'Codice', 'Attività', 'Iscrizioni', 'Studenti', 'Presenze', 'Convenzione in registro', 'Da stipulare', 'Valutazione'],
            array_map(function ($sc) use ($conn, $media) {
                $ult = !empty($sc['codice']) ? (convenzioni_della_scuola($conn, $sc['codice'])[0] ?? null) : null;
                return [$sc['nome'], $sc['codice'] ?? '', implode(', ', array_keys($sc['attivita'])), (int)$sc['iscrizioni'], (int)($sc['studenti'] ?? 0), (int)($sc['presenze'] ?? 0), $ult ? testo_validita_convenzione($ult) : 'nessuna', !empty($sc['conv_mancanti']) ? 'Sì' : 'No', $media($sc['voti'] ?? [])];
            }, $per_scuola), [4, 1.8, 4, 1.1, 1.1, 1.1, 3, 1.2, 1.2]],
        'valutazioni' => fn() => ['Valutazioni della struttura ospitante ' . $anno_txt, array_merge(['Data', 'Attività', 'Scuola', 'Compilata da', 'Media', 'Riproporrebbe'], array_values(VALUTAZIONE_FSL_ASPETTI), array_values(VALUTAZIONE_FSL_APERTE)),
            array_map(function ($v) use ($conn, $d) {
                $s_v = !empty($v['scuola_codice']) ? scuola_per_codice($conn, $v['scuola_codice']) : null;
                return array_merge([$d($v['created_at']), $v['titolo'], $s_v ? etichetta_scuola($s_v) : '', $v['compilata_da'], number_format((float)$v['media'], 1, ',', ''), ['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'][$v['ripeterebbe']] ?? ''],
                    array_map(fn($k) => (int)($v['risposte']['voti'][$k] ?? 0), array_keys(VALUTAZIONE_FSL_ASPETTI)), array_map(fn($k) => (string)($v['risposte']['testi'][$k] ?? ''), array_keys(VALUTAZIONE_FSL_APERTE)));
            }, $valutazioni), []],
    ];
    $quale_fsl = (string)($_GET['tabella'] ?? '');
    if (isset($tabelle_fsl[$quale_fsl])) {
        [$tit_t, $int_t, $righe_t, $larg_t] = $tabelle_fsl[$quale_fsl]();
        (new \App\Infrastructure\Documenti\TabellaEsportabile($tit_t, $int_t, $righe_t, 'Formazione Scuola Lavoro, anno scolastico ' . $anno_txt . ', stampato il ' . date('d/m/Y'), $larg_t))
            ->scarica((string)$_GET['scarica'], 'FSL_' . $quale_fsl . '_' . $anno . '_' . ($anno + 1));
    }
}
// Registro scaricabile in Excel, PDF e CSV (la vista scelta: in vigore o scadute)
if ($tab === 'convenzioni' && isset($_GET['scarica'])) {
    $righe_reg = [];
    foreach ($conv_per_scuola as $cod_r => $lista_r) {
        $s_r = scuola_per_codice($conn, $cod_r);
        foreach ($lista_r as $c_r) {
            $docs_r = array_map(fn($x) => trim(($x['nome'] ?? '') . (!empty($x['email']) ? ' <' . $x['email'] . '>' : '')), json_decode((string)($c_r['docenti_json'] ?? ''), true) ?: []);
            $righe_reg[] = [$s_r ? etichetta_scuola($s_r) : $cod_r, $cod_r, $d($c_r['data_stipula']), $d($c_r['scadenza']), $stato_cv($c_r)[2], implode('; ', $docs_r), (string)$c_r['protocollo'], (string)$c_r['note']];
        }
    }
    (new \App\Infrastructure\Documenti\TabellaEsportabile(
        $vista_cv === 'archivio' ? 'Convenzioni FSL scadute' : 'Convenzioni FSL in vigore',
        ['Scuola', 'Codice', 'Valida dal', 'Valida fino al', 'Stato', 'Docenti di riferimento', 'Protocollo', 'Note'],
        $righe_reg, 'Registro delle convenzioni per la Formazione Scuola Lavoro, stampato il ' . date('d/m/Y'), [4, 2, 1.4, 1.4, 1.4, 4, 2, 3]
    ))->scarica((string)$_GET['scarica'], 'Convenzioni_FSL_' . ($vista_cv === 'archivio' ? 'scadute' : 'in_vigore') . '_' . date('Y-m-d'));
}
?>
<style>
.fsl-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.fsl-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.fsl-num { font-size:1.6rem; font-weight:800; color:#0f172a; line-height:1; }
.fsl-barra { height:10px; background:#e2e8f0; border-radius:6px; overflow:hidden; min-width:120px; }
.fsl-barra > span { display:block; height:100%; background:#b30000; }
.scu-sez { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.04); scroll-margin-top: 80px; }
.scu-sez h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.06em; font-weight:800; color:#1e293b; margin-bottom:1rem; }
.fsl-tabs .nav-link { font-weight:700; color:#475569; }
.fsl-tabs .nav-link.active { color:#b30000; border-bottom:3px solid #b30000; }
</style>

<?php
// Intestazione: sezioni (Scuole e convenzioni · Panoramica · Valutazioni · Report). Le vecchie schede (Da stipulare, Convenzioni,
// Verifica iscrizioni) sono schermate di «Scuole e convenzioni» e i loro indirizzi continuano a funzionare
$url_fsl = fn(string $t, string $extra = '') => 'fsl.php?p_id=' . (int)$filtro_p . '&tab=' . $t . $extra;
$gruppo_fsl = in_array($tab, ['home', 'iter', 'stipulare', 'convenzioni', 'verifica', 'scuola'], true) ? 'scuole' : $tab;
$sezioni_fsl = [];
$sezioni_fsl['scuole'] = ['href' => $url_fsl('home'), 'testo' => 'Scuole e convenzioni', 'icona' => 'fa-file-signature', 'numero' => $n_compiti_tuoi, 'avviso' => true, 'titolo' => 'Cosa devo fare con le convenzioni delle scuole'];
if ($puo_fsl) $sezioni_fsl['riepilogo'] = ['href' => $url_fsl('riepilogo', '&anno=' . $anno), 'testo' => 'Panoramica', 'icona' => 'fa-chart-column'];
if ($puo_fsl) $sezioni_fsl['valutazioni'] = ['href' => $url_fsl('valutazioni', '&anno=' . $anno), 'testo' => 'Valutazioni', 'icona' => 'fa-star'];
if ($puo_fsl) $sezioni_fsl['report'] = ['href' => $url_fsl('report', '&anno=' . $anno), 'testo' => 'Report FSL', 'icona' => 'fa-file-excel'];
// Azioni rare: nel menu «Altro» (le scuole) o come pulsante (le altre sezioni)
$voce_post = function (string $nome, string $testo, string $icona, string $conferma = '') use ($h): string {
    ob_start(); csrf_field(); $c = ob_get_clean();
    return '<form method="POST">' . $c . '<button type="submit" name="' . $nome . '" value="1" class="dropdown-item"' . ($conferma !== '' ? ' data-confirm="' . $h($conferma) . '"' : '') . '><i class="fa ' . $icona . ' me-2" aria-hidden="true"></i>' . $testo . '</button></form>';
};
$azioni_fsl = '';
$url_email_fsl = 'email.php?p_id=' . (int)$filtro_p . '&amp;gruppo=Convenzioni%20FSL';
if ($gruppo_fsl === 'scuole' && $puo_fsl_convenzioni) {
    $voci_altro = [
        '<a class="dropdown-item" href="' . $h($url_fsl('convenzioni', '&nuova=1') . '#convForm') . '"><i class="fa fa-file-signature me-2" aria-hidden="true"></i>Registra una convenzione già firmata fuori dal portale</a>',
        $voce_post('cv_ricorda_tutte', 'Ricorda a tutte le scuole di caricare il PDF firmato', 'fa-bell', 'Mandare il promemoria «carica il PDF firmato» a tutte le scuole che lo stanno aspettando (saltando chi è stato avvisato nelle ultime 24 ore)?'),
    ];
    if ($puo_fsl) $voci_altro[] = $voce_post('conv_verifica', 'Controlla ora le iscrizioni senza convenzione', 'fa-rotate');
    $voci_altro[] = '<a class="dropdown-item" href="' . $url_email_fsl . '"><i class="fa fa-envelope-open-text me-2" aria-hidden="true"></i>Testi delle email</a>';
    $azioni_fsl = pn_altro($voci_altro);
} elseif ($puo_fsl_convenzioni) {
    $azioni_fsl = '<a class="btn btn-sm btn-outline-secondary" href="' . $url_email_fsl . '"><i class="fa fa-envelope-open-text me-1" aria-hidden="true"></i>Testi delle email</a>';
}
$aiuto_fsl = ['riepilogo' => 'Il percorso delle scuole, dall\'iscrizione all\'attestato, con i numeri dell\'anno scolastico. Clicca una tappa per vedere chi c\'è dentro.',
              'valutazioni' => 'Le schede di valutazione della struttura ospitante compilate dai docenti.',
              'report' => 'Per ogni scuola: attività, classi, studenti e ore di formazione dell\'anno scolastico, con il file per le scuole e per il Ministero.',
              'scuole' => $tab === 'home' ? 'Qui vedi solo quello che devi fare con le convenzioni delle scuole. Il resto lo trovi nel registro.' : ''][$gruppo_fsl] ?? '';
echo pn_testata('Formazione Scuola Lavoro', 'fa-briefcase', $aiuto_fsl, $azioni_fsl);
echo pn_schede($sezioni_fsl, $gruppo_fsl, 'Sezioni della Formazione Scuola Lavoro');
// Scelta tra elenchi vicini: un'unica riga di pulsanti con il numero
$seg = function (array $voci, string $attivo) use ($h): string {
    $o = '<nav class="pn-segmenti" aria-label="Quali scuole vedere">';
    foreach ($voci as $k => $v) $o .= '<a href="' . $h($v['href']) . '"' . ((string)$k === $attivo ? ' class="attivo" aria-current="true"' : '') . '>' . $h($v['testo']) . (isset($v['numero']) ? ' (' . (int)$v['numero'] . ')' : '') . '</a>';
    return $o . '</nav>';
};
if ($gruppo_fsl === 'scuole') {
    if ($puo_fsl_convenzioni && \App\Core\App::per($conn)->get(\App\Fsl\ServizioFirmaDirettore::class)->direttore()['email'] === '') {
        echo '<div class="alert alert-warning small py-2"><i class="fa fa-user-tie me-1" aria-hidden="true"></i><strong>Manca il Direttore che firma le convenzioni.</strong> <a href="uffici.php?u=direzione" class="fw-bold">Indicalo nella Direzione</a> (Gestione del portale → Uffici): a lui arriva il link per firmare.</div>';
    }
    $titoli_sez = ['iter' => ['Convenzioni da seguire', 'Le convenzioni caricate dalle scuole, passo per passo: protocollo in entrata, firma del Direttore, protocollo definitivo.'],
                   'stipulare' => ['Scuole senza convenzione', 'Scuole con prenotazioni ma senza una convenzione valida.'],
                   'verifica' => ['Scuole senza convenzione', 'Iscrizioni alle attività FSL che non sono coperte da una convenzione valida.'],
                   'convenzioni' => ['Registro delle convenzioni', 'Tutte le convenzioni firmate, con i file, la validità e i docenti di riferimento.']];
    if (isset($titoli_sez[$tab])) {
        echo pn_indietro($url_fsl('home'), 'Torna a «Convenzioni con le scuole»');
        echo '<h2 class="fw-bold mb-1" style="font-size:1.35rem;text-transform:none;letter-spacing:0;">' . $h($titoli_sez[$tab][0]) . '</h2><p class="text-secondary mb-3">' . $h($titoli_sez[$tab][1]) . '</p>';
    }
    if (in_array($tab, ['stipulare', 'verifica'], true)) {
        $voci_seg = ['da_stipulare' => ['href' => $url_fsl('stipulare'), 'testo' => 'Da stipulare', 'numero' => $n_scuole_da_stipulare],
                     'tutte' => ['href' => $url_fsl('stipulare', '&mostra=tutte'), 'testo' => 'Tutte le scuole con prenotazioni', 'numero' => count($scuole_fsl)]];
        if ($puo_fsl) $voci_seg['verifica'] = ['href' => $url_fsl('verifica'), 'testo' => 'Iscrizioni senza convenzione', 'numero' => count($iscr_da_stipulare)];
        echo '<div class="pn-linea">' . $seg($voci_seg, $tab === 'verifica' ? 'verifica' : (($_GET['mostra'] ?? '') === 'tutte' ? 'tutte' : 'da_stipulare')) . '</div>';
    }
}
?>

<?php if ($tab === 'home'):
$n_msg_h = array_sum(\App\Core\App::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class)->nonLetteDallUfficio());
$n_scad_h = 0;
foreach ($conv_vigore as $lista_h) foreach ($lista_h as $c_h) if ($c_h['scadenza'] !== null && $c_h['scadenza'] <= $tra60_cv && $c_h['scadenza'] >= $oggi_cv) $n_scad_h++;
$compiti_h = [
    ['numero' => count($fasi_cv['caricata']), 'titolo' => 'Da prendere in carico', 'testo' => 'La scuola ha caricato il PDF firmato: devi protocollarlo.', 'href' => $url_fsl('iter') . '#fase-caricata', 'bottone' => 'Apri e gestisci', 'tono' => 'tuo', 'vuoto' => 'Nessun PDF da prendere in carico.'],
    ['numero' => count($fasi_cv['firmata']), 'titolo' => 'Da protocollare', 'testo' => 'Il Direttore ha firmato: registra il protocollo definitivo.', 'href' => $url_fsl('iter') . '#fase-firmata', 'bottone' => 'Apri e gestisci', 'tono' => 'tuo', 'vuoto' => 'Nessuna convenzione da protocollare.'],
    ['numero' => count($fasi_cv['dirfirma']), 'titolo' => 'Dal Direttore per la firma', 'testo' => 'Aspettano la firma del Direttore. Non devi fare altro.', 'href' => $url_fsl('iter') . '#fase-dirfirma', 'bottone' => 'Vedi', 'tono' => 'attesa', 'vuoto' => 'Il Direttore non ha convenzioni da firmare.'],
    ['numero' => $n_scuole_da_stipulare, 'titolo' => 'Scuole senza convenzione', 'testo' => 'Hanno prenotazioni ma non una convenzione valida.', 'href' => $url_fsl('stipulare'), 'bottone' => 'Vedi l\'elenco', 'tono' => 'tuo', 'vuoto' => 'Tutte le scuole con prenotazioni sono a posto.'],
];
if ($n_msg_h) array_splice($compiti_h, 2, 0, [['numero' => $n_msg_h, 'titolo' => $n_msg_h === 1 ? 'Messaggio da una scuola' : 'Messaggi dalle scuole', 'testo' => 'Una scuola ti ha risposto: apri «Scrivi alla scuola».', 'href' => $url_fsl('iter'), 'bottone' => 'Leggi', 'tono' => 'tuo']]);
echo pn_compiti($compiti_h, 'Cosa devo fare con le convenzioni delle scuole');
$opz_scuole = [];
foreach ($scuole_fsl as $g_h) $opz_scuole[$g_h['nome'] . ($g_h['comune'] !== '' ? ' – ' . $g_h['comune'] : '')] = $url_fsl('scuola', '&k=' . urlencode($g_h['chiave']));
?>
<?php if ($n_scad_h): ?><div class="alert alert-warning py-2"><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i><strong><?php echo $n_scad_h === 1 ? 'Una convenzione scade' : $n_scad_h . ' convenzioni scadono'; ?> entro 60 giorni.</strong> <a class="fw-bold" href="<?php echo $h($url_fsl('convenzioni') . '#registro'); ?>">Apri il registro</a> e premi «Rinnova».</div><?php endif; ?>
<div class="pn-linea">
    <input type="search" id="cercaScuolaFsl" list="elencoScuoleFsl" class="form-control" style="max-width:360px;" placeholder="Cerca una scuola con prenotazioni" aria-label="Cerca una scuola con prenotazioni" autocomplete="off">
    <datalist id="elencoScuoleFsl"><?php foreach (array_keys($opz_scuole) as $nome_h): ?><option value="<?php echo $h($nome_h); ?>"><?php endforeach; ?></datalist>
    <a class="btn btn-outline-secondary fw-bold pn-spazio" href="<?php echo $h($url_fsl('convenzioni') . '#registro'); ?>"><i class="fa fa-folder-open me-1" aria-hidden="true"></i>Registro delle convenzioni (<?php echo count($conv_vigore); ?> in vigore)</a>
</div>
<script>
(function () {
    var mappa = <?php echo json_encode($opz_scuole, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    document.getElementById('cercaScuolaFsl').addEventListener('change', function () { if (mappa[this.value]) window.location.href = mappa[this.value]; });
})();
</script>

<?php elseif ($tab === 'riepilogo'): ?>
<p class="text-secondary small">Tutte le attività con <strong>Attività di Formazione Scuola Lavoro</strong> attivo, di ogni area, che iniziano tra il <?php echo $d($dal_a); ?> e il <?php echo $d($al_a); ?>. Studenti = numero dichiarato nelle iscrizioni confermate. Ogni tabella si scarica in Excel, PDF o CSV (menu «Scarica»).</p>

<?php
$n_el_mancanti = count($perc['elenchi_mancanti']);
echo pn_compiti([
    ['numero' => $n_scuole_da_stipulare, 'titolo' => 'Scuole senza convenzione', 'testo' => 'Hanno prenotazioni ma non una convenzione valida.', 'href' => $url_fsl('stipulare'), 'bottone' => 'Vedi l\'elenco', 'tono' => 'tuo', 'vuoto' => 'Tutte le scuole sono a posto.'],
    ['numero' => $n_el_mancanti, 'titolo' => 'Elenchi studenti mancanti', 'testo' => 'Attività finite senza l\'elenco dei nomi: senza, gli attestati non partono.', 'href' => '#elenchi', 'bottone' => 'Vedi le attività', 'tono' => 'tuo', 'vuoto' => 'Tutti gli elenchi sono arrivati.'],
    ['numero' => max(0, (int)$perc['attestati_dovuti'] - (int)$perc['attestati_inviati']), 'titolo' => 'Attestati da inviare', 'testo' => 'Attività finite con attestati non ancora spediti.', 'href' => '#tabAtt', 'bottone' => 'Vedi le attività', 'tono' => 'tuo', 'vuoto' => 'Tutti gli attestati sono partiti.'],
    ['numero' => max(0, (int)$n_inviti - count($valutazioni)), 'titolo' => 'Valutazioni attese', 'testo' => 'Schede inviate ai docenti e non ancora compilate.', 'href' => $url_fsl('valutazioni', '&anno=' . $anno), 'bottone' => 'Vedi', 'tono' => 'attesa', 'vuoto' => 'Nessuna scheda in attesa.'],
], 'Cosa devo fare nell\'anno scolastico');
echo pn_percorso([
    ['numero' => count($per_att), 'testo' => 'Attività con iscrizioni', 'nota' => 'dell\'anno scolastico', 'href' => '#tabAtt'],
    ['numero' => $perc['scuole'], 'testo' => 'Scuole iscritte', 'nota' => count($conf) . ' iscrizioni · ' . $n_studenti . ' studenti' . ($perc['in_attesa'] ? ' · ' . $perc['in_attesa'] . ' in attesa' : ''), 'href' => '#tabScu'],
    ['numero' => $n_scuole_da_stipulare, 'testo' => 'Convenzioni da stipulare', 'nota' => $n_conv_mancanti ? $n_conv_mancanti . ' iscrizioni senza convenzione valida' : 'tutto a posto', 'href' => $url_fsl('stipulare'), 'avviso' => $n_scuole_da_stipulare > 0],
    ['numero' => $n_el_mancanti, 'testo' => 'Elenchi studenti mancanti', 'nota' => 'attività finite, elenco non inserito', 'href' => $n_el_mancanti ? '#elenchi' : '', 'avviso' => $n_el_mancanti > 0],
    ['numero' => $perc['attestati_inviati'] . '/' . $perc['attestati_dovuti'], 'testo' => 'Attestati inviati', 'nota' => 'delle attività finite con attestati'],
    ['numero' => count($valutazioni) . ($n_inviti ? '/' . $n_inviti : ''), 'testo' => 'Valutazioni ricevute', 'nota' => $valutazioni ? 'media ' . $media(array_map(fn($x) => (float)$x['media'], $valutazioni)) : 'nessuna ancora', 'href' => $url_fsl('valutazioni', '&anno=' . $anno)],
], 'Percorso delle scuole nell\'anno scolastico');
?>

<?php if ($n_el_mancanti): ?>
<section class="fsl-sez" id="elenchi">
    <h2><i class="fa fa-user-graduate me-1" aria-hidden="true"></i>Elenchi studenti da inserire (<?php echo $n_el_mancanti; ?>)</h2>
    <p class="small text-secondary">Attività già finite con attestati per gli studenti ma senza l'elenco dei nomi: finché manca, gli attestati non partono. Il docente riceve un promemoria; da qui puoi aprire l'iscrizione.</p>
    <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
        <thead class="table-light"><tr><th>Scuola</th><th>Attività</th><th>Finita il</th><th>Docente</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($perc['elenchi_mancanti'] as $x): ?>
            <tr>
                <td class="fw-semibold"><?php echo $h($x['scuola_nome']); ?></td><td><?php echo $h($x['titolo']); ?><div class="text-secondary"><?php echo $h($x['area']); ?></div></td>
                <td class="text-nowrap"><?php echo $d($x['al']); ?></td>
                <td><?php echo $h(trim($x['nome'] . ' ' . $x['cognome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?></div></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary fw-bold py-0" href="iscritti.php?p_id=<?php echo (int)$x['pagina_id']; ?><?php echo !empty($x['archiviato']) ? '&archivio=1' : ''; ?>&f_cerca=<?php echo urlencode($x['codice_prenotazione']); ?>">Apri l'iscrizione</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>

<section class="fsl-sez">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-list me-1" aria-hidden="true"></i>Attività (<?php echo count($per_att); ?>)</h2>
        <?php if ($per_att) echo pn_scarica($url_fsl('riepilogo', '&anno=' . $anno . '&tabella=attivita')); ?>
    </div>
    <?php if (!$per_att): ?><p class="text-muted small mb-0">Nessuna attività FSL con iscrizioni in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabAtt">
        <thead class="table-light"><tr><th>Attività</th><th>Area</th><th>Periodo</th><th class="text-center">Scuole</th><th class="text-center">Confermate</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th class="text-center">Senza convenzione</th><th class="text-center">Valutazioni</th><th class="text-center">Media</th></tr></thead>
        <tbody>
        <?php foreach ($per_att as $a): ?>
            <tr>
                <td class="fw-semibold"><?php echo $h($a['titolo']); ?></td><td><?php echo $h($a['area']); ?></td>
                <td class="text-nowrap"><?php echo $d($a['dal']) . ($a['al'] !== $a['dal'] ? ' – ' . $d($a['al']) : ''); ?></td>
                <td class="text-center"><?php echo count($a['scuole']); ?></td><td class="text-center"><?php echo (int)($a['confermate'] ?? 0); ?></td>
                <td class="text-center"><?php echo (int)($a['studenti'] ?? 0); ?></td><td class="text-center"><?php echo (int)($a['presenze'] ?? 0); ?></td>
                <td class="text-center <?php echo !empty($a['conv_mancanti']) ? 'text-danger fw-bold' : ''; ?>"><?php echo (int)($a['conv_mancanti'] ?? 0); ?></td>
                <td class="text-center"><?php echo count($a['voti'] ?? []) . '/' . (int)($a['val_inviate'] ?? 0); ?></td><td class="text-center"><?php echo $media($a['voti'] ?? []); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="fsl-sez">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-school me-1" aria-hidden="true"></i>Scuole (<?php echo count($per_scuola); ?>)</h2>
        <?php if ($per_scuola) echo pn_scarica($url_fsl('riepilogo', '&anno=' . $anno . '&tabella=scuole')); ?>
    </div>
    <?php if (!$per_scuola): ?><p class="text-muted small mb-0">Nessuna scuola in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabScu">
        <thead class="table-light"><tr><th>Scuola</th><th>Codice</th><th>Attività</th><th class="text-center">Iscrizioni</th><th class="text-center">Studenti</th><th class="text-center">Presenze</th><th>Convenzione in registro</th><th class="text-center">Valutazione</th></tr></thead>
        <tbody>
        <?php foreach ($per_scuola as $s): $ult = !empty($s['codice']) ? (convenzioni_della_scuola($conn, $s['codice'])[0] ?? null) : null; ?>
            <tr>
                <td class="fw-semibold"><?php echo !empty($s['codice']) && isset($chiavi_fsl[strtoupper($s['codice'])]) ? '<a href="' . $h($url_fsl('scuola', '&k=' . urlencode(strtoupper($s['codice'])))) . '" class="text-decoration-none">' . $h($s['nome']) . '</a>' : $h($s['nome']); ?></td><td class="font-monospace"><?php echo $h($s['codice'] ?: '—'); ?></td>
                <td><?php echo $h(implode(', ', array_keys($s['attivita']))); ?></td>
                <td class="text-center"><?php echo (int)$s['iscrizioni']; ?></td><td class="text-center"><?php echo (int)($s['studenti'] ?? 0); ?></td><td class="text-center"><?php echo (int)($s['presenze'] ?? 0); ?></td>
                <td><?php echo $ult ? $h(testo_validita_convenzione($ult)) : '<span class="text-secondary">nessuna</span>'; ?><?php if (!empty($s['conv_mancanti'])): ?> <span class="badge" style="background:#fee2e2;color:#991b1b;">da stipulare</span><?php endif; ?></td>
                <td class="text-center"><?php echo $media($s['voti'] ?? []); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'report' && $puo_fsl): $tp = $dati_fsl_rep['totali']; ?>
<section class="fsl-sez" id="report">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <form method="GET" class="d-flex align-items-center gap-2 m-0"><input type="hidden" name="p_id" value="<?php echo (int)$filtro_p; ?>"><input type="hidden" name="tab" value="report">
            <label class="small fw-bold" for="repAnno">Anno scolastico</label>
            <select class="form-select form-select-sm" id="repAnno" name="anno" onchange="this.form.submit()" style="width:auto;"><?php foreach ($anni as $a_r): ?><option value="<?php echo $a_r; ?>"<?php echo $a_r === $anno ? ' selected' : ''; ?>><?php echo $a_r . '/' . ($a_r + 1); ?></option><?php endforeach; ?></select></form>
        <span class="ms-auto"><?php echo pn_scarica($url_fsl('report', '&anno=' . $anno)); ?></span>
    </div>
    <p class="text-secondary small">Attività di Formazione Scuola Lavoro confermate dal <?php echo $d($dal_a); ?> al <?php echo $d($al_a); ?>. Le <strong>ore di formazione</strong> sono le ore dell'attività per gli studenti <strong>presenti</strong>; nell'Excel trovi anche le ore di ogni studente, per le classi con l'elenco dei nomi inserito.</p>
    <?php echo pn_percorso([
        ['numero' => $tp['scuole'], 'testo' => 'Scuole', 'nota' => $tp['attivita'] . ' attività'],
        ['numero' => $tp['classi'], 'testo' => 'Classi', 'nota' => 'iscrizioni confermate'],
        ['numero' => $tp['studenti'], 'testo' => 'Studenti iscritti', 'nota' => $tp['presenti'] . ' presenti'],
        ['numero' => number_format((float)$tp['ore_studente'], 0, ',', '.'), 'testo' => 'Ore di formazione', 'nota' => 'ore × studenti presenti'],
    ], 'Numeri dell\'anno scolastico'); ?>
    <?php if (!$dati_fsl_rep['scuole']): ?><p class="text-muted small mb-0">Nessuna attività FSL confermata in questo anno scolastico.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle small" id="tabRep">
        <thead class="table-light"><tr><th>Scuola</th><th>Comune</th><th class="text-center">Attività</th><th class="text-center">Classi</th><th class="text-center">Studenti</th><th class="text-center">Presenti</th><th class="text-end">Ore complessive</th><th class="text-end">Ore medie</th></tr></thead>
        <tbody><?php foreach ($dati_fsl_rep['scuole'] as $s_r): ?>
            <tr><td class="fw-semibold"><?php echo $h($s_r['nome']); ?></td><td><?php echo $h($s_r['comune']); ?></td><td class="text-center"><?php echo (int)$s_r['attivita']; ?></td><td class="text-center"><?php echo (int)$s_r['classi']; ?></td>
                <td class="text-center"><?php echo (int)$s_r['studenti']; ?></td><td class="text-center"><?php echo (int)$s_r['presenti']; ?></td><td class="text-end"><?php echo number_format((float)$s_r['ore_studente'], 1, ',', '.'); ?></td><td class="text-end"><?php echo number_format((float)$s_r['ore_medie'], 1, ',', '.'); ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'iter'): ?>
<?php
$dir_cv = \App\Core\App::per($conn)->get(\App\Fsl\ServizioFirmaDirettore::class)->direttore();
$nome_scuola_cv = fn(array $co) => (string)((json_decode((string)$co['dati_json'], true) ?: [])['scuola']['denominazione'] ?? '') ?: 'Scuola';
// Intestazione della scheda: scuola, dirigente, quando, quante attività, documenti precompilati
$testa_cv = function (array $co) use ($h, $d, $nome_scuola_cv): void {
    $dc = json_decode((string)$co['dati_json'], true) ?: []; $sc = $dc['scuola'] ?? [];
    $np = count(array_filter($dc['attivita'] ?? [], fn($a) => empty($a['pr']))); ?>
    <div class="iter-scuola"><strong><?php echo $h($nome_scuola_cv($co)); ?></strong>
        <span class="text-secondary small"><?php echo $h($co['scuola_codice'] ?: 'scuola non in anagrafe'); ?><?php echo !empty($sc['pec']) ? ' · PEC ' . $h($sc['pec']) : ''; ?><?php echo !empty($sc['dirigente']) ? ' · Dirigente ' . $h($sc['dirigente']) : ''; ?></span>
        <span class="text-secondary small">compilata il <?php echo $d($co['aggiornata_il']); ?> · <?php echo count($dc['attivita'] ?? []); ?> attività nell'Allegato A<?php echo $np ? " · <span class='badge bg-warning text-dark'>$np da prenotare</span>" : ''; ?></span></div>
<?php };
$btn_file = fn(array $co, string $f, string $testo, string $classe = 'btn-outline-danger') => '<a class="btn btn-sm ' . $classe . '" href="convenzione_file.php?c=' . (int)$co['id'] . ($f !== '' ? '&amp;f=' . $f : '') . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>' . $testo . '</a>';
// Comunicazione con la scuola: conversazione, promemoria «carica il PDF» e messaggio libero (una scheda a comparsa per convenzione)
$rep_msg = \App\Core\App::per($conn)->get(\App\Fsl\MessaggiConvenzioneRepository::class);
$msg_nuovi = $rep_msg->nonLetteDallUfficio();
$msg_ultimi = $rep_msg->ultimiPerCompilazione();
$n_nuovi_msg = array_sum($msg_nuovi);
$scheda_msg = function (array $co) use ($h, $rep_msg, $msg_nuovi, $msg_ultimi): void {
    $idc = (int)$co['id']; $nuovi = $msg_nuovi[$idc] ?? 0; $conv = $rep_msg->della($idc); $attende = \App\Fsl\ServizioMessaggiConvenzione::attendeIlPdf($co);
    $ult = $msg_ultimi[$idc] ?? null; ?>
    <details class="cv-msg mt-2" id="cv<?php echo $idc; ?>"<?php echo $nuovi ? ' open' : ''; ?>>
        <summary class="small fw-bold"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Scrivi alla scuola<?php echo $conv ? ' · ' . count($conv) . ($conv && count($conv) === 1 ? ' messaggio' : ' messaggi') : ''; ?>
            <?php if ($nuovi): ?><span class="badge bg-danger ms-1"><?php echo $nuovi === 1 ? 'Nuova risposta' : $nuovi . ' nuove risposte'; ?></span><?php endif; ?>
            <?php if ($ult && !$nuovi): ?><span class="text-secondary fw-normal ms-1">ultimo: <?php echo $h(date('d/m H:i', strtotime((string)$ult['creato_il']))); ?></span><?php endif; ?></summary>
        <?php if ($conv): ?><div class="cv-chat my-2">
            <?php foreach ($conv as $m): $dalla_scuola = $m['da'] === 'scuola'; ?>
                <div class="cv-bolla <?php echo $dalla_scuola ? 'scuola' : 'ufficio'; ?>">
                    <div class="small text-secondary"><?php echo $dalla_scuola ? 'La scuola' : $h($m['autore'] ?: 'Ufficio'); ?> · <?php echo $h(date('d/m/Y H:i', strtotime((string)$m['creato_il']))); ?><?php echo $m['tipo'] === 'promemoria' ? ' · promemoria' : ''; ?><?php echo (!$dalla_scuola && $m['letto_il'] !== null) ? ' · letto' : ''; ?></div>
                    <div><?php echo nl2br($h($m['testo'])); ?></div></div>
            <?php endforeach; ?></div>
            <?php if ($nuovi): ?><form method="POST" class="mb-2"><?php csrf_field(); ?><button type="submit" name="cv_letto" value="<?php echo $idc; ?>" class="btn btn-sm btn-outline-secondary py-0">Segna come letto</button></form><?php endif; ?>
        <?php else: ?><p class="small text-secondary my-2">Nessun messaggio ancora. Quello che scrivi arriva alla scuola per email, con il link alla sua pagina dove può rispondere.</p><?php endif; ?>
        <form method="POST" class="d-flex flex-column gap-1" style="max-width:560px;"><?php csrf_field(); ?>
            <textarea name="testo" class="form-control form-control-sm" rows="2" maxlength="1500" required placeholder="Scrivi un messaggio alla scuola…" aria-label="Messaggio alla scuola"></textarea>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" name="cv_messaggio" value="<?php echo $idc; ?>" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button>
                <?php if ($attende): ?><button type="submit" name="cv_ricorda" value="<?php echo $idc; ?>" class="btn btn-sm btn-outline-danger fw-bold" formnovalidate title="Email con il link per caricare il PDF firmato"><i class="fa fa-bell me-1" aria-hidden="true"></i>Ricorda di caricare il PDF</button><?php endif; ?>
            </div></form>
    </details>
<?php };
$n_iter = count($fasi_cv['caricata']) + count($fasi_cv['firmata']) + count(array_filter($fasi_cv['dirfirma'], fn($c) => $c['stato_iter'] === 'in_carico'));
?>
<style>
.iter-passi { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:10px; margin:0 0 1.25rem; padding:0; list-style:none; }
.iter-passi li { position:relative; background:#fff; border:1px solid #e2e8f0; border-top:5px solid var(--c); border-radius:12px; padding:12px 12px 10px; }
.iter-passi li::after { content:"›"; position:absolute; right:-10px; top:42%; font-size:1.6rem; color:#94a3b8; z-index:2; }
.iter-passi li:last-child::after { display:none; }
.iter-passi .n { display:inline-grid; place-items:center; width:26px; height:26px; border-radius:50%; background:var(--c); color:#fff; font-weight:800; font-size:.85rem; margin-right:6px; }
.iter-passi .chi { display:inline-block; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--c); }
.iter-passi h3 { font-size:.92rem; font-weight:700; margin:6px 0 4px; }
.iter-passi p { font-size:.8rem; color:#475569; margin:0; }
.iter-passi a.conta { display:inline-block; margin-top:6px; font-weight:800; font-size:.82rem; text-decoration:none; padding:1px 10px; border-radius:99px; background:var(--c); color:#fff; }
.iter-passi a.conta.zero { background:#e2e8f0; color:#64748b; }
.iter-tuo { --c:#b30000; } .iter-altri { --c:#64748b; } .iter-dir { --c:#7c3aed; } .iter-ok { --c:#15803d; }
.iter-fase { margin:1.5rem 0 .5rem; display:flex; align-items:center; gap:10px; flex-wrap:wrap; scroll-margin-top:80px; }
.iter-fase h2 { margin:0 !important; font-size:1.05rem !important; text-transform:none !important; letter-spacing:0 !important; }
.iter-fase .badge-chi { font-size:.72rem; font-weight:800; padding:3px 10px; border-radius:99px; color:#fff; background:var(--c); }
.iter-card { background:#fff; border:1px solid #e2e8f0; border-left:6px solid var(--c); border-radius:12px; padding:14px 16px; margin-bottom:12px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.cv-chat { display:flex; flex-direction:column; gap:6px; max-height:260px; overflow:auto; }
.cv-bolla { border-radius:10px; padding:6px 10px; max-width:88%; font-size:.88rem; }
.cv-bolla.ufficio { background:#fef2f2; align-self:flex-end; }
.cv-bolla.scuola { background:#eff6ff; align-self:flex-start; }
.iter-scuola { display:flex; flex-direction:column; gap:1px; margin-bottom:10px; }
.iter-scuola strong { font-size:1.02rem; }
.iter-azioni { list-style:none; padding:0; margin:0; counter-reset:a; }
.iter-azioni > li { counter-increment:a; display:grid; grid-template-columns:30px 1fr; gap:8px; padding:8px 0; border-top:1px dashed #e2e8f0; align-items:start; }
.iter-azioni > li::before { content:counter(a); width:26px; height:26px; border-radius:50%; background:var(--c); color:#fff; display:grid; place-items:center; font-weight:800; font-size:.8rem; margin-top:2px; }
.iter-esito { background:#f1f5f9; border-radius:8px; padding:7px 10px; font-size:.84rem; color:#334155; margin-top:8px; }
.iter-vuoto { color:#64748b; font-size:.9rem; padding:8px 0; }
@media (max-width: 991px) { .iter-passi { grid-template-columns:1fr; } .iter-passi li::after { display:none; } }
</style>
<section class="scu-sez" id="iter">
    <h2 style="margin-bottom:.5rem;">Come funziona: la convenzione firmata dalla scuola, passo per passo</h2>
    <ol class="iter-passi">
        <li class="iter-altri"><span class="n">1</span><span class="chi">La scuola</span><h3>Compila e carica il PDF firmato</h3><p>Dalla pagina «Convenzione online». Tu non devi fare nulla.</p><a class="conta<?php echo $fasi_cv['attesa'] ? '' : ' zero'; ?>" href="#fase-attesa"><?php echo count($fasi_cv['attesa']); ?> in attesa del PDF</a></li>
        <li class="iter-tuo"><span class="n">2</span><span class="chi">Tu · Ufficio</span><h3>Scarichi, protocolli, registri il protocollo in entrata</h3><p>Il sistema avvisa la scuola e manda <strong>da solo</strong> l'email al Direttore.</p><a class="conta<?php echo $fasi_cv['caricata'] ? '' : ' zero'; ?>" href="#fase-caricata"><?php echo count($fasi_cv['caricata']); ?> da fare</a></li>
        <li class="iter-dir"><span class="n">3</span><span class="chi">Il Direttore</span><h3>Firma dal link ricevuto</h3><p>Il PDF firmato torna qui da solo.</p><a class="conta<?php echo $fasi_cv['dirfirma'] ? '' : ' zero'; ?>" href="#fase-dirfirma"><?php echo count($fasi_cv['dirfirma']); ?> dal Direttore</a></li>
        <li class="iter-tuo"><span class="n">4</span><span class="chi">Tu · Ufficio</span><h3>Scarichi il firmato, protocolli, registri il protocollo</h3><p>Indichi anche da quando a quando vale.</p><a class="conta<?php echo $fasi_cv['firmata'] ? '' : ' zero'; ?>" href="#fase-firmata"><?php echo count($fasi_cv['firmata']); ?> da fare</a></li>
        <li class="iter-ok"><span class="n">5</span><span class="chi">Il sistema</span><h3>Registra la convenzione e conferma le prenotazioni</h3><p>Scuola e docenti ricevono l'email.</p><a class="conta<?php echo $fasi_cv['fatte'] ? '' : ' zero'; ?>" href="#fase-fatte"><?php echo count($fasi_cv['fatte']); ?> registrate</a></li>
    </ol>

    <!-- Il Direttore che firma: viene dalla Direzione (Gestione del portale → Uffici) -->
    <?php $ass_cv = \App\Core\App::per($conn)->get(\App\Uffici\Direzione::class)->assenza(); ?>
    <div class="border rounded p-2 mb-2 small" id="direttoreConv"<?php echo $dir_cv['email'] === '' ? ' style="border-color:#dc2626 !important;background:#fef2f2;"' : ''; ?>>
        <i class="fa fa-user-tie me-1" aria-hidden="true"></i><strong>Firma ora:</strong>
        <?php if ($dir_cv['email'] === ''): ?><span class="text-danger fw-bold">nessun Direttore indicato</span><?php else: ?><span class="fw-bold" style="color:#15803d;"><?php echo $h($dir_cv['nome']); ?></span> · <?php echo $h($dir_cv['email']); ?><?php endif; ?>
        <?php if ($ass_cv['attiva']): ?><span class="badge bg-warning text-dark ms-1">Direttore assente: firma il vice</span><?php endif; ?>
        <span class="text-secondary"> · il Direttore e il vice si scelgono dall'anagrafe in </span><?php if ($is_full_admin): ?><a class="fw-bold" href="uffici.php?u=direzione">Gestione del portale → Uffici → Direzione</a><?php else: ?><strong>Gestione del portale → Uffici → Direzione</strong><?php endif; ?>.
    </div>

    <!-- 2 · Da prendere in carico -->
    <div class="iter-fase iter-tuo" id="fase-caricata"><span class="badge-chi">Tocca a te</span><h2>② Caricate dalle scuole: da prendere in carico (<?php echo count($fasi_cv['caricata']); ?>)</h2></div>
    <?php if (!$fasi_cv['caricata']): ?><p class="iter-vuoto">Nessuna convenzione da prendere in carico.</p><?php endif; ?>
    <?php foreach ($fasi_cv['caricata'] as $co): $idc = (int)$co['id']; ?>
    <div class="iter-card iter-tuo"><?php $testa_cv($co); ?>
        <ol class="iter-azioni">
            <li><div><strong>Scarica il PDF caricato dalla scuola</strong><div class="mt-1 d-flex gap-2 flex-wrap"><?php echo $btn_file($co, '', 'PDF della convenzione firmata'); ?><?php if (!empty($co['file_allegato_scuola'])) echo $btn_file($co, 'allegato', 'Allegato A', 'btn-outline-secondary'); ?></div>
                <div class="text-secondary small mt-1">Firmato da <?php echo $h($co['firmatario'] ?: 'la scuola'); ?> il <?php echo $d($co['firmata_il']); ?>.</div></div></li>
            <li><div><strong>Protocollalo</strong> nel sistema di protocollo di Ateneo, come fai di solito.</div></li>
            <li><div><strong>Scrivi qui numero e data del protocollo in entrata</strong>
                <form method="POST" class="row g-2 mt-1 align-items-end"><?php csrf_field(); ?>
                    <div class="col-sm-4"><label class="form-label small mb-0">N. protocollo in entrata</label><input name="prot_ingresso" class="form-control form-control-sm" maxlength="80" required placeholder="es. 1234"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data</label><input type="date" name="prot_ingresso_data" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="col-sm-5"><button type="submit" name="cv_prendi" value="<?php echo $idc; ?>" class="btn btn-danger fw-bold w-100"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Registra e manda al Direttore</button></div></form></div></li>
        </ol>
        <div class="iter-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Appena registri: la scuola riceve l'avviso e <strong><?php echo $h($dir_cv['nome'] ?: 'il Direttore'); ?></strong> riceve l'email con il link per firmare. Non devi ricaricare il file.</div>
        <details class="mt-2"><summary class="small fw-bold text-danger" style="cursor:pointer;">Qualcosa non va? Rifiuta con un motivo</summary>
            <form method="POST" class="mt-1" style="max-width:420px;"><?php csrf_field(); ?>
                <textarea name="motivo" class="form-control form-control-sm mb-1" rows="2" maxlength="500" required placeholder="Es. firma mancante, dati del Dirigente errati…" aria-label="Motivo del rifiuto"></textarea>
                <button type="submit" name="cv_rifiuta" value="<?php echo $idc; ?>" class="btn btn-sm btn-outline-danger">Rifiuta e avvisa la scuola</button></form></details>
        <?php $scheda_msg($co); ?>
    </div>
    <?php endforeach; ?>

    <!-- 4 · Firmate dal Direttore -->
    <div class="iter-fase iter-tuo" id="fase-firmata"><span class="badge-chi">Tocca a te</span><h2>④ Firmate dal Direttore: da protocollare e registrare (<?php echo count($fasi_cv['firmata']); ?>)</h2></div>
    <?php if (!$fasi_cv['firmata']): ?><p class="iter-vuoto">Nessuna convenzione in attesa del protocollo definitivo.</p><?php endif; ?>
    <?php foreach ($fasi_cv['firmata'] as $co): $idc = (int)$co['id']; ?>
    <div class="iter-card iter-tuo"><?php $testa_cv($co); ?>
        <ol class="iter-azioni">
            <li><div><strong>Scarica il PDF firmato dal Direttore</strong><div class="mt-1"><?php echo $btn_file($co, 'dir', 'PDF firmato dal Direttore', 'btn-outline-success'); ?></div>
                <div class="text-secondary small mt-1">Firmato da <?php echo $h($co['firmatario_direttore']); ?><?php echo !empty($co['firmata_direttore_il']) ? ' il ' . $d($co['firmata_direttore_il']) : ''; ?>. Protocollo in entrata: <?php echo $h($co['protocollo_ingresso'] ?: '—'); ?>.</div></div></li>
            <li><div><strong>Protocollalo</strong> in via definitiva nel sistema di Ateneo.</div></li>
            <li><div><strong>Scrivi qui il protocollo e per quanto vale la convenzione</strong>
                <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php csrf_field(); ?>
                    <div class="col-sm-3"><label class="form-label small mb-0">N. protocollo</label><input name="protocollo" class="form-control form-control-sm" maxlength="80" required placeholder="es. 1234"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Data protocollo</label><input type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida dal</label><input type="date" name="data_stipula" class="form-control form-control-sm" required value="<?php echo $h($conv_def_dal); ?>"></div>
                    <div class="col-sm-3"><label class="form-label small mb-0">Valida fino al</label><input type="date" name="scadenza" class="form-control form-control-sm" required value="<?php echo $h($conv_def_al); ?>"></div>
                    <div class="col-12"><label class="form-label small mb-0" for="fd<?php echo $idc; ?>">PDF con la segnatura di protocollo (facoltativo)</label><input type="file" name="file_definitivo" id="fd<?php echo $idc; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf">
                        <div class="form-text">Se lo carichi, nel registro e per la scuola c'è questo file al posto di quello firmato dal Direttore (che resta scaricabile qui).</div></div>
                    <div class="col-12"><button type="submit" name="cv_completa" value="<?php echo $idc; ?>" class="btn btn-success fw-bold" data-confirm="Registrare la convenzione, confermare le prenotazioni in attesa della scuola e avvisarla?"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Registra la convenzione e conferma le prenotazioni</button></div></form></div></li>
        </ol>
        <div class="iter-esito"><i class="fa fa-bolt me-1" aria-hidden="true"></i>Alla registrazione la convenzione entra <strong>da sola</strong> nel registro, le prenotazioni in attesa della scuola vengono confermate e le persone avvisate per email.</div>
        <?php $scheda_msg($co); ?>
    </div>
    <?php endforeach; ?>

    <!-- 3 · Dal Direttore -->
    <div class="iter-fase iter-dir" id="fase-dirfirma"><span class="badge-chi">Attendi</span><h2>③ Dal Direttore per la firma (<?php echo count($fasi_cv['dirfirma']); ?>)</h2></div>
    <?php if (!$fasi_cv['dirfirma']): ?><p class="iter-vuoto">Nessuna convenzione dal Direttore.</p><?php endif; ?>
    <?php foreach ($fasi_cv['dirfirma'] as $co): $idc = (int)$co['id']; $inviata = $co['stato_iter'] === 'da_firmare'; ?>
    <div class="iter-card iter-dir"><?php $testa_cv($co); ?>
        <p class="mb-2 small"><?php if ($inviata): ?><i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>Il link per firmare è stato mandato a <strong><?php echo $h($dir_cv['nome']); ?></strong> il <?php echo date('d/m/Y H:i', strtotime($co['da_firmare_il'])); ?>. Quando firma, il PDF torna qui e la convenzione passa al punto ④.
            <?php else: ?><i class="fa fa-triangle-exclamation me-1 text-danger" aria-hidden="true"></i>Protocollo in entrata registrato (<?php echo $h($co['protocollo_ingresso']); ?>), ma <strong>l'email al Direttore non è partita</strong>: controlla che sia indicato qui sopra e premi il pulsante.<?php endif; ?></p>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <form method="POST" class="m-0"><?php csrf_field(); ?><button type="submit" name="cv_invia_direttore" value="<?php echo $idc; ?>" class="btn btn-sm <?php echo $inviata ? 'btn-outline-primary' : 'btn-primary fw-bold'; ?>"><i class="fa fa-file-signature me-1" aria-hidden="true"></i><?php echo $inviata ? 'Rimanda il link' : 'Invia al Direttore per la firma'; ?></button></form>
            <?php echo $btn_file($co, '', 'PDF della scuola', 'btn-outline-secondary'); ?></div>
        <details class="mt-2"><summary class="small fw-bold" style="cursor:pointer;color:#15803d;">Il Direttore ha firmato fuori dal portale? Carica tu il PDF firmato</summary>
            <form method="POST" enctype="multipart/form-data" class="row g-2 mt-1"><?php csrf_field(); ?>
                <div class="col-12"><label class="form-label small fw-bold mb-0" for="fe<?php echo $idc; ?>">PDF firmato dal Direttore in PAdES</label><input type="file" name="file_definitivo" id="fe<?php echo $idc; ?>" class="form-control form-control-sm" accept=".pdf,application/pdf" required></div>
                <div class="col-sm-3"><label class="form-label small mb-0">N. protocollo</label><input name="protocollo" class="form-control form-control-sm" maxlength="80" required></div>
                <div class="col-sm-3"><label class="form-label small mb-0">Data protocollo</label><input type="date" name="protocollo_data" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="col-sm-3"><label class="form-label small mb-0">Valida dal</label><input type="date" name="data_stipula" class="form-control form-control-sm" required value="<?php echo $h($conv_def_dal); ?>"></div>
                <div class="col-sm-3"><label class="form-label small mb-0">Valida fino al</label><input type="date" name="scadenza" class="form-control form-control-sm" required value="<?php echo $h($conv_def_al); ?>"></div>
                <div class="col-12"><button type="submit" name="cv_completa" value="<?php echo $idc; ?>" class="btn btn-sm btn-success fw-bold" data-confirm="Registrare la convenzione, confermare le prenotazioni in attesa della scuola e avvisarla?">Registra e conferma le prenotazioni</button></div></form></details>
        <?php $scheda_msg($co); ?>
    </div>
    <?php endforeach; ?>

    <!-- 1 · In attesa della scuola, rifiutate -->
    <div class="iter-fase iter-altri" id="fase-attesa"><span class="badge-chi">Attendi</span><h2>① Compilate online, in attesa del PDF firmato dalla scuola (<?php echo count($fasi_cv['attesa']) + count($fasi_cv['rifiutata']); ?>)</h2></div>
    <?php if ($n_nuovi_msg): ?><div class="alert alert-danger small py-2"><i class="fa fa-envelope me-1" aria-hidden="true"></i><strong><?php echo $n_nuovi_msg === 1 ? 'Una scuola ti ha risposto' : $n_nuovi_msg . ' messaggi dalle scuole'; ?></strong>: apri «Scrivi alla scuola» sulla scheda con il bollino rosso.</div><?php endif; ?>
    <?php if ($fasi_cv['attesa'] || $fasi_cv['rifiutata']): ?><form method="POST" class="mb-2"><?php csrf_field(); ?>
        <button type="submit" name="cv_ricorda_tutte" value="1" class="btn btn-sm btn-outline-danger fw-bold" data-confirm="Mandare il promemoria «carica il PDF firmato» a tutte le scuole che lo stanno aspettando (saltando chi è stato avvisato nelle ultime 24 ore)?"><i class="fa fa-bell me-1" aria-hidden="true"></i>Ricorda il PDF a tutte le scuole in attesa</button></form><?php endif; ?>
    <?php if (!$fasi_cv['attesa'] && !$fasi_cv['rifiutata']): ?><p class="iter-vuoto">Nessuna convenzione in attesa delle scuole.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead><tr><th>Scuola</th><th>Compilata il</th><th>Stato</th><th>Documenti per la scuola</th></tr></thead><tbody>
        <?php foreach (array_merge($fasi_cv['rifiutata'], $fasi_cv['attesa']) as $co): $rif = $co['stato_iter'] === 'rifiutata'; $dcx = json_decode((string)$co['dati_json'], true) ?: []; ?>
            <tr><td><strong><?php echo $h($nome_scuola_cv($co)); ?></strong><div class="text-secondary"><?php echo $h($co['scuola_codice'] ?: 'non in anagrafe'); ?></div></td><td><?php echo $d($co['aggiornata_il']); ?></td>
                <td><?php echo $rif ? '<span class="badge bg-secondary">Rifiutata da te: attende un nuovo file</span><div class="text-secondary" style="max-width:260px;white-space:normal;">' . $h($co['nota_ufficio']) . '</div>' : '<span class="badge bg-light text-dark border">La scuola non ha ancora caricato il PDF</span>'; ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="../convenzione_online.php?t=<?php echo $h($co['token']); ?>&amp;scarica=convenzione"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione Word</a>
                    <?php $reg = convenzione_valida($conn, $co['scuola_codice']); if (!$reg && $co['scuola_codice']): ?><a class="btn btn-sm btn-outline-success py-0" href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&amp;tab=convenzioni&amp;conv_nuova=<?php echo $h($co['scuola_codice']); ?>#convForm" title="Se hai già la convenzione firmata fuori dal portale">Registra a mano</a><?php endif; ?></td></tr>
            <tr><td colspan="4" class="bg-light border-top-0 pt-0"><?php $scheda_msg($co); ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>

    <!-- 5 · Registrate -->
    <div class="iter-fase iter-ok" id="fase-fatte"><span class="badge-chi">Fatto</span><h2>⑤ Registrate: convenzione attiva, prenotazioni confermate (<?php echo count($fasi_cv['fatte']); ?>)</h2></div>
    <?php if (!$fasi_cv['fatte']): ?><p class="iter-vuoto">Ancora nessuna.</p>
    <?php else: ?><div class="table-responsive"><table class="table table-sm small align-middle bg-white border">
        <thead><tr><th>Scuola</th><th>Protocollo in entrata</th><th>Protocollo definitivo</th><th>Documenti</th></tr></thead><tbody>
        <?php foreach ($fasi_cv['fatte'] as $co): ?>
            <tr><td><strong><?php echo $h($nome_scuola_cv($co)); ?></strong></td>
                <td><?php echo $h($co['protocollo_ingresso'] ?: '—'); ?><?php echo !empty($co['protocollo_ingresso_data']) ? ' del ' . $d($co['protocollo_ingresso_data']) : ''; ?></td>
                <td><?php echo $h($co['protocollo'] ?: '—'); ?><?php echo !empty($co['protocollo_data']) ? ' del ' . $d($co['protocollo_data']) : ''; ?></td>
                <td class="text-nowrap"><?php if (!empty($co['file_firmato'])) echo $btn_file($co, '', 'Scuola', 'btn-outline-secondary py-0'); ?> <?php if (!empty($co['file_definitivo'])) echo $btn_file($co, 'dir', 'Firmata', 'btn-outline-success py-0'); ?>
                    <a class="btn btn-sm btn-outline-secondary py-0" href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&amp;tab=convenzioni#registro">Registro</a></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <p class="text-secondary small mt-3 mb-0">Le scuole che non usano la convenzione online (o che non hanno ancora nulla) si trovano in <a href="<?php echo $h($url_fsl('stipulare')); ?>">Da stipulare</a>; il registro di tutte le convenzioni valide è in <a href="<?php echo $h($url_fsl('convenzioni')); ?>#registro">Convenzioni in vigore</a>.</p>
</section>

<?php elseif ($tab === 'convenzioni'): ?>
<section class="scu-sez" id="convenzioni">
    <p class="small text-secondary">La convenzione deve coprire <strong>tutto il periodo</strong> dell'attività: se lo copre la scuola non deve inviare nulla. Registrando una nuova convenzione le prenotazioni in attesa si confermano da sole e la scuola riceve l'email. Ti avvisiamo 60 giorni prima della scadenza.</p>

    <!-- REGISTRA / MODIFICA -->
    <?php $form_aperto = $conv_mod || $conv_rinnova || !empty($conv_nuova_s) || !empty($_GET['nuova']); if ($form_aperto): ?>
    <details class="mb-3" id="convFormBox" open>
    <h3 class="h6 fw-bold mt-3" id="convForm"><?php echo $conv_mod ? 'Modifica la convenzione' : ($conv_rinnova ? 'Rinnova la convenzione' : 'Registra una convenzione'); ?></h3>
    <?php if ($conv_rinnova): ?><p class="small text-secondary mb-2">Nuova convenzione per la stessa scuola, con i docenti di riferimento della precedente (<?php echo $h(testo_validita_convenzione($conv_rinnova)); ?><?php echo $conv_rinnova['protocollo'] !== '' ? (preg_match('/^prot/i', $conv_rinnova['protocollo']) ? ', ' : ', protocollo ') . $h($conv_rinnova['protocollo']) : ''; ?>): controlla le date e carica i nuovi file firmati. La convenzione scaduta resta in archivio.</p><?php endif; ?>
    <?php $cm = $conv_mod ?: []; $cm_doc = json_decode((string)($cm['docenti_json'] ?? ($conv_rinnova['docenti_json'] ?? '')), true) ?: [['nome' => '', 'email' => '']];
          $cm_s = !empty($cm['scuola_codice']) ? scuola_per_codice($conn, $cm['scuola_codice']) : ($conv_nuova_s ?? null); ?>
    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end mb-3 border rounded p-2">
        <?php csrf_field(); ?>
        <input type="hidden" name="conv_id" value="<?php echo (int)($cm['id'] ?? 0); ?>">
        <div class="col-lg-6"><label class="form-label small fw-bold mb-1">Scuola *</label><?php echo html_campo_scuola('conv', $cm_s ? etichetta_scuola($cm_s) : '', $cm_s['codice'] ?? '', 'required'); ?></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvStip">Valida dal *</label><input type="date" name="data_stipula" id="cvStip" class="form-control form-control-sm" required value="<?php echo $h($cm['data_stipula'] ?? $conv_def_dal); ?>" onchange="var s=document.getElementById('cvScad'); if (this.value && !s.dataset.toccata) { var d=new Date(this.value); d.setFullYear(d.getFullYear()+<?php echo CONV_DURATA_ANNI; ?>); d.setDate(d.getDate()-1); s.value=d.toISOString().slice(0,10); }"></div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvScad">Valida fino al *</label><input type="date" name="scadenza" id="cvScad" class="form-control form-control-sm" required value="<?php echo $h($cm['scadenza'] ?? $conv_def_al); ?>" oninput="this.dataset.toccata='1'"></div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileC">Convenzione firmata (PDF PAdES)<?php echo empty($cm['file_convenzione']) ? '' : ' – carica solo per sostituirla'; ?></label>
            <input type="file" name="file_convenzione" id="cvFileC" class="form-control form-control-sm" accept=".pdf,application/pdf">
            <?php if (!empty($cm['file_convenzione'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=conv" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold mb-1" for="cvFileA">Allegato A (PDF, anche senza firma PAdES)<?php echo empty($cm['file_allegato']) ? '' : ' – carica solo per sostituirlo'; ?></label>
            <input type="file" name="file_allegato" id="cvFileA" class="form-control form-control-sm" accept=".pdf,application/pdf">
            <?php if (!empty($cm['file_allegato'])): ?><div class="form-text"><a href="convenzione_file.php?id=<?php echo (int)$cm['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>File attuale</a></div><?php endif; ?>
        </div>
        <div class="col-12">
            <label class="form-label small fw-bold mb-1">Docenti di riferimento (Allegato A)</label>
            <div id="cvDocenti">
                <?php foreach ($cm_doc as $d): ?>
                    <div class="d-flex gap-2 mb-1 cv-doc">
                        <input type="text" name="doc_nome[]" class="form-control form-control-sm" placeholder="Nome e cognome" value="<?php echo $h($d['nome'] ?? ''); ?>" maxlength="150" aria-label="Nome e cognome del docente">
                        <input type="email" name="doc_email[]" class="form-control form-control-sm" placeholder="Email" value="<?php echo $h($d['email'] ?? ''); ?>" aria-label="Email del docente">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="var r=this.closest('.cv-doc'); if (document.querySelectorAll('#cvDocenti .cv-doc').length > 1) r.remove(); else r.querySelectorAll('input').forEach(function(i){i.value='';});" aria-label="Togli il docente">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold py-0" onclick="var c=document.querySelector('#cvDocenti .cv-doc').cloneNode(true); c.querySelectorAll('input').forEach(function(i){i.value='';}); document.getElementById('cvDocenti').appendChild(c);"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi docente</button>
        </div>
        <div class="col-6 col-lg-3"><label class="form-label small fw-bold mb-1" for="cvProt">Protocollo</label><input type="text" name="protocollo" id="cvProt" class="form-control form-control-sm" maxlength="100" value="<?php echo $h($cm['protocollo'] ?? ''); ?>" placeholder="es. n. 1234 del 01/10/2026"></div>
        <div class="col-6 col-lg-6"><label class="form-label small fw-bold mb-1" for="cvNote">Note</label><input type="text" name="note" id="cvNote" class="form-control form-control-sm" maxlength="500" value="<?php echo $h($cm['note'] ?? ''); ?>"></div>
        <div class="col-12 col-lg-3 d-flex gap-2">
            <button type="submit" name="conv_salva" value="1" class="btn btn-sm btn-danger fw-bold flex-grow-1"><i class="fa fa-save me-1" aria-hidden="true"></i><?php echo $conv_mod ? 'Salva le modifiche' : 'Registra'; ?></button>
            <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni<?php echo $vista_cv === 'archivio' ? '&vista=archivio' : ''; ?>#registro" class="btn btn-sm btn-outline-secondary fw-bold">Annulla</a>
        </div>
    </form>
</details>
<?php endif; ?>

    <!-- REGISTRO PER SCUOLA: in vigore / scadute -->
    <div class="pn-linea" id="registro">
        <?php echo $seg(['vigore' => ['href' => $url_fsl('convenzioni') . '#registro', 'testo' => 'In vigore', 'numero' => count($conv_vigore)], 'archivio' => ['href' => $url_fsl('convenzioni', '&vista=archivio') . '#registro', 'testo' => 'Scadute', 'numero' => count($conv_archivio)]], $vista_cv); ?>
        <?php if ($conv_per_scuola): ?><input type="search" id="cercaConv" class="form-control" style="max-width:300px;" placeholder="Cerca scuola, docente o protocollo" aria-label="Cerca nel registro delle convenzioni"><span class="pn-spazio"><?php echo pn_scarica($url_fsl('convenzioni', $vista_cv === 'archivio' ? '&vista=archivio' : '')); ?></span><?php endif; ?>
    </div>
    <?php if ($vista_cv === 'archivio'): ?><p class="small text-secondary mb-2">Convenzioni scadute: ci finiscono da sole il giorno dopo la scadenza e restano consultabili con i loro file. <strong>Rinnova</strong> prepara una nuova convenzione per la stessa scuola.</p><?php endif; ?>
    <?php if ($conv_per_scuola): ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle small" id="tabConv">
                <thead class="table-light"><tr><th>Scuola</th><th>Validità</th><th>Docenti di riferimento</th><th>File</th><th>Protocollo e note</th><th></th></tr></thead>
                <?php foreach ($conv_per_scuola as $cod => $lista): $s_c = scuola_per_codice($conn, $cod); ?>
                    <tbody class="conv-scuola border-top">
                    <?php foreach ($lista as $i => $c):
                        [$bg_c, $fg_c, $lbl_c] = $stato_cv($c); $al_c = $c['scadenza'];
                        if ($lbl_c === 'In scadenza') $lbl_c = 'Scade tra ' . max(0, (int)ceil((strtotime($al_c) - strtotime($oggi_cv)) / 86400)) . ' giorni';
                        $docs = json_decode((string)($c['docenti_json'] ?? ''), true) ?: []; ?>
                        <tr>
                            <?php if ($i === 0): ?><td rowspan="<?php echo count($lista); ?>" class="align-top"><div class="fw-semibold"><?php echo $h($s_c ? etichetta_scuola($s_c) : $cod); ?></div><div class="text-secondary font-monospace"><?php echo $h($cod); ?></div><div class="text-secondary"><?php echo (int)$c['n_iscr']; ?> iscrizioni</div></td><?php endif; ?>
                            <td class="text-nowrap"><?php echo $h(testo_validita_convenzione($c)); ?> <span class="badge" style="background:<?php echo $bg_c; ?>;color:<?php echo $fg_c; ?>;"><?php echo $lbl_c; ?></span></td>
                            <td><?php foreach ($docs as $d): ?><div><?php echo $h($d['nome']); ?><?php if (!empty($d['email'])): ?> · <a href="mailto:<?php echo $h($d['email']); ?>"><?php echo $h($d['email']); ?></a><?php endif; ?></div><?php endforeach; ?><?php if (!$docs): ?><span class="text-secondary">—</span><?php endif; ?></td>
                            <td class="text-nowrap">
                                <?php if (!empty($c['file_convenzione'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=conv" target="_blank" class="me-2"><i class="fa fa-file-pdf me-1"></i>Convenzione</a><?php else: ?><span class="badge bg-light text-secondary border me-1">convenzione mancante</span><?php endif; ?>
                                <?php if (!empty($c['file_allegato'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1"></i>Allegato A</a><?php else: ?><span class="badge bg-light text-secondary border">Allegato A mancante</span><?php endif; ?>
                            </td>
                            <td><?php echo $h($c['protocollo']); ?><?php if ($c['note'] !== ''): ?><div class="text-secondary"><?php echo $h($c['note']); ?></div><?php endif; ?></td>
                            <td class="text-nowrap">
                                <?php if ($vista_cv === 'archivio' && $i === 0 && empty($conv_vigore[$cod])): ?>
                                    <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&vista=archivio&conv_rinnova=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0" title="Rinnova: nuova convenzione per questa scuola"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Rinnova</a>
                                <?php elseif ($vista_cv === 'archivio' && $i === 0): ?>
                                    <span class="badge bg-light text-success border" title="La scuola ha già una convenzione in vigore">rinnovata</span>
                                <?php elseif ($i === 0 && str_starts_with($lbl_c, 'Scade')): ?>
                                    <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&conv_rinnova=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0" title="Rinnova: nuova convenzione per questa scuola"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Rinnova</a>
                                <?php endif; ?>
                                <a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni<?php echo $vista_cv === 'archivio' ? '&vista=archivio' : ''; ?>&conv_mod=<?php echo (int)$c['id']; ?>#convForm" class="btn btn-sm btn-outline-secondary fw-bold py-0" aria-label="Modifica la convenzione"><i class="fa fa-pen me-1" aria-hidden="true"></i>Modifica</a>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <button type="submit" name="conv_elimina" value="<?php echo (int)$c['id']; ?>" class="btn btn-sm btn-outline-danger py-0" data-confirm="Eliminare questa convenzione dal registro, con i suoi file?" title="Elimina" aria-label="Elimina la convenzione"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                <?php endforeach; ?>
            </table>
        </div>
        <script>
        document.getElementById('cercaConv').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#tabConv tbody.conv-scuola').forEach(function (tb) { tb.style.display = !q || tb.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
        });
        </script>
    <?php else: ?>
        <p class="text-muted small mb-0"><?php echo $vista_cv === 'archivio' ? 'Nessuna convenzione scaduta.' : 'Nessuna convenzione in vigore.'; ?></p>
    <?php endif; ?>
</section>

<?php elseif ($tab === 'stipulare'): ?>
<?php
$mostra_st = ($_GET['mostra'] ?? '') === 'tutte' ? 'tutte' : 'da_stipulare';
$elenco_st = $mostra_st === 'tutte' ? $scuole_fsl : array_values(array_filter($scuole_fsl, fn($g) => $g['da_stipulare']));
$n_pren_st = array_sum(array_map(fn($g) => $g['n_da_stipulare'], $scuole_fsl));
$stati_pren_st = ['confermata' => ['Confermata', '#166534', '#f0fdf4'], 'da_approvare' => ['In attesa', '#9a3412', '#fff7ed'], 'in_attesa' => ["Lista d'attesa", '#854d0e', '#fefce8'], 'richiesta_conferma' => ['Posto offerto', '#1e40af', '#eff6ff']];
$stati_conv_st = ['da_stipulare' => ['Da stipulare', '#991b1b', '#fee2e2'], 'coperta' => ['Convenzione valida', '#166534', '#f0fdf4'], 'ricevuta' => ['Convenzione ricevuta', '#166534', '#f0fdf4'], 'dichiarata' => ['Dichiarata dalla scuola', '#475569', '#f1f5f9']];
?>
<style>
.st-card { background:#fff; border:1px solid #e2e8f0; border-left:6px solid #16a34a; border-radius:12px; margin-bottom:1rem; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.st-card.da-stipulare { border-left-color:#dc2626; }
.st-card .st-testa { padding:1rem 1.25rem .5rem; }
.st-card .st-nome { font-size:1.15rem; font-weight:800; color:#0f172a; line-height:1.25; }
.st-card .st-corpo { padding:0 1.25rem; }
.st-card .st-piede { padding:.85rem 1.25rem; background:#f8fafc; border-top:1px solid #e2e8f0; border-radius:0 0 12px 12px; }
.st-chip { display:inline-block; font-size:.72rem; font-weight:700; padding:.15rem .55rem; border-radius:999px; white-space:nowrap; }
</style>
<section class="scu-sez">
    <p class="small text-secondary mb-3">Per ogni scuola scarichi già <strong>precompilati</strong> l'Allegato A e la Convenzione, con i dati dell'anagrafe e delle prenotazioni. Il logo della scuola non c'è: lo aggiungi tu al documento. I dati del Dirigente non ancora indicati restano da completare a mano.</p>

    <?php if (!$elenco_st): ?>
        <div class="alert alert-success mb-0"><i class="fa fa-circle-check me-1" aria-hidden="true"></i><?php echo $mostra_st === 'tutte' ? 'Nessuna scuola ha prenotazioni in corso alle attività FSL.' : 'Nessuna convenzione da stipulare: tutte le scuole con prenotazioni in corso sono a posto.'; ?></div>
    <?php endif; ?>

    <?php $srv_st = \App\Core\App::per($conn)->get(\App\Fsl\ServizioConvenzioniScuole::class); $dest_per_scuola = []; foreach ($elenco_st as $g_d) if ($g_d['da_stipulare']) $dest_per_scuola[$g_d['chiave']] = $srv_st->destinatari($g_d['chiave']); ?>
    <?php foreach ($elenco_st as $g): $k_g = urlencode($g['chiave']); $url_doc = fn($tipo) => 'allegato_a.php?k=' . $k_g . '&doc=' . $tipo; ?>
    <article class="st-card <?php echo $g['da_stipulare'] ? 'da-stipulare' : ''; ?>">
        <div class="st-testa d-flex flex-wrap align-items-start gap-2">
            <div class="flex-grow-1">
                <div class="st-nome"><a href="<?php echo $h($url_fsl('scuola', '&k=' . urlencode($g['chiave']))); ?>" class="text-decoration-none text-reset"><?php echo $h($g['nome']); ?></a></div>
                <div class="small text-secondary"><?php echo $h($g['comune']); ?><?php echo $g['codice'] !== '' ? ($g['comune'] !== '' ? ' · ' : '') . '<span class="font-monospace">' . $h($g['codice']) . '</span>' : ' · scuola scritta a mano nel modulo: abbinala all\'anagrafe per verificarne la convenzione'; ?> · <?php echo count($g['prenotazioni']); ?> <?php echo count($g['prenotazioni']) === 1 ? 'prenotazione' : 'prenotazioni'; ?><?php echo $g['n_studenti'] ? ' · ' . (int)$g['n_studenti'] . ' studenti' : ''; ?></div>
            </div>
            <?php if ($g['da_stipulare']): ?>
                <span class="st-chip" style="background:#fee2e2;color:#991b1b;font-size:.85rem;"><i class="fa fa-file-circle-exclamation me-1" aria-hidden="true"></i>Convenzione da stipulare (<?php echo (int)$g['n_da_stipulare']; ?>)</span>
            <?php else: ?>
                <span class="st-chip" style="background:#dcfce7;color:#166534;font-size:.85rem;"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Convenzione a posto<?php echo $g['validita'] ? ' fino al ' . $d($g['validita']) : ''; ?></span>
            <?php endif; ?>
        </div>
        <div class="st-corpo">
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-2">
                    <thead class="table-light"><tr><th>Attività</th><th>Periodo</th><th class="text-center">Studenti</th><th>Docente</th><th>Prenotazione</th><th>Convenzione</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($g['prenotazioni'] as $p_g): [$lp, $cp, $bp] = $stati_pren_st[$p_g['stato']] ?? [$p_g['stato'], '#475569', '#f1f5f9']; [$lc, $cc_, $bc] = $stati_conv_st[$p_g['conv']]; ?>
                        <tr>
                            <td><strong><?php echo $h($p_g['titolo']); ?></strong><div class="text-secondary"><?php echo $h($p_g['area']); ?><?php echo $p_g['turno'] !== '' ? ' · ' . $h($p_g['turno']) : ''; ?> · <span class="font-monospace"><?php echo $h($p_g['codice']); ?></span></div></td>
                            <td class="text-nowrap"><?php echo $h($p_g['periodo']); ?></td>
                            <td class="text-center"><?php echo $p_g['studenti'] ?: '—'; ?></td>
                            <td><?php echo $h($p_g['docente']); ?><div class="text-secondary"><?php echo $h($p_g['email']); ?></div></td>
                            <td><span class="st-chip" style="background:<?php echo $bp; ?>;color:<?php echo $cp; ?>;"><?php echo $h($lp); ?></span></td>
                            <td><span class="st-chip" style="background:<?php echo $bc; ?>;color:<?php echo $cc_; ?>;"><?php echo $h($lc); ?></span></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$p_g['pagina_id']; ?>&f_cerca=<?php echo urlencode($p_g['codice']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="st-piede d-flex flex-wrap align-items-center gap-2">
            <?php if ($g['codice'] !== '' && preg_match('/^[A-Z0-9]{10}$/', $g['codice'])): ?>
                <a href="fsl.php?p_id=<?php echo (int)$filtro_p; ?>&tab=convenzioni&conv_nuova=<?php echo urlencode($g['codice']); ?>&dal=<?php echo $h($g['dal']); ?>&al=<?php echo $h($g['al']); ?>#convForm" class="btn btn-success fw-bold"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Registra la convenzione ricevuta</a>
            <?php endif; ?>
            <?php if ($g['da_stipulare']): ?>
                <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="mostra" value="<?php echo $h($mostra_st); ?>"><button type="submit" name="st_chiedi" value="<?php echo $h($g['chiave']); ?>" class="btn btn-outline-danger fw-bold" data-confirm="Mandare ai docenti di questa scuola l'email con i modelli della convenzione e la PEC?"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Chiedi la convenzione</button></form>
                <button type="button" class="btn btn-outline-primary fw-bold" data-bs-toggle="collapse" data-bs-target="#st-msg-<?php echo $h(md5($g['chiave'])); ?>" aria-expanded="false"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Scrivi alla scuola</button>
            <?php endif; ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary fw-bold dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="fa fa-file-lines me-1" aria-hidden="true"></i>Documenti già compilati</button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="<?php echo $h($url_doc('allegato_pdf')); ?>"><i class="fa fa-file-pdf me-2" aria-hidden="true"></i>Allegato A (PDF)</a></li>
                    <li><a class="dropdown-item" href="<?php echo $h($url_doc('allegato')); ?>"><i class="fa fa-file-word me-2" aria-hidden="true"></i>Allegato A (Word)</a></li>
                    <li><a class="dropdown-item" href="<?php echo $h($url_doc('convenzione')); ?>"><i class="fa fa-file-word me-2" aria-hidden="true"></i>Convenzione (Word)</a></li>
                </ul>
            </div>
            <a href="<?php echo $h($url_fsl('scuola', '&k=' . urlencode($g['chiave']))); ?>" class="btn btn-outline-secondary fw-bold ms-auto">Apri la scheda della scuola</a>
        </div>
        <?php if ($g['da_stipulare']): ?>
        <div class="collapse px-3 pb-3 pt-2 border-top" id="st-msg-<?php echo $h(md5($g['chiave'])); ?>">
            <form method="POST" class="m-0"><?php csrf_field(); ?><input type="hidden" name="mostra" value="<?php echo $h($mostra_st); ?>">
                <label class="form-label small fw-bold mb-1" for="st-t-<?php echo $h(md5($g['chiave'])); ?>">Messaggio ai docenti: <?php echo $h(implode(', ', array_map(fn($x) => $x['docente'], $dest_per_scuola[$g['chiave']] ?? [])) ?: 'nessun indirizzo email disponibile'); ?></label>
                <textarea class="form-control form-control-sm mb-2" id="st-t-<?php echo $h(md5($g['chiave'])); ?>" name="testo" rows="3" maxlength="1500" required placeholder="Scrivi qui il messaggio: arriva per email e resta nella chat della prenotazione."></textarea>
                <button type="submit" name="st_scrivi" value="<?php echo $h($g['chiave']); ?>" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-paper-plane me-1" aria-hidden="true"></i>Manda il messaggio</button>
            </form>
        </div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>

<?php elseif ($tab === 'scuola'):
// Scheda di una scuola con prenotazioni in corso: percorso, prossimo passo, prenotazioni, documenti e convenzioni registrate
$g_s = null;
foreach ($scuole_fsl as $g_x) if ($g_x['chiave'] === (string)($_GET['k'] ?? '')) { $g_s = $g_x; break; }
$indietro_s = '<a href="' . $h($url_fsl('stipulare', '&mostra=tutte')) . '" class="text-decoration-none small"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>Tutte le scuole con prenotazioni</a>';
?>
<section class="scu-sez">
<?php if (!$g_s): ?>
    <p class="mb-2"><?php echo $indietro_s; ?></p>
    <?php echo pn_vuoto('Questa scuola non ha prenotazioni in corso alle attività FSL (oppure il collegamento non è più valido).', 'fa-school'); ?>
<?php else:
    $per_id = [];
    foreach ($righe as $x) $per_id[(int)$x['id']] = $x;
    $rg = [];
    foreach ($g_s['prenotazioni'] as $p_s) if (isset($per_id[$p_s['id']])) $rg[] = $per_id[$p_s['id']];
    $n_conf_s = count(array_filter($g_s['prenotazioni'], fn($p_s) => $p_s['stato'] === 'confermata'));
    $conclusa_s = $rg && count(array_filter($rg, fn($x) => \App\Fsl\PercorsoFsl::conclusa($x, $oggi_f))) === count($rg);
    $con_att_s = array_values(array_filter($rg, fn($x) => \App\Fsl\PercorsoFsl::haAttestati($x)));
    $tutte_s = fn(array $lista, string $campo) => count(array_filter($lista, fn($x) => (int)($x[$campo] ?? 0) > 0)) === count($lista);
    $passi_s = \App\Fsl\PercorsoFsl::passi($n_conf_s > 0, !$g_s['da_stipulare'], $conclusa_s,
        $con_att_s ? $tutte_s($con_att_s, 'n_elenco') : null, $con_att_s ? $tutte_s($con_att_s, 'attestato_inviato') : null, $rg ? $tutte_s($rg, 'val_id') : null);
    $qui_s = '';
    foreach ($passi_s as $ps) if ($ps['stato'] === 'qui') { $qui_s = $ps['chiave']; break; }
    $k_g = urlencode($g_s['chiave']); $url_doc = fn($tipo) => 'allegato_a.php?k=' . $k_g . '&doc=' . $tipo;
    $cod_ok = $g_s['codice'] !== '' && preg_match('/^[A-Z0-9]{10}$/', $g_s['codice']);
    $url_reg = $cod_ok ? $url_fsl('convenzioni', '&conv_nuova=' . urlencode($g_s['codice']) . '&dal=' . $g_s['dal'] . '&al=' . $g_s['al']) . '#convForm' : '';
    $pren_att = $g_s['prenotazioni'][0] ?? null;
    $btn_reg = $url_reg ? '<a href="' . $h($url_reg) . '" class="btn btn-danger fw-bold"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Registra la convenzione firmata</a>' : '';
    $btn_iscr = $pren_att ? '<a href="iscritti.php?p_id=' . (int)$pren_att['pagina_id'] . '&f_cerca=' . urlencode($pren_att['codice']) . '" class="btn btn-outline-secondary fw-bold">Apri le iscrizioni</a>' : '';
    $btn_stud = $pren_att ? '<a href="partecipanti.php?p_id=' . (int)$pren_att['pagina_id'] . '" class="btn btn-outline-secondary fw-bold">Studenti e attestati</a>' : '';
    $prossimi = [
        'iscrizione'  => ['Conferma le prenotazioni in attesa', 'Ci sono prenotazioni non ancora confermate: il posto non è ancora della scuola.', $btn_iscr, 'attesa'],
        'convenzione' => ['Fai firmare la convenzione e registrala', 'Scarica l\'Allegato A e la Convenzione già compilati dalla scheda qui accanto, inviali alla scuola e carica il file firmato.', '<a href="' . $h($url_doc('allegato_pdf')) . '" class="btn btn-outline-danger fw-bold"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A (PDF)</a>' . $btn_reg, ''],
        'attivita'    => ['Tutto a posto: si aspetta lo svolgimento', 'La convenzione è valida. Attività dal ' . $d($g_s['dal']) . ' al ' . $d($g_s['al']) . '.', '', 'ok'],
        'elenco'      => ['Aspetta l\'elenco degli studenti', 'Il docente deve inserire i nomi degli studenti: da quell\'elenco partono gli attestati.', $btn_stud, 'attesa'],
        'attestati'   => ['Manda gli attestati agli studenti', 'L\'elenco c\'è: gli attestati si inviano dalla pagina Studenti e attestati dopo la fine dell\'attività.', $btn_stud, ''],
        'valutazione' => ['Aspetta la valutazione del docente', 'La scheda di valutazione della struttura ospitante arriva dal docente dopo l\'attività.', '', 'attesa'],
    ];
?>
    <p class="mb-2"><?php echo $indietro_s; ?></p>
    <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
        <div class="flex-grow-1">
            <h2 class="h4 fw-bold mb-0" style="text-transform:none;letter-spacing:0;font-size:1.3rem;"><?php echo $h($g_s['nome']); ?></h2>
            <div class="small text-secondary"><?php echo $h($g_s['comune']); ?><?php echo $g_s['codice'] !== '' ? ($g_s['comune'] !== '' ? ' · ' : '') . '<span class="font-monospace">' . $h($g_s['codice']) . '</span>' : ' · scuola scritta a mano nel modulo: abbinala all\'anagrafe per verificarne la convenzione'; ?><?php echo $g_s['n_studenti'] ? ' · ' . (int)$g_s['n_studenti'] . ' studenti' : ''; ?></div>
        </div>
        <?php echo $g_s['da_stipulare'] ? pn_stato('Convenzione da stipulare', 'blocco') : pn_stato('Convenzione a posto' . ($g_s['validita'] ? ' fino al ' . $d($g_s['validita']) : ''), 'ok'); ?>
    </div>

    <?php
    // La convenzione della scuola: finché non è a posto si vedono solo i suoi cinque passi e l'unica cosa da fare adesso
    $co_s = null;
    if ($g_s['codice'] !== '') foreach ($cv_online as $co_x) if (($co_x['scuola_codice'] ?? '') === $g_s['codice']) { $co_s = $co_x; break; }
    $it_s = (string)($co_s['stato_iter'] ?? '');
    $fase_s = !$g_s['da_stipulare'] ? 5 : match (true) { $it_s === 'caricata_scuola' => 2, in_array($it_s, ['in_carico', 'da_firmare'], true) => 3, $it_s === 'firmata_direttore' => 4, default => 1 };
    if ($fase_s < 5):
        $passi_cv = [];
        foreach (['La scuola compila e carica il PDF', 'Tu protocolli', 'Il Direttore firma', 'Tu registri', 'Fatto'] as $i_f => $n_f) $passi_cv[] = ['nome' => $n_f, 'stato' => $i_f + 1 < $fase_s ? 'fatto' : ($i_f + 1 === $fase_s ? 'qui' : '')];
        $form_s = function (string $nome, int $id, string $testo, string $classe, string $icona): string { ob_start(); csrf_field(); $c_f = ob_get_clean(); return '<form method="POST" class="d-inline">' . $c_f . '<button type="submit" name="' . $nome . '" value="' . $id . '" class="btn ' . $classe . ' fw-bold" formnovalidate><i class="fa ' . $icona . ' me-1" aria-hidden="true"></i>' . $testo . '</button></form>'; };
        $id_s = (int)($co_s['id'] ?? 0);
        $btn_doc_s = '<a href="#documentiScuola" class="btn btn-outline-secondary fw-bold"><i class="fa fa-file-lines me-1" aria-hidden="true"></i>Documenti da inviare alla scuola</a>';
        $btn_reg_s = str_replace(['btn-danger', 'Registra la convenzione firmata'], ['btn-outline-secondary', "Ce l'ho già firmata: registrala"], $btn_reg);
        if ($fase_s === 1) {
            $attende_s = $co_s && \App\Fsl\ServizioMessaggiConvenzione::attendeIlPdf($co_s);
            echo pn_iter($passi_cv, 'Convenzione della scuola');
            echo pn_prossima($it_s === 'rifiutata' ? 'Ora si aspetta il nuovo file della scuola' : 'Ora si aspetta la scuola',
                'La scuola deve compilare la convenzione online e caricare il PDF firmato. Non devi fare altro: se vuoi, le mandi un promemoria.',
                ($id_s && $attende_s ? $form_s('cv_ricorda', $id_s, 'Ricorda di caricare il PDF', 'btn-danger', 'fa-bell') : '') . $btn_doc_s . $btn_reg_s, 'attesa');
        } elseif ($fase_s === 2) {
            echo pn_iter($passi_cv, 'Convenzione della scuola');
            echo pn_prossima('Tocca a te: prendi in carico il PDF', 'La scuola ha caricato la convenzione firmata. Scaricala, protocollala e scrivi il numero di protocollo.',
                '<a class="btn btn-danger fw-bold" href="' . $h($url_fsl('iter')) . '#fase-caricata"><i class="fa fa-arrow-right me-1" aria-hidden="true"></i>Apri e protocolla</a>');
        } elseif ($fase_s === 3) {
            echo pn_iter($passi_cv, 'Convenzione della scuola');
            $da_inviare_s = $it_s === 'in_carico';
            echo $da_inviare_s
                ? pn_prossima('Manda il link al Direttore', 'La convenzione è protocollata in entrata ma il Direttore non ha ancora il link per firmare.', $form_s('cv_invia_direttore', $id_s, 'Manda il link al Direttore', 'btn-danger', 'fa-paper-plane'))
                : pn_prossima('Ora si aspetta il Direttore', 'Il link per firmare è già partito. Non devi fare altro: quando firma, il PDF torna qui.', $form_s('cv_invia_direttore', $id_s, 'Rimanda il link al Direttore', 'btn-outline-secondary', 'fa-paper-plane'), 'attesa');
        } else {
            echo pn_iter($passi_cv, 'Convenzione della scuola');
            echo pn_prossima('Tocca a te: registra il protocollo definitivo', 'Il Direttore ha firmato. Scarica il PDF firmato, protocollalo e scrivi il numero di protocollo.',
                '<a class="btn btn-danger fw-bold" href="' . $h($url_fsl('iter')) . '#fase-firmata"><i class="fa fa-arrow-right me-1" aria-hidden="true"></i>Apri e registra</a>');
        }
    else: ?>
    <?php echo pn_iter($passi_s, 'Percorso della scuola'); ?>
    <?php if ($qui_s !== ''): [$t_p, $x_p, $a_p, $tono_p] = $prossimi[$qui_s]; echo pn_prossima($t_p, $x_p, $a_p, $tono_p); else: echo pn_prossima('Percorso completato', 'Convenzione, elenco, attestati e valutazione sono a posto per queste prenotazioni.', '', 'ok'); endif; ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h3 class="h6 fw-bold mb-2"><i class="fa fa-calendar-check me-1" aria-hidden="true"></i>Prenotazioni (<?php echo count($g_s['prenotazioni']); ?>)</h3>
                <?php $stati_ps = ['confermata' => ['Confermata', 'ok'], 'da_approvare' => ['In attesa', 'attesa'], 'in_attesa' => ["Lista d'attesa", 'attesa'], 'richiesta_conferma' => ['Posto offerto', 'info']]; ?>
                <div class="table-responsive"><table class="table table-sm align-middle small mb-0">
                    <thead class="table-light"><tr><th>Attività</th><th>Periodo</th><th class="text-center">Studenti</th><th>Docente</th><th>Prenotazione</th><th>Convenzione</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($g_s['prenotazioni'] as $p_s): [$lp, $tp] = $stati_ps[$p_s['stato']] ?? [$p_s['stato'], 'spento']; ?>
                        <tr>
                            <td><strong><?php echo $h($p_s['titolo']); ?></strong><div class="text-secondary"><?php echo $h($p_s['area']); ?><?php echo $p_s['turno'] !== '' ? ' · ' . $h($p_s['turno']) : ''; ?> · <span class="font-monospace"><?php echo $h($p_s['codice']); ?></span></div></td>
                            <td class="text-nowrap"><?php echo $h($p_s['periodo']); ?></td>
                            <td class="text-center"><?php echo $p_s['studenti'] ?: '—'; ?></td>
                            <td><?php echo $h($p_s['docente']); ?><div class="text-secondary"><?php echo $h($p_s['email']); ?></div></td>
                            <td><?php echo pn_stato($lp, $tp); ?></td>
                            <td><?php echo $p_s['conv'] === 'da_stipulare' ? pn_stato('Da stipulare', 'blocco') : ($p_s['conv'] === 'dichiarata' ? pn_stato('Dichiarata dalla scuola', 'spento') : pn_stato('Convenzione valida', 'ok')); ?></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$p_s['pagina_id']; ?>&f_cerca=<?php echo urlencode($p_s['codice']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3" id="documentiScuola"><div class="card-body">
                <h3 class="h6 fw-bold mb-2"><i class="fa fa-file-lines me-1" aria-hidden="true"></i>Documenti già compilati</h3>
                <p class="small text-secondary">Con i dati dell'anagrafe e delle prenotazioni. Il logo della scuola non è incluso, a meno che l'abbia caricato la scuola.</p>
                <div class="d-grid gap-2">
                    <a href="<?php echo $h($url_doc('allegato_pdf')); ?>" class="btn btn-danger fw-bold"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A (PDF)</a>
                    <a href="<?php echo $h($url_doc('allegato')); ?>" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Allegato A (Word)</a>
                    <a href="<?php echo $h($url_doc('convenzione')); ?>" class="btn btn-outline-primary fw-bold"><i class="fa fa-file-word me-1" aria-hidden="true"></i>Convenzione (Word)</a>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h3 class="h6 fw-bold mb-2"><i class="fa fa-file-signature me-1" aria-hidden="true"></i>Convenzioni registrate</h3>
                <?php $conv_s = $g_s['codice'] !== '' ? convenzioni_della_scuola($conn, $g_s['codice']) : []; ?>
                <?php if (!$conv_s): ?><p class="small text-secondary mb-2">Nessuna convenzione nel registro per questa scuola.</p><?php endif; ?>
                <?php foreach ($conv_s as $c_s): $scad_s = $c_s['scadenza'] !== null && $c_s['scadenza'] < $oggi_f; ?>
                    <div class="small border-top pt-2 mt-2">
                        <?php echo $h(testo_validita_convenzione($c_s)); ?> <?php echo $scad_s ? pn_stato('Scaduta', 'spento') : pn_stato('In vigore', 'ok'); ?>
                        <div class="mt-1 d-flex flex-wrap gap-2">
                            <?php if (!empty($c_s['file_convenzione'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c_s['id']; ?>&f=conv" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Convenzione</a><?php endif; ?>
                            <?php if (!empty($c_s['file_allegato'])): ?><a href="convenzione_file.php?id=<?php echo (int)$c_s['id']; ?>&f=all" target="_blank"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Allegato A</a><?php endif; ?>
                            <a href="<?php echo $h($url_fsl('convenzioni', '&conv_mod=' . (int)$c_s['id']) . '#convForm'); ?>">Modifica</a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($url_reg): ?><a href="<?php echo $h($url_reg); ?>" class="btn btn-sm btn-outline-danger fw-bold mt-3 w-100">Registra una convenzione</a><?php endif; ?>
            </div></div>
        </div>
    </div>
<?php endif; ?>
</section>

<?php elseif ($tab === 'verifica'): ?>
<section class="scu-sez">
    <!-- VERIFICA SULLE ISCRIZIONI DELLE ATTIVITÀ FSL (anche già confermate) -->
    <div id="verifica">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h3 class="h6 fw-bold mb-0"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Verifica delle iscrizioni alle attività FSL</h3>
            <form method="POST" class="ms-auto m-0">
                <?php csrf_field(); ?>
                <button type="submit" name="conv_verifica" value="1" class="btn btn-sm btn-outline-dark fw-bold" data-attesa="Verifica in corso…"><i class="fa fa-rotate me-1" aria-hidden="true"></i>Verifica ora</button>
            </form>
        </div>
        <p class="small text-secondary mb-2">Controlla tutte le iscrizioni (anche confermate) alle attività FSL non ancora concluse, per OpenLab, Formazione Scuola Lavoro e ogni altra area: dove la convenzione copre il periodo l'iscrizione risulta <strong>Convenzione ricevuta</strong>; dove non lo copre diventa <strong>Convenzione da stipulare</strong>, senza cambiare lo stato della prenotazione e senza email automatiche. La richiesta alla scuola la invii da Iscrizioni ("Chiedi la convenzione per email"); da lì partono anche i promemoria. La verifica si ripete ogni giorno da sola.</p>
        <?php if ($iscr_ora_coperte): ?>
            <div class="alert alert-info small py-2"><i class="fa fa-circle-info me-1" aria-hidden="true"></i><?php echo $iscr_ora_coperte === 1 ? "Un'iscrizione segnata «da stipulare» ora è coperta" : "$iscr_ora_coperte iscrizioni segnate «da stipulare» ora sono coperte"; ?> da una convenzione del registro: premi <strong>Verifica ora</strong> per segnarle come ricevute (chi era in attesa viene confermato e avvisato per email).</div>
        <?php endif; ?>
        <?php if ($iscr_da_stipulare): ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle small mb-0">
                    <thead class="table-light"><tr><th>Scuola</th><th>Attività</th><th>Periodo</th><th>Convenzione in registro</th><th>Docente</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($iscr_da_stipulare as $x): $s_x = !empty($x['scuola_codice']) ? scuola_per_codice($conn, $x['scuola_codice']) : null;
                        [$pdal, $pal] = periodo_prenotazione($x); $ult = $s_x ? (convenzioni_della_scuola($conn, $x['scuola_codice'])[0] ?? null) : null; ?>
                        <tr>
                            <td><?php echo $h($s_x ? etichetta_scuola($s_x) : (nome_scuola_prenotazione($x) ?: '—')); ?><div class="text-secondary <?php echo $s_x ? 'font-monospace' : ''; ?>"><?php echo $s_x ? $h($x['scuola_codice']) : 'scritta a mano: abbinala all\'anagrafe'; ?></div></td>
                            <td><?php echo $h($x['titolo']); ?><div class="text-secondary"><?php echo $h($x['area']); ?> · <span class="font-monospace"><?php echo $h($x['codice_prenotazione']); ?></span> · <?php echo $h($x['stato'] ?: 'confermata'); ?></div></td>
                            <td class="text-nowrap"><?php echo date('d/m/Y', strtotime($pdal)) . ($pal !== $pdal ? ' – ' . date('d/m/Y', strtotime($pal)) : ''); ?></td>
                            <td><?php if ($ult): ?><span class="badge" style="background:#fee2e2;color:#991b1b;">non copre il periodo</span><div class="text-secondary"><?php echo $h(testo_validita_convenzione($ult)); ?></div><?php elseif ($s_x): ?><span class="badge bg-light text-dark border">nessuna</span><?php else: ?>—<?php endif; ?></td>
                            <td><?php echo $h(trim($x['nome'] . ' ' . $x['cognome'])); ?><div class="text-secondary"><?php echo $h($x['email']); ?></div></td>
                            <td class="text-nowrap"><a href="iscritti.php?p_id=<?php echo (int)$x['pagina_id']; ?>&f_cerca=<?php echo urlencode($x['codice_prenotazione']); ?>" class="btn btn-sm btn-outline-secondary fw-bold py-0">Iscrizioni</a>
                                <?php if ($s_x): ?><a href="fsl.php?p_id=<?php echo $filtro_p; ?>&tab=convenzioni&conv_nuova=<?php echo urlencode($x['scuola_codice']); ?>&dal=<?php echo $pdal; ?>&al=<?php echo $pal; ?>#convForm" class="btn btn-sm btn-success fw-bold py-0">Registra</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="small text-success mb-0"><i class="fa fa-circle-check me-1" aria-hidden="true"></i>Nessuna iscrizione alle attività FSL in corso senza convenzione.</p>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<p class="text-secondary small">Tutte le attività con <strong>Attività di Formazione Scuola Lavoro</strong> attivo, di ogni area, che iniziano tra il <?php echo $d($dal_a); ?> e il <?php echo $d($al_a); ?>. Studenti = numero dichiarato nelle iscrizioni confermate. Ogni tabella si scarica in Excel, PDF o CSV (menu «Scarica»).</p>

<section class="fsl-sez" id="valutazioni">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h2 class="mb-0"><i class="fa fa-star me-1" aria-hidden="true"></i>Schede di valutazione della struttura ospitante (<?php echo count($valutazioni); ?>)</h2>
        <?php if ($valutazioni) echo pn_scarica($url_fsl('valutazioni', '&anno=' . $anno . '&tabella=valutazioni')); ?>
    </div>
    <p class="small text-secondary">A fine attività (presenza registrata) il docente riceve per email il link alla scheda, con un promemoria dopo 7 giorni. Voti da 1 a 5.</p>
    <?php if (!$valutazioni): ?><p class="text-muted small mb-0">Nessuna scheda compilata in questo anno scolastico.</p><?php else: ?>
    <div class="row g-4 mb-3">
        <div class="col-lg-7">
            <table class="table table-sm small mb-0">
                <?php foreach (VALUTAZIONE_FSL_ASPETTI as $k => $etichetta): $m_k = !empty($somme[$k]) ? array_sum($somme[$k]) / count($somme[$k]) : 0; ?>
                    <tr><td><?php echo $h($etichetta); ?></td><td style="width:40%;"><div class="fsl-barra" role="img" aria-label="Media <?php echo number_format($m_k, 1, ',', ''); ?> su 5"><span style="width:<?php echo round($m_k / 5 * 100); ?>%"></span></div></td><td class="fw-bold text-end"><?php echo number_format($m_k, 1, ',', ''); ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="col-lg-5">
            <div class="small fw-bold mb-1">Riproporrebbero l'attività</div>
            <?php foreach (['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'] as $k => $t): ?><div class="small"><?php echo $t; ?>: <strong><?php echo $rip[$k]; ?></strong></div><?php endforeach; ?>
        </div>
    </div>
    <div class="table-responsive"><table class="table table-sm align-top small" id="tabVal">
        <thead class="table-light"><tr><th>Data</th><th>Attività</th><th>Scuola</th><th>Compilata da</th><th class="text-center">Media</th><th>Riproporrebbe</th>
            <?php foreach (VALUTAZIONE_FSL_ASPETTI as $etichetta): ?><th class="d-none"><?php echo $h($etichetta); ?></th><?php endforeach; ?>
            <?php foreach (VALUTAZIONE_FSL_APERTE as $etichetta): ?><th><?php echo $h($etichetta); ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($valutazioni as $v): $s_v = !empty($v['scuola_codice']) ? scuola_per_codice($conn, $v['scuola_codice']) : null; ?>
            <tr>
                <td class="text-nowrap"><?php echo $d($v['created_at']); ?></td><td><?php echo $h($v['titolo']); ?></td>
                <td><?php echo $h($s_v ? etichetta_scuola($s_v) : '—'); ?></td><td><?php echo $h($v['compilata_da']); ?></td>
                <td class="text-center fw-bold"><?php echo number_format((float)$v['media'], 1, ',', ''); ?></td><td><?php echo $h(['si' => 'Sì', 'forse' => 'Forse', 'no' => 'No'][$v['ripeterebbe']] ?? ''); ?></td>
                <?php foreach (array_keys(VALUTAZIONE_FSL_ASPETTI) as $k): ?><td class="d-none"><?php echo (int)($v['risposte']['voti'][$k] ?? 0); ?></td><?php endforeach; ?>
                <?php foreach (array_keys(VALUTAZIONE_FSL_APERTE) as $k): ?><td style="max-width:260px;"><?php echo nl2br($h($v['risposte']['testi'][$k] ?? '')); ?></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
