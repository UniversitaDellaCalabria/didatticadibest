<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;
use App\Eventi\Righe;

/** Bandi di tutorato (tabella tutorato_bandi). */
final class BandoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM tutorato_bandi WHERE id = ?', [$id]);
    }

    /**
     * Tutti i bandi, dal più recente, con il numero di lettere e di lettere firmate (valori come testo).
     *
     * @return list<array<string, string|null>>
     */
    public function elenco(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT b.*, (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato <> 'annullata') AS n_incarichi,
                    (SELECT COUNT(*) FROM tutorato_incarichi i WHERE i.bando_id = b.id AND i.stato IN ('firmata', 'protocollata')) AS n_firmate
             FROM tutorato_bandi b ORDER BY b.creato_il DESC"
        ));
    }

    /** @param list<mixed> $v titolo, anno, decreto bando e data, decreto commissione e data, direttore (persona, nome, email, cf), operatore, luogo */
    public function aggiorna(int $id, array $v): void
    {
        $this->db->esegui(
            'UPDATE tutorato_bandi SET titolo=?, anno_accademico=?, decreto_bando=?, decreto_bando_data=?, decreto_commissione=?, decreto_commissione_data=?, direttore_persona_id=?, direttore_nome=?, direttore_email=?, direttore_cf=?, operatore_id=?, luogo=?, aggiornato_il=NOW() WHERE id=?',
            [...$v, $id]
        );
    }

    /** @param list<mixed> $v come aggiorna(), seguiti da chi lo crea */
    public function inserisci(array $v): int
    {
        return $this->db->inserisci(
            'INSERT INTO tutorato_bandi (titolo, anno_accademico, decreto_bando, decreto_bando_data, decreto_commissione, decreto_commissione_data, direttore_persona_id, direttore_nome, direttore_email, direttore_cf, operatore_id, luogo, creato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $v
        );
    }
}
