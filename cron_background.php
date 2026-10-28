<?php
// cron_background.php - Motore Automazioni (Da richiamare ogni 15 minuti tramite crontab Ubuntu)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
consenti_esecuzione_cron([1]); // solo crontab, chiave CRON_KEY o admin loggato

// =========================================================================
// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE (stesso pattern già
// in uso in cron_attestati.php). Se il crontab lancia una nuova esecuzione
// mentre la precedente sta ancora girando (es. invio email lento, tanti
// destinatari), qui evitiamo doppio invio delle email post-evento.
// =========================================================================
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . $cache_dir . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file_bg = $cache_dir . '/cron_background.lock';

// Anti lock-orfano: se il lock esiste da più di 10 minuti lo consideriamo residuo
// di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file_bg) && (time() - filemtime($lock_file_bg)) > 600) {
    @unlink($lock_file_bg);
}

$lock_handle_bg = fopen($lock_file_bg, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se già in uso, fallisce subito)
if (!$lock_handle_bg || !flock($lock_handle_bg, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: cron_background.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa).\n");
}


$now = date('Y-m-d H:i:s');
$cron = \App\Core\App::get(\App\Iscritti\ServizioCron::class);

echo "Inizio Esecuzione CRON: $now\n";

// =========================================================================
// TASK 0: AUTO-ARCHIVIAZIONE EVENTI SCADUTI
// =========================================================================
echo $cron->autoArchiviazione();

// =========================================================================
// TASK 1: EMAIL POST-EVENTO (Attestati e Sondaggi)
// Seleziona i presenti a eventi finiti, a cui NON è ancora stata mandata l'email
// Progetti: solo a progetto concluso (data di fine passata)
// =========================================================================
// Indirizzo del portale calcolato dalla richiesta (puoi anche hardcodarlo se preferisci)
$domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'il-tuo-sito.unical.it') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
echo $cron->emailPostEvento($now, $domain);

// =========================================================================
// TASK 1b: PROMEMORIA AL DOCENTE PER L'ELENCO DEGLI STUDENTI
// Prenotazioni di classe con attestati ed elenco ancora vuoto: chi ha prenotato riceve un'email (una sola volta)
// con il link per compilarlo. Progetti per le scuole: a 7 giorni (o meno) dalla fine.
// Eventi con attestati per la classe: da 3 giorni prima del turno fino a 14 giorni dopo.
// =========================================================================
echo $cron->promemoriaElenco();

// Attestati degli studenti ad attività conclusa (se il cron degli attestati non è pianificato a parte):
// progetti per le scuole dopo la data di fine, eventi con attestati per la classe dopo il giorno del turno
echo $cron->attestatiClassi();

// =========================================================================
// TASK 1g: CONVENZIONI CON LE SCUOLE
// 0) verifica delle iscrizioni alle attività FSL (anche confermate): la convenzione deve coprire il periodo dell'attività;
// a) promemoria alla scuola ogni 7 giorni (massimo 3) finché la convenzione non arriva, fino alla fine dell'attività
//    (dopo la richiesta del gestore: le iscrizioni segnate dalla verifica partono senza promemoria);
// b) avviso ai gestori (una volta) quando l'attività inizia entro 7 giorni e ci sono scuole ancora senza convenzione;
// c) avviso agli amministratori (una volta) per le convenzioni del registro che scadono entro 60 giorni.
// =========================================================================
echo $cron->convenzioniVerifica();
echo $cron->convenzioniPromemoria();
echo $cron->convenzioniAvvisoGestori();
echo $cron->convenzioniInScadenza();

// =========================================================================
// TASK 1h: DOCUMENTI DA CONSEGNARE PRIMA DELLE ATTIVITÀ FSL
// Elenco degli studenti e autorizzazione della scuola (PDF): il docente che ha prenotato riceve un promemoria ogni 7 giorni
// (al massimo 6) finché manca qualcosa, poi un ultimo avviso quando si supera la scadenza (7 giorni prima dell'inizio).
// =========================================================================
echo \App\Core\App::get(\App\Fsl\ServizioDocumentiClasse::class)->promemoria();

// =========================================================================
// TASK 1i: SCHEDA DI VALUTAZIONE DELLA STRUTTURA OSPITANTE (FSL)
// Attività FSL concluse da non più di 30 giorni, prenotazione confermata con la presenza registrata: il docente
// riceve il link alla scheda; se dopo 7 giorni non l'ha compilata, un solo promemoria.
// =========================================================================
echo $cron->valutazioniFsl();

// =========================================================================
// TASK 1c: CONSERVAZIONE DEI NOMI DEGLI STUDENTI (privacy)
// - Iscrizioni annullate/rifiutate/scadute: l'elenco senza attestati emessi non serve più e si cancella.
// - Dopo MESI_CONSERVAZIONE_STUDENTI dalla fine del progetto o dal giorno dell'evento (o dall'inserimento, se non
//   c'è una data) i nomi si riducono alle iniziali: i codici degli attestati restano verificabili.
// =========================================================================
echo $cron->conservazioneStudenti((int)MESI_CONSERVAZIONE_STUDENTI);

// =========================================================================
// TASK 1e: CONSERVAZIONE DEI DATI (art. 5.1.e GDPR) - durate nel file .env, descritte in privacy.php
// - Registri tecnici (accessi con IP e browser, email inviate): CONSERVAZIONE_LOG_MESI, predefinito 12
// - Registro delle azioni amministrative: CONSERVAZIONE_AUDIT_MESI, predefinito 24
// - Prenotazioni: CONSERVAZIONE_PRENOTAZIONI_MESI dopo l'attività (0 = mai). Non si cancellano: nome e cognome
//   ridotti alle iniziali, email/matricola/campi del modulo svuotati, messaggi e allegati eliminati.
//   Restano codice, turno, stato e presenza: statistiche e codici degli attestati continuano a funzionare.
// - Account senza accesso da CONSERVAZIONE_UTENTI_MESI (0 = mai), esclusi amministratori e gestori.
// =========================================================================
$mesi_log   = max(1, (int)(env_valore('CONSERVAZIONE_LOG_MESI') ?? 12));
$mesi_audit = max(1, (int)(env_valore('CONSERVAZIONE_AUDIT_MESI') ?? 24));
echo $cron->conservazioneRegistri($mesi_log, $mesi_audit);

$mesi_pren = max(0, (int)(env_valore('CONSERVAZIONE_PRENOTAZIONI_MESI') ?? 0));
if ($mesi_pren > 0) echo $cron->conservazionePrenotazioni($mesi_pren);

// - Tutorato: lettere protocollate o annullate da CONSERVAZIONE_INCARICHI_MESI (0 = mai) senza dati personali, PDF e registro;
//   convocazioni delle sedute (link, email, motivi delle assenze) cancellate dopo CONSERVAZIONE_CONVOCAZIONI_MESI (predefinito 12)
$mesi_inc = max(0, (int)(env_valore('CONSERVAZIONE_INCARICHI_MESI') ?? 0));
if ($mesi_inc > 0) echo $cron->conservazioneTutorato($mesi_inc);
$mesi_conv = max(0, (int)(env_valore('CONSERVAZIONE_CONVOCAZIONI_MESI') ?? 12));
if ($mesi_conv > 0) echo $cron->conservazioneSedute($mesi_conv);

$mesi_ut = max(0, (int)(env_valore('CONSERVAZIONE_UTENTI_MESI') ?? 0));
if ($mesi_ut > 0) echo $cron->conservazioneAccount($mesi_ut);

// - Chat di assistenza ferme da CONSERVAZIONE_ASSISTENZA_MESI (predefinito 12, 0 = mai): messaggi cancellati e conversazione senza dati personali
$mesi_as = max(0, (int)(env_valore('CONSERVAZIONE_ASSISTENZA_MESI') ?? 12));
if ($mesi_as > 0) {
    // Pagine di Ateneo che Didacticus legge come fonti: si rileggono quelle ferme da più di una settimana (di solito poche per volta)
    try { [$pg_ok, $pg_ko] = \App\Core\App::per($conn)->get(\App\Assistenza\Didacticus\ServizioPagine::class)->aggiornaScadute(); if ($pg_ok + $pg_ko > 0) echo "- Pagine di Ateneo per Didacticus: $pg_ok lette, $pg_ko con errore.\n"; }
    catch (\Throwable $e_pg) { echo '- Pagine di Ateneo per Didacticus: errore (' . $e_pg->getMessage() . ")\n"; }
    // Significato delle voci di Didacticus (embedding): si ricalcolano solo le voci nuove o cambiate; senza EMB_URL non fa nulla
    try { [$sg_ok, $sg_ko] = \App\Core\App::per($conn)->get(\App\Assistenza\Didacticus\ServizioSignificato::class)->aggiorna(); if ($sg_ok + $sg_ko > 0) echo "- Significato delle voci di Didacticus: $sg_ok calcolate, $sg_ko non calcolate.
"; }
    catch (\Throwable $e_sg) { echo '- Significato delle voci di Didacticus: errore (' . $e_sg->getMessage() . ")
"; }
    try { echo '- Conservazione assistenza: ' . \App\Core\App::per($conn)->get(\App\Assistenza\ConservazioneAssistenza::class)->applica($mesi_as) . " chat ripulite (ferme da più di $mesi_as mesi).\n"; }
    catch (\Throwable $e) { error_log('Cron, conservazione assistenza: ' . $e->getMessage()); }
}

// =========================================================================
// TASK 1d: RIEPILOGO SETTIMANALE DELLE EMAIL AGLI AMMINISTRATORI (dal lunedì, una volta a settimana)
// =========================================================================
if (date('N') >= 1) {
    echo $cron->riepilogoSettimanale();
}

// =========================================================================
// TASK 1j: RIEPILOGO SETTIMANALE «DA FARE PER TE» alle persone che hanno cose in attesa (conferme, firme, registri, schede, verbali, pratiche…),
// una volta a settimana, dal lunedì al venerdì dalle 7; il Direttore ci trova anche il cruscotto. RIEPILOGO_PERSONE=0 nel .env lo spegne.
// =========================================================================
echo \App\Core\App::get(\App\Portale\RiepilogoPersone::class)->cron();

// =========================================================================
// TASK 1k: CONVENZIONI DI TESI CON ENTI E AZIENDE: promemoria alle aziende ferme (ogni 7 giorni, fino a 120 giorni dall'invito) e avviso di scadenza
// (60 giorni prima) all'azienda e all'ufficio, una volta sola
// =========================================================================
echo \App\Core\App::get(\App\ConvenzioniTesi\ServizioConvenzioniTesi::class)->cron();

// =========================================================================
// TASK 1h: CONTROLLO AUTOMATICO DEL PORTALE, una volta al giorno dalle 3 di notte
// (pagine, file riservati, spazio su disco, backup, email): avviso agli amministratori solo se qualcosa non va
// =========================================================================
echo $cron->controlloPortale();

// =========================================================================
// TASK 1l: ARCHIVIO VERBALI dalla cartella sul NAS (uploads/verbali/archivio/): nuovi PDF letti, cambiati riletti, tolti quelli spariti; al massimo una volta all'ora
// =========================================================================
try {
    echo \App\Core\App::get(\App\Didattica\ScansioneArchivio::class)->cron();
} catch (\Throwable $e) {
    error_log('Cron, archivio verbali: ' . $e->getMessage());
}

// =========================================================================
// TASK 1f: ANAGRAFE DEL PERSONALE E CORSI DI STUDIO (API del portale di Ateneo), una volta a settimana
// =========================================================================
echo $cron->anagrafeAteneo();

// =========================================================================
// TASK 2: PROMEMORIA PRE-EVENTO E ALTRE AUTOMAZIONI
// =========================================================================
$file_reminders = __DIR__ . '/admin/cron_reminders.php';
if (file_exists($file_reminders)) {
    ob_start();
    include $file_reminders;
    ob_end_clean();
    echo "- Promemoria (admin/cron_reminders.php) eseguiti.\n";
}

echo "Esecuzione CRON terminata con successo.\n";

// Rilascio esplicito del lock (viene comunque rilasciato dal sistema alla chiusura dello script)
flock($lock_handle_bg, LOCK_UN);
fclose($lock_handle_bg);
?>
