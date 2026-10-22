<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Core\Database;

/** Impostazioni di Didacticus: acceso o spento, saluto, soglia di sicurezza e immagine di profilo (colonne didacticus_* di impostazioni_sistema). */
final class ImpostazioniDidacticus
{
    public const SOGLIA_MIN = 20;
    public const SOGLIA_MAX = 90;
    public const SOGLIA_PREDEFINITA = 60;
    public const SALUTO_PREDEFINITO = "Ciao, sono Didacticus, l'assistente virtuale del Dipartimento! Posso rispondere alle domande più comuni su tirocini, certificati, pratiche, tutorato, tesi ed eventi. Scrivimi cosa ti serve; se non riesco ad aiutarti ti metto subito in contatto con una persona dell'ufficio.";

    public function __construct(private Database $db)
    {
    }

    /** @return array{attivo: bool, saluto: string, soglia: int, avatar: string} */
    public function leggi(): array
    {
        $r = $this->db->riga('SELECT didacticus_attivo, didacticus_saluto, didacticus_soglia, didacticus_avatar FROM impostazioni_sistema WHERE id = 1') ?? [];
        $saluto = trim((string) ($r['didacticus_saluto'] ?? ''));

        return [
            'attivo' => !isset($r['didacticus_attivo']) || (int) $r['didacticus_attivo'] === 1,
            'saluto' => $saluto !== '' ? $saluto : self::SALUTO_PREDEFINITO,
            'soglia' => max(self::SOGLIA_MIN, min(self::SOGLIA_MAX, (int) ($r['didacticus_soglia'] ?? self::SOGLIA_PREDEFINITA) ?: self::SOGLIA_PREDEFINITA)),
            'avatar' => (string) ($r['didacticus_avatar'] ?? ''),
        ];
    }

    public function salva(bool $attivo, string $saluto, int $soglia): void
    {
        $saluto = trim($saluto);
        $this->db->esegui(
            'UPDATE impostazioni_sistema SET didacticus_attivo = ?, didacticus_saluto = ?, didacticus_soglia = ? WHERE id = 1',
            [$attivo ? 1 : 0, $saluto === self::SALUTO_PREDEFINITO ? '' : mb_substr($saluto, 0, 1000), max(self::SOGLIA_MIN, min(self::SOGLIA_MAX, $soglia))]
        );
    }

    /** Il nome del file dell'immagine di profilo caricata ('' = quella di serie). */
    public function impostaAvatar(string $file): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET didacticus_avatar = ? WHERE id = 1', [$file]);
    }
}
