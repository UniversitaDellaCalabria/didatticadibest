<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Core\Orologio;

/**
 * Conservazione delle chat (art. 5.1.e GDPR, durata nel .env: CONSERVAZIONE_ASSISTENZA_MESI, predefinita 12): dopo quel tempo dall'ultimo movimento
 * i messaggi, i recapiti delle notifiche push e le domande a cui Didacticus non ha risposto si cancellano e la conversazione resta senza dati personali (nome ridotto alle iniziali, email, indirizzo IP e riferimento alla pratica
 * svuotati, link personale non più valido). Restano argomento, tempi e valutazione, per le statistiche.
 */
final class ConservazioneAssistenza
{
    public function __construct(private Database $db, private Orologio $orologio)
    {
    }

    /** Conversazioni chiuse (o ferme) da più di $mesi mesi: ritorna quante sono state ripulite. */
    public function applica(int $mesi): int
    {
        if ($mesi < 1) {
            return 0;
        }
        $limite = $this->orologio->adesso()->modify("-$mesi months")->format('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->db->righe("SELECT id, nome FROM assistenza_conversazioni WHERE email <> '' AND ultimo_il < ?", [$limite]) as $c) {
            $id = (int) $c['id'];
            $this->db->esegui('DELETE FROM assistenza_messaggi WHERE conversazione_id = ?', [$id]);
            $this->db->esegui("DELETE FROM push_sottoscrizioni WHERE ruolo = 'utente' AND conversazione_id = ?", [$id]);
            $this->db->esegui('DELETE FROM didacticus_mancate WHERE conversazione_id = ?', [$id]);
            $this->db->esegui(
                "UPDATE assistenza_conversazioni SET nome = ?, email = '', ip = '', contesto_testo = '', contesto_id = NULL, utente_id = NULL, token = ?, operatore_nome = '' WHERE id = ?",
                [self::iniziali((string) $c['nome']), substr(hash('sha256', 'assistenza' . $id . random_bytes(8)), 0, 32), $id]
            );
            ++$n;
        }

        return $n;
    }

    public static function iniziali(string $nome): string
    {
        $out = '';
        foreach (preg_split('/\s+/u', trim($nome)) ?: [] as $parte) {
            if ($parte !== '') {
                $out .= mb_strtoupper(mb_substr($parte, 0, 1)) . '. ';
            }
        }

        return trim($out);
    }
}
