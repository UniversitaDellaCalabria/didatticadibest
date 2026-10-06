<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Core\Sito;
use App\Iscrizioni\Vista\RiepilogoPrenotazione;
use App\Sistema\ListaEmail;

/**
 * Chi riceve il riepilogo di prenotazioni e disdette e come è fatto: gestori con notifiche attive, indirizzi aggiuntivi
 * dell'evento e referenti del progetto con «Riceve le iscrizioni». Spostato da get_destinatari_notifiche_prenotazione(),
 * corpo_notifica_per() e html_riepilogo_prenotazione() di inc/base.php, che restano come facciate.
 */
final class NotifichePrenotazione
{
    public function __construct(
        private NotificheRepository $repo,
        private ServizioAbilitazioni $abilitazioni,
        private Sito $sito,
    ) {
    }

    /** @return list<string> indirizzi (minuscoli, senza doppioni) */
    public function destinatari(int $eventoId): array
    {
        $dest = [];
        foreach ($this->abilitazioni->emailGestoriEvento($eventoId) as $e) {
            $dest[strtolower(trim($e))] = true;
        }
        foreach (ListaEmail::normalizza($this->repo->emailExtraEvento($eventoId)) as $e) {
            $dest[$e] = true;
        }
        $referenti = json_decode($this->repo->referentiJson($eventoId), true) ?: [];
        foreach ($referenti as $rf) {
            $em = strtolower(trim((string) ($rf['email'] ?? '')));
            if (!empty($rf['notifiche']) && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $dest[$em] = true;
            }
        }

        return array_map('strval', array_keys($dest));
    }

    /**
     * Il pulsante «Apri gli iscritti del turno» solo per i gestori (hanno accesso all'amministrazione);
     * referenti dei progetti e indirizzi in copia ricevono il riepilogo senza link all'admin.
     *
     * @param array{html: string, html_senza_admin: string} $riepilogo
     * @param list<string> $emailGestori
     */
    public static function corpoPer(string $email, string $intro, array $riepilogo, array $emailGestori): string
    {
        $gestore = in_array(strtolower(trim($email)), array_map(static fn ($e): string => strtolower(trim($e)), $emailGestori), true);

        return $intro . ($gestore ? $riepilogo['html'] : $riepilogo['html_senza_admin']);
    }

    /**
     * Riepilogo completo di una prenotazione per le email: tabella con i dati e tutti i campi aggiuntivi.
     *
     * @return array{oggetto_evento: string|null, html: string, html_senza_admin: string, dati: array<string, string|null>}|null null se la prenotazione non esiste
     */
    public function riepilogo(int $prenotazioneId): ?array
    {
        $p = $this->repo->prenotazioneCompleta($prenotazioneId);
        if ($p === null) {
            return null;
        }
        $etichette = $this->repo->etichetteCampi((int) $p['pagina_id'], (int) $p['evento_id']);

        return RiepilogoPrenotazione::costruisci($p, $etichette, $this->sito->urlBase());
    }
}
