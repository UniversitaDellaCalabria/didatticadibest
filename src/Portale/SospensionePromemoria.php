<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/**
 * Interruttore «Sospendi i promemoria al Direttore e agli uffici» (Gestione del portale → Sistema e registri): finché è attivo il portale non manda
 * il riepilogo settimanale «Da fare per te» al Direttore, al vice e alle persone degli uffici, né i promemoria delle pratiche ferme e delle firme del Direttore.
 * Non segna nulla come mandato: quando si riattiva, il primo passaggio del cron rimanda tutto quello che è ancora dovuto.
 * Non tocca le email di una richiesta (il link di firma, l'avviso di una pratica nuova) né i promemoria a docenti, studenti e scuole.
 */
final class SospensionePromemoria
{
    public function __construct(private Database $db)
    {
    }

    public function attiva(): bool
    {
        return (int) ($this->db->valore('SELECT promemoria_sospesi FROM impostazioni_sistema WHERE id = 1') ?? 0) === 1;
    }

    /** @return array{attiva: bool, da: string, il: ?string} */
    public function stato(): array
    {
        $r = $this->db->riga('SELECT promemoria_sospesi, promemoria_sospesi_da, promemoria_sospesi_il FROM impostazioni_sistema WHERE id = 1') ?: [];

        return ['attiva' => (int) ($r['promemoria_sospesi'] ?? 0) === 1, 'da' => (string) ($r['promemoria_sospesi_da'] ?? ''), 'il' => !empty($r['promemoria_sospesi_il']) ? (string) $r['promemoria_sospesi_il'] : null];
    }

    /** Sospende (true) o riattiva (false) i promemoria; $da = chi lo ha fatto. */
    public function imposta(bool $sospendi, string $da): void
    {
        $this->db->esegui('UPDATE impostazioni_sistema SET promemoria_sospesi = ?, promemoria_sospesi_da = ?, promemoria_sospesi_il = NOW() WHERE id = 1', [$sospendi ? 1 : 0, mb_substr($da, 0, 150)]);
    }

    /**
     * Email di chi non deve ricevere i promemoria mentre sono sospesi: il Direttore, il vice e le persone di tutti gli uffici.
     *
     * @return list<string>
     */
    public function esclusi(): array
    {
        $e = [];
        foreach ($this->db->righe("SELECT DISTINCT LOWER(email) AS email FROM uffici_persone WHERE email <> ''") as $r) {
            $e[] = (string) $r['email'];
        }

        return $e;
    }
}
