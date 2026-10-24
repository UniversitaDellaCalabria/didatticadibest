<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * «Chi contattare per cosa»: i recapiti istituzionali che il Dipartimento sceglie di mostrare agli studenti (contatti_studenti).
 * Sono scritti a mano dal pannello (admin/contatti.php): non vengono mai presi dagli uffici o dalle persone dell'anagrafe.
 */
final class ContattiRepository
{
    /** Voci di partenza proposte al primo uso: nascoste e senza recapiti, da completare dal pannello. */
    public const ESEMPI = [
        ['Segreteria didattica', 'Certificati, piano di studi, convalide, pratiche della carriera'],
        ['Ufficio Tirocini', 'Convenzioni, avvio del tirocinio sulla piattaforma, crediti'],
        ['Tesi e laurea', 'Richiesta di tesi, convenzione con aziende, domanda di laurea'],
        ['Orientamento', 'Informazioni sui corsi per chi vuole iscriversi e per le scuole'],
        ['Direzione del Dipartimento', 'Richieste che riguardano il Dipartimento nel suo insieme'],
    ];

    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function tutti(): array
    {
        return $this->db->righe('SELECT * FROM contatti_studenti ORDER BY ordine, id');
    }

    /** @return list<array<string, mixed>> */
    public function visibili(): array
    {
        return $this->db->righe('SELECT * FROM contatti_studenti WHERE visibile = 1 ORDER BY ordine, id');
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM contatti_studenti WHERE id = ?', [$id]);
    }

    /**
     * Crea (id 0) o aggiorna un contatto. Ritorna un messaggio d'errore, o '' se è andata bene.
     *
     * @param array<string, mixed> $d titolo, per_cosa, luogo, orari, email, telefono, link_etichetta, link_url, ordine, visibile
     */
    public function salva(int $id, array $d): string
    {
        $titolo = mb_substr(trim((string) ($d['titolo'] ?? '')), 0, 150);
        $email = mb_substr(trim((string) ($d['email'] ?? '')), 0, 150);
        $url = trim((string) ($d['link_url'] ?? ''));
        if ($titolo === '') {
            return 'Scrivi a chi ci si rivolge (per esempio «Segreteria didattica»).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'L\'indirizzo email non sembra corretto.';
        }
        if ($url !== '' && !preg_match('#^(https?://[^\s<>"\']+|[a-z0-9_][a-z0-9_\-./]*(\?[^\s<>"\']*)?)$#i', $url)) {
            return 'L\'indirizzo del collegamento non è valido (deve iniziare con https:// oppure essere una pagina del portale).';
        }
        $par = [
            $titolo, mb_substr(trim((string) ($d['per_cosa'] ?? '')), 0, 500), mb_substr(trim((string) ($d['luogo'] ?? '')), 0, 255),
            mb_substr(trim((string) ($d['orari'] ?? '')), 0, 255), $email, mb_substr(trim((string) ($d['telefono'] ?? '')), 0, 60),
            mb_substr(trim((string) ($d['link_etichetta'] ?? '')), 0, 100), mb_substr($url, 0, 500), (int) ($d['ordine'] ?? 0), !empty($d['visibile']) ? 1 : 0,
        ];
        if ($id > 0) {
            $this->db->esegui('UPDATE contatti_studenti SET titolo=?, per_cosa=?, luogo=?, orari=?, email=?, telefono=?, link_etichetta=?, link_url=?, ordine=?, visibile=? WHERE id=?', [...$par, $id]);
        } else {
            $this->db->inserisci('INSERT INTO contatti_studenti (titolo, per_cosa, luogo, orari, email, telefono, link_etichetta, link_url, ordine, visibile) VALUES (?,?,?,?,?,?,?,?,?,?)', $par);
        }

        return '';
    }

    public function mostra(int $id, bool $visibile): void
    {
        $this->db->esegui('UPDATE contatti_studenti SET visibile = ? WHERE id = ?', [$visibile ? 1 : 0, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM contatti_studenti WHERE id = ?', [$id]);
    }

    /** Inserisce le voci di esempio (nascoste), solo se la tabella è vuota. Ritorna quante ne ha inserite. */
    public function inserisciEsempi(): int
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM contatti_studenti') > 0) {
            return 0;
        }
        foreach (self::ESEMPI as $i => [$titolo, $perCosa]) {
            $this->db->inserisci('INSERT INTO contatti_studenti (titolo, per_cosa, ordine, visibile) VALUES (?, ?, ?, 0)', [$titolo, $perCosa, $i + 1]);
        }

        return count(self::ESEMPI);
    }
}
