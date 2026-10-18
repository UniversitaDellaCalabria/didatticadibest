<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;

/**
 * Gli organi di un corso di studi oltre al consiglio (commissione didattica, gruppo di riesame, riunioni con gli studenti…): non hanno sedute nel portale,
 * solo l'archivio dei verbali. Ogni organo (come ogni consiglio) ha una cartella dentro uploads/verbali/archivio/, sul NAS: i PDF che ci metti dentro
 * si leggono da soli (vedi ScansioneArchivio).
 */
final class OrganiArchivio
{
    /** tipo => [nome predefinito, cartella predefinita] */
    public const TIPI = [
        'commissione' => ['Commissione didattica', 'Commissione didattica'],
        'riesame'     => ['Gruppo di riesame – Assicurazione della Qualità', 'Gruppo di riesame'],
        'studenti'    => ['Riunione con gli studenti', 'Riunioni con gli studenti'],
        'altro'       => ['Altro organo', ''],
    ];

    public function __construct(private Database $db)
    {
    }

    /** La cartella come si scrive nel database: barre dritte, niente «..», niente barre ai bordi. */
    public static function cartella(string $c): string
    {
        $c = str_replace('\\', '/', trim($c));
        $pezzi = [];
        foreach (explode('/', $c) as $p) {
            $p = trim(preg_replace('/[\x00-\x1F]/u', '', $p) ?? '');
            if ($p === '' || $p === '.' || $p === '..') {
                continue;
            }
            $pezzi[] = $p;
        }

        return mb_substr(implode('/', $pezzi), 0, 300);
    }

    /** @return list<array<string, mixed>> */
    public function tutti(): array
    {
        return $this->db->righe('SELECT o.*, (SELECT COUNT(*) FROM didattica_sedute s WHERE s.organo_id = o.id) AS n_verbali FROM archivio_organi o ORDER BY o.corso, o.nome');
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM archivio_organi WHERE id = ?', [$id]);
    }

    /** Crea (id = 0) o aggiorna un organo. Ritorna il messaggio d'errore, null se va bene. */
    public function salva(int $id, string $nome, string $tipo, string $corso, string $cartella): ?string
    {
        $nome = mb_substr(trim($nome), 0, 300);
        $corso = mb_substr(trim($corso), 0, 300);
        $cartella = self::cartella($cartella);
        if ($nome === '') {
            return "Scrivi il nome dell'organo.";
        }
        if (!isset(self::TIPI[$tipo])) {
            $tipo = 'altro';
        }
        if ($cartella !== '' && ($altro = $this->proprietarioCartella($cartella, $id))) {
            return 'La cartella «' . $cartella . '» è già di «' . $altro . '».';
        }
        if ($id) {
            $this->db->esegui('UPDATE archivio_organi SET nome = ?, tipo = ?, corso = ?, cartella = ? WHERE id = ?', [$nome, $tipo, $corso, $cartella, $id]);
            $this->db->esegui('UPDATE didattica_sedute SET organo = ? WHERE organo_id = ?', [$nome, $id]);
        } else {
            $this->db->inserisci('INSERT INTO archivio_organi (nome, tipo, corso, cartella) VALUES (?, ?, ?, ?)', [$nome, $tipo, $corso, $cartella]);
        }

        return null;
    }

    /** Il nome dell'organo o del consiglio che ha già quella cartella (maiuscole ignorate), null se è libera. */
    public function proprietarioCartella(string $cartella, int $tranneOrgano = 0, int $tranneConsiglio = 0): ?string
    {
        $o = $this->db->riga('SELECT nome FROM archivio_organi WHERE LOWER(cartella) = LOWER(?) AND id <> ? LIMIT 1', [$cartella, $tranneOrgano]);
        if ($o) {
            return (string) $o['nome'];
        }
        $c = $this->db->riga('SELECT nome FROM didattica_consigli WHERE LOWER(cartella_archivio) = LOWER(?) AND id <> ? LIMIT 1', [$cartella, $tranneConsiglio]);

        return $c ? (string) $c['nome'] : null;
    }

    /** Imposta la cartella di un consiglio. Ritorna il messaggio d'errore, null se va bene. */
    public function cartellaConsiglio(int $consiglioId, string $cartella): ?string
    {
        $cartella = self::cartella($cartella);
        if ($cartella !== '' && ($altro = $this->proprietarioCartella($cartella, 0, $consiglioId))) {
            return 'La cartella «' . $cartella . '» è già di «' . $altro . '».';
        }
        $this->db->esegui('UPDATE didattica_consigli SET cartella_archivio = ? WHERE id = ?', [$cartella, $consiglioId]);

        return null;
    }

    /** Toglie un organo senza verbali; con verbali ritorna l'errore (si può disattivare la cartella svuotandola). */
    public function elimina(int $id): ?string
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM didattica_sedute WHERE organo_id = ?', [$id]) > 0) {
            return "L'organo ha dei verbali in archivio: eliminali prima, o tienilo (togliendo la cartella non viene più letta).";
        }
        $this->db->esegui('DELETE FROM archivio_organi WHERE id = ?', [$id]);

        return null;
    }

    /**
     * Crea i tre organi tipici di un corso (commissione didattica, gruppo di riesame, riunioni con gli studenti), con le cartelle «Corso/Organo».
     * Quelli già presenti per quel corso non si ripetono. Ritorna quanti ne ha creati.
     */
    public function creaStandard(string $corso, string $cartellaCorso): int
    {
        $corso = mb_substr(trim($corso), 0, 300);
        $base = self::cartella($cartellaCorso !== '' ? $cartellaCorso : $corso);
        if ($corso === '') {
            return 0;
        }
        $n = 0;
        foreach (['commissione', 'riesame', 'studenti'] as $tipo) {
            [$nome, $cart] = self::TIPI[$tipo];
            if ($this->db->valore('SELECT COUNT(*) FROM archivio_organi WHERE tipo = ? AND LOWER(corso) = LOWER(?)', [$tipo, $corso])) {
                continue;
            }
            $cartella = self::cartella($base . '/' . $cart);
            if ($this->proprietarioCartella($cartella) !== null) {
                $cartella = '';
            }
            $this->db->inserisci('INSERT INTO archivio_organi (nome, tipo, corso, cartella) VALUES (?, ?, ?, ?)', [$nome . ' – ' . $corso, $tipo, $corso, $cartella]);
            $n++;
        }

        return $n;
    }
}
