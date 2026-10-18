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
        'consiglio'   => ['Consiglio di corso di studi', 'Consiglio'],
        'commissione' => ['Commissione didattica', 'Commissione didattica'],
        'paritetica'  => ['Commissione paritetica', 'Commissione paritetica'],
        'riesame'     => ['Gruppo di riesame – Assicurazione della Qualità', 'Gruppo di riesame'],
        'studenti'    => ['Riunione con gli studenti', 'Riunioni con gli studenti'],
        'altro'       => ['Altro organo', ''],
    ];

    public function __construct(private Database $db)
    {
    }

    /** Il tipo che somiglia di più al nome di una cartella («COMMISSIONE_DIDATTICA» → commissione, «Gruppo di riesame» → riesame…). */
    public static function indovinaTipo(string $nomeCartella): string
    {
        $n = mb_strtolower(str_replace(['_', '-', '.'], ' ', $nomeCartella));
        return match (true) {
            str_contains($n, 'paritetica') => 'paritetica',
            str_contains($n, 'riesame') || str_contains($n, 'assicurazione') || (bool) preg_match('/\\baq\\b/u', $n) => 'riesame',
            str_contains($n, 'welcome') || str_contains($n, 'student') || str_contains($n, 'incontr') => 'studenti',
            str_contains($n, 'commission') => 'commissione',
            str_contains($n, 'consiglio') || (bool) preg_match('/\\bc[dl]s\\b|\\bcdl\\b/u', $n) => 'consiglio',
            default => 'altro',
        };
    }

    /** Il nome proposto per un organo nuovo: «Commissione didattica – Corso di Biologia» (o il nome della cartella, bello da leggere). */
    public static function nomeSuggerito(string $nomeCartella, string $tipo, string $corso): string
    {
        $bello = trim(preg_replace('/\\s+/u', ' ', str_replace(['_', '-'], ' ', $nomeCartella)) ?? '');
        $bello = mb_strtoupper(mb_substr($bello, 0, 1)) . mb_strtolower(mb_substr($bello, 1));
        $base = isset(self::TIPI[$tipo]) && $tipo !== 'altro' ? self::TIPI[$tipo][0] : $bello;

        return $corso !== '' ? $base . ' – ' . $corso : $base;
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
    public function salva(int $id, string $nome, string $tipo, string $corso, string $cartella, string $tipoNome = ''): ?string
    {
        $tipoNome = mb_substr(trim($tipoNome), 0, 100);
        $nome = mb_substr(trim($nome), 0, 300);
        $corso = mb_substr(trim($corso), 0, 300);
        $cartella = self::cartella($cartella);
        if ($nome === '') {
            return "Scrivi il nome dell'organo.";
        }
        if (!isset(self::TIPI[$tipo])) {
            $tipo = 'altro';
        }
        if ($tipo !== 'altro') {
            $tipoNome = '';
        }
        if ($cartella !== '' && ($altro = $this->proprietarioCartella($cartella, $id))) {
            return 'La cartella «' . $cartella . '» è già di «' . $altro . '».';
        }
        if ($id) {
            $this->db->esegui('UPDATE archivio_organi SET nome = ?, tipo = ?, tipo_nome = ?, corso = ?, cartella = ? WHERE id = ?', [$nome, $tipo, $tipoNome, $corso, $cartella, $id]);
            $this->db->esegui('UPDATE didattica_sedute SET organo = ? WHERE organo_id = ?', [$nome, $id]);
        } else {
            $this->db->inserisci('INSERT INTO archivio_organi (nome, tipo, tipo_nome, corso, cartella) VALUES (?, ?, ?, ?, ?)', [$nome, $tipo, $tipoNome, $corso, $cartella]);
        }

        return null;
    }

    /**
     * Le scelte del campo «Tipo»: i tipi predefiniti e quelli scritti a mano e già usati. Il valore è la chiave del tipo predefinito oppure «x:Nome».
     *
     * @return array<string, string> valore => etichetta
     */
    public function tipiDisponibili(): array
    {
        $out = [];
        foreach (self::TIPI as $k => $t) {
            $out[$k] = $t[0];
        }
        foreach ($this->db->righe("SELECT DISTINCT tipo_nome FROM archivio_organi WHERE tipo = 'altro' AND tipo_nome <> '' ORDER BY tipo_nome") as $r) {
            $out['x:' . $r['tipo_nome']] = (string) $r['tipo_nome'];
        }

        return $out;
    }

    /**
     * Dalla scelta nel modulo («consiglio», «x:Nome» o «@nuovo» con il nome scritto a parte) al tipo da salvare.
     *
     * @return array{0: string, 1: string} [chiave del tipo, nome scritto a mano o '']
     */
    public static function risolviTipo(string $scelta, string $nuovo = ''): array
    {
        if (isset(self::TIPI[$scelta])) {
            return [$scelta, ''];
        }
        if ($scelta === '@nuovo') {
            return ['altro', mb_substr(trim($nuovo), 0, 100)];
        }
        if (str_starts_with($scelta, 'x:')) {
            return ['altro', mb_substr(trim(substr($scelta, 2)), 0, 100)];
        }

        return ['altro', ''];
    }

    /** Il valore da selezionare nel campo «Tipo» per un organo. @param array<string, mixed> $o */
    public static function valoreScelta(array $o): string
    {
        $tipo = (string) ($o['tipo'] ?? 'altro');
        if ($tipo === 'altro' && (string) ($o['tipo_nome'] ?? '') !== '') {
            return 'x:' . $o['tipo_nome'];
        }

        return isset(self::TIPI[$tipo]) ? $tipo : 'altro';
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
