<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Orologio;

/**
 * Registrazione della presenza: auto-registrazione dello studente con il QR del turno (self_checkin.php, con finestra
 * da 30 minuti prima dell'inizio alla fine del turno) e check-in del biglietto da parte dei gestori (checkin.php).
 */
final class ServizioCheckin
{
    public function __construct(private PrenotazioneRepository $prenotazioni, private Orologio $orologio)
    {
    }

    /**
     * Auto-registrazione dello studente connesso: il QR porta il turno e il codice di sicurezza.
     *
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     *         esito «success», «warning» o «error»; colore e icona servono alla pagina; messaggio è HTML già pronto
     */
    public function autoRegistrazione(int $turnoId, string $token, int $utenteId): array
    {
        if ($turnoId === 0 || empty($token)) {
            return self::risposta('error', 'Dati del QR Code mancanti o incompleti. Prova a ripetere la scansione.', 'danger', 'fa-qrcode');
        }
        $turno = $this->prenotazioni->turnoPerCheckin($turnoId, $token);
        if ($turno === null) {
            return self::risposta('error', 'QR Code non valido o scaduto. La segreteria potrebbe aver ruotato il codice di sicurezza.', 'danger', 'fa-times-circle');
        }

        $adesso = $this->orologio->adesso()->getTimestamp();
        if (empty($turno['data_turno'])) {
            // Turno identificato solo dal nome: il check-in resta sempre aperto
            $inizio = $adesso;
            $fine = $adesso;
        } else {
            $inizio = strtotime($turno['data_turno'] . ' ' . ($turno['orario_inizio'] ?: '00:00:00')) - (30 * 60);
            $fine = strtotime($turno['data_turno'] . ' ' . ($turno['orario_fine'] ?: '23:59:59'));
        }

        if ($adesso < $inizio) {
            return self::risposta('warning', "È troppo presto per registrarsi! Il check-in aprirà 30 minuti prima dell'inizio.", 'warning', 'fa-clock', $turno);
        }
        if ($adesso > $fine) {
            return self::risposta('error', 'Il periodo per registrare la presenza a questo evento è terminato.', 'secondary', 'fa-calendar-times', $turno);
        }

        $pren = $this->prenotazioni->dellUtenteSulTurno($turnoId, $utenteId);
        if ($pren === null) {
            return self::risposta('error', 'Non risulti iscritto a questo turno. Devi prima effettuare la prenotazione.', 'danger', 'fa-user-times', $turno);
        }
        if ($pren['stato'] !== 'confermata') {
            return self::risposta('error', 'La tua iscrizione non è confermata (Stato: ' . strtoupper((string) $pren['stato']) . ').', 'danger', 'fa-exclamation-triangle', $turno);
        }
        if ($pren['presente'] == 1) {
            return self::risposta('success', 'La tua presenza era già stata registrata. Nessuna ulteriore azione richiesta.', 'success', 'fa-check-double', $turno);
        }
        $this->prenotazioni->registraPresenza((int) $pren['id']);

        return self::risposta('success', 'Check-in completato con successo! Presenza convalidata ufficialmente.', 'success', 'fa-check-circle', $turno);
    }

    /** Presenza registrata dal gestore scansionando il biglietto (checkin.php). */
    public function registraPresenza(int $prenotazioneId): void
    {
        $this->prenotazioni->registraPresenza($prenotazioneId);
    }

    /**
     * @param array<string, mixed>|null $turno
     * @return array{esito: string, messaggio: string, colore: string, icona: string, turno: array<string, mixed>|null}
     */
    private static function risposta(string $esito, string $messaggio, string $colore, string $icona, ?array $turno = null): array
    {
        return ['esito' => $esito, 'messaggio' => $messaggio, 'colore' => $colore, 'icona' => $icona, 'turno' => $turno];
    }
}
