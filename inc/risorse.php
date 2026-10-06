<?php
// inc/risorse.php - Calendari e risorse: aule, laboratori e sportelli (appuntamenti con gli uffici) prenotabili a slot.
// Ogni risorsa appartiene a un'area di tipo "calendario" e ha orari settimanali (risorse_orari), chiusure
// (risorse_chiusure, anche per tutta l'area) e prenotazioni (prenotazioni_risorse) con controllo delle sovrapposizioni.
// La logica sta in src/Risorse/ (RisorsaRepository, ServizioRisorse, NotificheRisorse, Prenotazioni): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('TIPI_RISORSA')) define('TIPI_RISORSA', \App\Risorse\Costanti::TIPI);
if (!defined('ACCESSI_RISORSA')) define('ACCESSI_RISORSA', \App\Risorse\Costanti::ACCESSI);
if (!defined('GIORNI_SETTIMANA')) define('GIORNI_SETTIMANA', \App\Risorse\Costanti::GIORNI);

if (!function_exists('risorsa')) {
    function risorsa($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class)->perId($id);
    }
}

if (!function_exists('orari_risorsa')) {
    // Fasce orarie della risorsa per giorno della settimana: [1 => [['09:00:00','13:00:00'], ...], ...]
    function orari_risorsa($conn, int $risorsa_id): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class)->orari($risorsa_id);
    }
}

if (!function_exists('chiusura_risorsa')) {
    // Motivo della chiusura della risorsa (o di tutta l'area) nel giorno, null se aperta
    function chiusura_risorsa($conn, array $r, string $data): ?string {
        return \App\Core\App::per($conn)->get(\App\Risorse\RisorsaRepository::class)->chiusura((int)$r['id'], (int)$r['pagina_id'], $data);
    }
}

if (!function_exists('slot_risorsa')) {
    // Slot di un giorno: [['inizio' => 'Y-m-d H:i:s', 'fine' => ..., 'stato' => libero|occupato|passato|lontano|chiuso, 'fascia' => n], ...]
    // passato = prima dell'anticipo minimo; lontano = oltre i giorni prenotabili; fascia = indice della fascia oraria
    // (gli slot consecutivi si possono unire solo nella stessa fascia).
    function slot_risorsa($conn, array $r, string $data, ?int $escludi_pren = null): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->slot($r, $data, $escludi_pren);
    }
}

if (!function_exists('gruppi_utente_nomi')) {
    // Nomi dei gruppi dell'utente (ruolo principale e secondari), in minuscolo
    function gruppi_utente_nomi($conn, array $u): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->gruppiUtenteNomi($u);
    }
}

if (!function_exists('puo_prenotare_risorsa')) {
    // Chi può prenotare: amministratori e gestori dell'area sempre; poi in base a risorse.accesso
    function puo_prenotare_risorsa($conn, array $r, ?array $u): bool {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->puoPrenotare($r, $u);
    }
}

if (!function_exists('prenota_risorsa')) {
    // Prenota $n_slot slot consecutivi da $inizio (Y-m-d H:i:s) e, se la risorsa lo consente, ogni settimana fino a $ripeti_fino.
    // La prima occorrenza deve riuscire; le successive in conflitto vengono saltate. Ritorna
    // ['codici' => [...], 'saltate' => ['Y-m-d' => motivo], 'errore' => null|string, 'stato' => confermata|da_approvare, 'serie' => ?].
    function prenota_risorsa($conn, array $r, array $u, string $inizio, int $n_slot, string $motivo = '', ?string $ripeti_fino = null): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->prenota($r, $u, $inizio, $n_slot, $motivo, $ripeti_fino);
    }
}

if (!function_exists('prenotazione_risorsa')) {
    // Prenotazione con risorsa e area (per id o per codice)
    function prenotazione_risorsa($conn, $chiave): ?array {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->prenotazione($chiave);
    }
}

if (!function_exists('quando_risorsa')) {
    // "Martedì 10/11/2026, 09:00–11:00"
    function quando_risorsa(array $p): string {
        return \App\Risorse\Prenotazioni::quando($p);
    }
}

if (!function_exists('email_gestori_risorsa')) {
    // Chi riceve le notifiche: indirizzi della risorsa, altrimenti i gestori dell'area con le notifiche attive
    function email_gestori_risorsa($conn, array $p): array {
        return \App\Core\App::per($conn)->get(\App\Risorse\NotificheRisorse::class)->emailGestori($p);
    }
}

if (!function_exists('email_prenotazione_risorsa')) {
    // Email a chi ha prenotato. $tipo: registrata | approvata | rifiutata | annullata_gestore | promemoria
    // $codici: più prenotazioni della stessa serie in un'unica email (solo "registrata")
    function email_prenotazione_risorsa($conn, array $p, string $tipo, array $altre = [], array $saltate = []): bool {
        return \App\Core\App::per($conn)->get(\App\Risorse\NotificheRisorse::class)->emailPrenotazione($p, $tipo, $altre, $saltate);
    }
}

if (!function_exists('notifica_gestori_risorsa')) {
    // Email ai gestori: nuova prenotazione (o richiesta da approvare) oppure annullamento da parte dell'utente
    function notifica_gestori_risorsa($conn, array $p, string $tipo, int $n = 1): void {
        \App\Core\App::per($conn)->get(\App\Risorse\NotificheRisorse::class)->notificaGestori($p, $tipo, $n);
    }
}

if (!function_exists('cambia_stato_prenotazione_risorsa')) {
    // Approva / rifiuta / annulla (gestore) oppure annulla (utente). Ritorna true se lo stato è cambiato.
    function cambia_stato_prenotazione_risorsa($conn, int $id, string $nuovo, bool $da_gestore = true): bool {
        return \App\Core\App::per($conn)->get(\App\Risorse\ServizioRisorse::class)->cambiaStato($id, $nuovo, $da_gestore);
    }
}

if (!function_exists('ics_prenotazione_risorsa')) {
    // File .ics della prenotazione
    function ics_prenotazione_risorsa(array $p): string {
        return \App\Risorse\Prenotazioni::ics($p);
    }
}
