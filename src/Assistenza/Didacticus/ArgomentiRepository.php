<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Assistenza\Costanti;
use App\Core\Database;

/** Gli argomenti delle domande frequenti e della chat (tabella didacticus_argomenti), modificabili dal pannello. */
final class ArgomentiRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Gli argomenti in ordine; la prima volta la tabella si riempie con quelli di serie. @return array<string, array{nome: string, icona: string, aiuto: string}> */
    public function elenco(): array
    {
        $righe = $this->db->righe('SELECT chiave, nome, icona, aiuto FROM didacticus_argomenti ORDER BY ordine, nome');
        if (!$righe) {
            $i = 0;
            foreach (Costanti::ARGOMENTI as $k => $a) {
                $this->db->esegui('INSERT IGNORE INTO didacticus_argomenti (chiave, nome, icona, aiuto, ordine) VALUES (?, ?, ?, ?, ?)', [$k, $a['nome'], $a['icona'], $a['aiuto'], ++$i]);
            }
            $righe = $this->db->righe('SELECT chiave, nome, icona, aiuto FROM didacticus_argomenti ORDER BY ordine, nome');
        }
        $out = [];
        foreach ($righe as $r) {
            $out[(string) $r['chiave']] = ['nome' => (string) $r['nome'], 'icona' => (string) $r['icona'], 'aiuto' => (string) $r['aiuto']];
        }

        return $out;
    }

    /** Quante voci usano ogni argomento. @return array<string, int> */
    public function usi(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT argomento, COUNT(*) n FROM didacticus_voci GROUP BY argomento') as $r) {
            $out[(string) $r['argomento']] = (int) $r['n'];
        }

        return $out;
    }

    /** Crea (chiave vuota) o aggiorna un argomento. Ritorna un messaggio d'errore, o '' se è andata bene. */
    public function salva(string $chiave, string $nome, string $icona, string $aiuto, int $ordine): string
    {
        $nome = mb_substr(trim($nome), 0, 120);
        if ($nome === '') {
            return 'Scrivi il nome dell\'argomento.';
        }
        $icona = preg_match('/^fa-[a-z0-9-]{2,36}$/', trim($icona)) ? trim($icona) : 'fa-circle-question';
        $aiuto = mb_substr(trim($aiuto), 0, 255);
        if ($chiave === '') {
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(strtr($nome, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']))), '_');
            $base = substr($base !== '' ? $base : 'argomento', 0, 24);
            $chiave = $base;
            for ($n = 2; (int) $this->db->valore('SELECT COUNT(*) FROM didacticus_argomenti WHERE chiave = ?', [$chiave]) > 0; ++$n) {
                $chiave = $base . '_' . $n;
            }
            $this->db->esegui('INSERT INTO didacticus_argomenti (chiave, nome, icona, aiuto, ordine) VALUES (?, ?, ?, ?, ?)', [$chiave, $nome, $icona, $aiuto, $ordine]);
        } else {
            $this->db->esegui('UPDATE didacticus_argomenti SET nome = ?, icona = ?, aiuto = ?, ordine = ? WHERE chiave = ?', [$nome, $icona, $aiuto, $ordine, $chiave]);
        }
        Costanti::dimentica();

        return '';
    }

    /** Elimina un argomento: le sue voci passano a «Altro». «Altro» non si elimina. */
    public function elimina(string $chiave): bool
    {
        if ($chiave === Costanti::ARGOMENTO_PREDEFINITO) {
            return false;
        }
        $this->db->esegui('UPDATE didacticus_voci SET argomento = ? WHERE argomento = ?', [Costanti::ARGOMENTO_PREDEFINITO, $chiave]);
        $this->db->esegui('DELETE FROM didacticus_argomenti WHERE chiave = ?', [$chiave]);
        Costanti::dimentica();

        return true;
    }
}
