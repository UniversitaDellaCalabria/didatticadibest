<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Orologio;
use App\Email\InvioEmail;

/**
 * Cambio dell'email del profilo con verifica: la nuova email vale solo dopo che la persona ha scritto il codice di 6 cifre arrivato a quell'indirizzo.
 * Serve perché diverse autorizzazioni riconoscono le persone dall'email (conferme e firme dei docenti, docente del tutorato, «Da fare per te»):
 * senza verifica chiunque poteva scrivere nel profilo l'email di un docente e ricevere i suoi compiti. Una verifica in corso per persona
 * (tabella verifiche_email); il codice si salva solo come impronta. A cambio fatto si avvisa anche il vecchio indirizzo.
 */
final class VerificaEmail
{
    public const MINUTI_VALIDITA = 30;
    public const TENTATIVI = 5;
    public const SECONDI_TRA_INVII = 60;
    public const INVII_AL_GIORNO = 10;

    public function __construct(private Database $db, private UtenteRepository $utenti, private InvioEmail $invio, private Orologio $orologio)
    {
    }

    /** Manda il codice alla nuova email. Ritorna null se è partito, altrimenti il motivo per cui non è partito. */
    public function richiedi(int $utenteId, string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Inserisci un indirizzo email valido.';
        }
        $utente = $this->utenti->perId($utenteId);
        if ($utente === null) {
            return 'Utente non trovato.';
        }
        if (mb_strtolower(trim((string) ($utente['email'] ?? ''))) === $email) {
            return 'Questa è già la tua email.';
        }
        if ($this->utenti->emailUsataDaAltri($email, $utenteId)) {
            return 'Questa email è già associata a un altro account.';
        }
        $ora = $this->orologio->adesso();
        $prima = $this->db->riga('SELECT creata_il, invii FROM verifiche_email WHERE utente_id = ?', [$utenteId]);
        $invii = 1;
        if ($prima !== null) {
            $da = $ora->getTimestamp() - (int) strtotime((string) $prima['creata_il']);
            if ($da < self::SECONDI_TRA_INVII) {
                return 'Ti abbiamo appena mandato un codice: aspetta un minuto prima di chiederne un altro.';
            }
            if ($da < 86400) {
                $invii = (int) $prima['invii'] + 1;
                if ($invii > self::INVII_AL_GIORNO) {
                    return 'Hai chiesto troppi codici oggi: riprova domani o scrivi all\'assistenza.';
                }
            }
        }
        $codice = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $salvata = $this->db->esegui('REPLACE INTO verifiche_email (utente_id, email, codice_hash, scade_il, tentativi, invii, creata_il) VALUES (?, ?, ?, ?, 0, ?, ?)', [
            $utenteId, $email, self::impronta($utenteId, $email, $codice), $ora->modify('+' . self::MINUTI_VALIDITA . ' minutes')->format('Y-m-d H:i:s'), $invii, $ora->format('Y-m-d H:i:s'),
        ]);
        if ($salvata < 0) {
            return 'Non è stato possibile preparare il codice: riprova tra qualche minuto.';
        }
        if (!$this->invio->invia($email, 'profilo_codice_email', ['codice' => $codice, 'minuti' => self::MINUTI_VALIDITA])) {
            $this->db->esegui('DELETE FROM verifiche_email WHERE utente_id = ?', [$utenteId]);

            return 'Non siamo riusciti a mandare l\'email: controlla l\'indirizzo e riprova.';
        }

        return null;
    }

    /**
     * Controlla il codice e, se è giusto, imposta la nuova email (personalizzata e verificata).
     *
     * @return array{0: ?string, 1: ?string} [errore, nuova email]
     */
    public function conferma(int $utenteId, string $codice): array
    {
        $v = $this->db->riga('SELECT email, codice_hash, scade_il, tentativi FROM verifiche_email WHERE utente_id = ?', [$utenteId]);
        if ($v === null) {
            return ['Non c\'è una verifica in corso: chiedi un nuovo codice.', null];
        }
        $ora = $this->orologio->adesso();
        if ((int) strtotime((string) $v['scade_il']) < $ora->getTimestamp()) {
            $this->annulla($utenteId);

            return ['Il codice è scaduto: chiedine uno nuovo.', null];
        }
        $email = (string) $v['email'];
        $codice = (string) preg_replace('/\D/', '', $codice);
        if (!hash_equals((string) $v['codice_hash'], self::impronta($utenteId, $email, $codice))) {
            $tentativi = (int) $v['tentativi'] + 1;
            if ($tentativi >= self::TENTATIVI) {
                $this->annulla($utenteId);

                return ['Codice sbagliato per troppe volte: chiedi un nuovo codice.', null];
            }
            $this->db->esegui('UPDATE verifiche_email SET tentativi = ? WHERE utente_id = ?', [$tentativi, $utenteId]);
            $restano = self::TENTATIVI - $tentativi;

            return ['Codice sbagliato: ' . ($restano === 1 ? 'ti resta un tentativo.' : 'ti restano ' . $restano . ' tentativi.'), null];
        }
        $this->annulla($utenteId);
        if ($this->utenti->emailUsataDaAltri($email, $utenteId)) {
            return ['Questa email è già associata a un altro account.', null];
        }
        $vecchia = mb_strtolower(trim((string) ($this->utenti->perId($utenteId)['email'] ?? '')));
        if (!$this->utenti->impostaEmailVerificata($utenteId, $email, $ora->format('Y-m-d H:i:s'))) {
            return ['Errore durante il salvataggio. Riprova.', null];
        }
        if ($vecchia !== '' && $vecchia !== $email && filter_var($vecchia, FILTER_VALIDATE_EMAIL)) {
            $this->invio->invia($vecchia, 'profilo_email_cambiata', ['email' => $email]);
        }

        return [null, $email];
    }

    /**
     * La verifica in corso, se c'è e non è scaduta (per mostrare «abbiamo mandato un codice a …»).
     *
     * @return array{email: string, scade_il: string}|null
     */
    public function inCorso(int $utenteId): ?array
    {
        $v = $this->db->riga('SELECT email, scade_il FROM verifiche_email WHERE utente_id = ?', [$utenteId]);
        if ($v === null || (int) strtotime((string) $v['scade_il']) < $this->orologio->adesso()->getTimestamp()) {
            return null;
        }

        return ['email' => (string) $v['email'], 'scade_il' => (string) $v['scade_il']];
    }

    public function annulla(int $utenteId): void
    {
        $this->db->esegui('DELETE FROM verifiche_email WHERE utente_id = ?', [$utenteId]);
    }

    private static function impronta(int $utenteId, string $email, string $codice): string
    {
        return hash('sha256', $utenteId . '|' . $email . '|' . $codice);
    }
}
