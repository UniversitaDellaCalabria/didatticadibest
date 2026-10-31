<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Database;

/**
 * «Invita l'azienda» dalla pratica di tesi: se l'azienda scelta non è ancora convenzionata lo studente ne scrive il nome e l'email del referente, parte l'invito a
 * compilare la convenzione (origine «studente») e la convenzione resta collegata alla pratica (pratiche.convenzione_tesi_id): lo studente ne vede l'avanzamento e
 * riceve l'avviso a convenzione stipulata. Una sola convenzione aperta per pratica e al massimo MAX_INVITI inviti per studente (contro l'uso per mandare email a caso).
 */
final class InvitoAziendaDaPratica
{
    public const MAX_INVITI = 5;

    public function __construct(private Database $db, private ServizioConvenzioniTesi $conv, private AziendaDellaPratica $azienda)
    {
    }

    /** @return array<string, mixed>|null la convenzione collegata alla pratica */
    public function collegata(int $praticaId): ?array
    {
        return $this->azienda->collegata($praticaId);
    }

    /**
     * @param array<string, mixed> $utente lo studente che ha fatto l'accesso
     * @return string|null il messaggio d'errore, null se l'invito è partito
     */
    public function invita(array $utente, int $praticaId, string $nome, string $email): ?string
    {
        $nome = trim($nome);
        $email = strtolower(trim($email));
        $p = empty($utente['id']) ? null : $this->db->riga('SELECT id, utente_id, stato, risposte_json FROM pratiche WHERE id = ? AND utente_id = ?', [$praticaId, (int) $utente['id']]);
        if (!$p || (string) $p['stato'] === 'bozza') {
            return 'Questa pratica non è disponibile: invia prima la richiesta.';
        }
        $aperta = $this->azienda->collegata($praticaId);
        if ($aperta && (string) $aperta['stato'] !== Costanti::PROTOCOLLATA) {
            return 'Hai già invitato un\'azienda per questa pratica: ne vedi l\'avanzamento qui sotto.';
        }
        $scelta = AziendaDellaPratica::scelta((array) (json_decode((string) $p['risposte_json'], true) ?: []));
        if ($scelta === null || $this->azienda->stato($scelta['valore'], $scelta['nome_altra'])['esito'] !== 'da_chiedere') {
            return 'L\'azienda di questa pratica non ha bisogno di una nuova convenzione.';
        }
        if ($nome === '' || mb_strlen($nome) > 200) {
            return 'Scrivi il nome dell\'azienda.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Scrivi l\'email del referente dell\'azienda: il link per la convenzione arriva lì.';
        }
        if ((int) $this->db->valore('SELECT COUNT(*) FROM pratiche WHERE utente_id = ? AND convenzione_tesi_id IS NOT NULL', [(int) $utente['id']]) >= self::MAX_INVITI) {
            return 'Hai raggiunto il numero massimo di inviti: scrivi all\'ufficio con «Serve aiuto?».';
        }
        $da = trim((string) ($utente['nome'] ?? '') . ' ' . (string) ($utente['cognome'] ?? ''));
        [$errore, $id] = $this->conv->avvia($email, $nome, Costanti::ORIGINE_STUDENTE, $da);
        if ($errore !== null) {
            return $errore;
        }
        $this->db->esegui('UPDATE pratiche SET convenzione_tesi_id = ? WHERE id = ?', [$id, $praticaId]);

        return null;
    }
}
