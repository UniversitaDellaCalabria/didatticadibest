<?php
// inc/avvisi.php - Avvisi per email dei nuovi eventi per ambito (avvisi.php). La logica sta in src/Avvisi/
// (ServizioAvvisi, IscrizioneRepository, Iscrizione): qui restano le funzioni di prima come facciate, per il cron
// (admin/cron_reminders.php), il report per ambito e le prove automatiche.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

use App\Avvisi\ServizioAvvisi;
use App\Core\App;

if (!function_exists('iscrivi_avvisi')) {
    // Ritorna [messaggio, errore]. $utente: utente collegato (se l'email è la sua, l'iscrizione è confermata subito)
    function iscrivi_avvisi($conn, string $email, array $ambiti, bool $scuole, ?array $utente = null): array {
        return App::per($conn)->get(ServizioAvvisi::class)->iscrivi($email, $ambiti, $scuole, $utente)->comeCoppia();
    }
    function iscrizione_avvisi_per_token($conn, string $tok): ?array {
        return App::per($conn)->get(ServizioAvvisi::class)->rigaPerToken($tok);
    }
    function conferma_avvisi($conn, string $tok): bool {
        return App::per($conn)->get(ServizioAvvisi::class)->conferma($tok);
    }
    function cancella_avvisi($conn, string $tok): bool {
        return App::per($conn)->get(ServizioAvvisi::class)->cancella($tok);
    }
    // Iscritti confermati per argomento (pannello): ['orientamento' => n, …, 'scuole' => n, '' => totale]
    function conta_iscritti_avvisi($conn): array {
        return App::per($conn)->get(ServizioAvvisi::class)->contaIscritti();
    }
}

if (!function_exists('invia_avvisi_eventi')) {
    // Cron: riepilogo dei nuovi eventi agli iscritti (vedi ServizioAvvisi::inviaNovita). Ritorna le email inviate.
    function invia_avvisi_eventi($conn): int {
        return App::per($conn)->get(ServizioAvvisi::class)->inviaNovita();
    }
}
