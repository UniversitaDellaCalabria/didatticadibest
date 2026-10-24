<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

use App\Assistenza\Costanti;
use App\Core\Database;
use App\Eventi\Righe;

/** La base di conoscenza di Didacticus (tabella didacticus_voci). */
final class VociRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function tutte(?string $argomento = null, string $cerca = ''): array
    {
        $dove = [];
        $par = [];
        if ($argomento !== null && $argomento !== '') {
            $dove[] = 'argomento = ?';
            $par[] = $argomento;
        }
        if (trim($cerca) !== '') {
            $dove[] = '(titolo LIKE ? OR domande LIKE ? OR parole_chiave LIKE ? OR risposta LIKE ?)';
            $l = '%' . trim($cerca) . '%';
            array_push($par, $l, $l, $l, $l);
        }

        return Righe::testo($this->db->righe('SELECT * FROM didacticus_voci' . ($dove ? ' WHERE ' . implode(' AND ', $dove) : '') . ' ORDER BY argomento, ordine, titolo', $par));
    }

    /** Le voci che Didacticus può usare: attive (e, per chi non ha il login, non riservate a chi accede). @return list<array<string, string|null>> */
    public function attive(bool $conLogin = true): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM didacticus_voci WHERE attiva = 1' . ($conLogin ? '' : ' AND solo_login = 0') . ' ORDER BY id'));
    }

    public function contaAttive(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM didacticus_voci WHERE attiva = 1');
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM didacticus_voci WHERE id = ?', [$id]));
    }

    /**
     * Crea o aggiorna una voce. $dati: argomento, titolo, domande, parole_chiave, risposta, link (lista di [etichetta, url]), allegati (lista di [nome, file]),
     * solo_login, proponi_operatore, attiva, ordine.
     *
     * @param array<string, mixed> $dati
     */
    public function salva(int $id, array $dati, string $quando): int
    {
        $valori = [
            (string) $dati['argomento'], mb_substr((string) $dati['titolo'], 0, 200), (string) $dati['domande'], (string) $dati['parole_chiave'], (string) $dati['risposta'],
            (string) json_encode($dati['link'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) json_encode($dati['allegati'], JSON_UNESCAPED_UNICODE),
            !empty($dati['solo_login']) ? 1 : 0, !empty($dati['proponi_operatore']) ? 1 : 0, !empty($dati['attiva']) ? 1 : 0, (int) ($dati['ordine'] ?? 0),
            implode(',', Costanti::pubblici((string) ($dati['pubblico'] ?? 'studenti'))),
        ];
        if ($id > 0) {
            $this->db->esegui(
                'UPDATE didacticus_voci SET argomento = ?, titolo = ?, domande = ?, parole_chiave = ?, risposta = ?, link_json = ?, allegati_json = ?, solo_login = ?, proponi_operatore = ?, attiva = ?, ordine = ?, pubblico = ?, aggiornato_il = ? WHERE id = ?',
                array_merge($valori, [$quando, $id])
            );

            return $id;
        }

        return $this->db->inserisci(
            'INSERT INTO didacticus_voci (argomento, titolo, domande, parole_chiave, risposta, link_json, allegati_json, solo_login, proponi_operatore, attiva, ordine, pubblico, creato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge($valori, [$quando])
        );
    }

    /**
     * Importa voci da un CSV con «;» (colonne: Argomento, Titolo, Domande, Parole chiave, Risposta, Link, Attiva, [Usi, Utile, Non utile], Propone operatore, Solo con accesso).
     * Le voci con lo stesso titolo si aggiornano (allegati, e link o flag non indicati, restano), le altre si aggiungono.
     *
     * @return array{0: int, 1: int, 2: int} nuove, aggiornate, saltate
     */
    public function importaCsv(string $testo, string $quando): array
    {
        $testo = ltrim($testo, "\xEF\xBB\xBF");
        if (!mb_check_encoding($testo, 'UTF-8')) {
            $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
        }
        $f = fopen('php://temp', 'r+');
        fwrite($f, $testo);
        rewind($f);
        $mappaArg = array_flip(array_map(fn ($a) => mb_strtolower($a['nome']), Costanti::argomenti()));
        $perTitolo = [];
        foreach ($this->tutte() as $v) {
            $perTitolo[mb_strtolower((string) $v['titolo'])] = $v;
        }
        [$nuove, $aggiornate, $saltate] = [0, 0, 0];
        $riga = 0;
        while (($c = fgetcsv($f, 0, ';', '"', '\\')) !== false && $riga < 500) {
            ++$riga;
            if (count($c) < 5 || ($riga === 1 && mb_strtolower(trim((string) $c[0])) === 'argomento')) {
                continue;
            }
            [$arg, $tit, $dom, $par, $ris] = [trim((string) $c[0]), trim((string) $c[1]), trim((string) $c[2]), trim((string) $c[3]), trim((string) $c[4])];
            if ($tit === '' || $ris === '') {
                ++$saltate;
                continue;
            }
            $arg = isset(Costanti::argomenti()[mb_strtolower($arg)]) ? mb_strtolower($arg) : ($mappaArg[mb_strtolower($arg)] ?? Costanti::ARGOMENTO_PREDEFINITO);
            $link = [];
            foreach (preg_split('/\R/u', (string) ($c[5] ?? '')) ?: [] as $rigaL) {
                [$e, $u] = array_pad(explode('|', $rigaL, 2), 2, '');
                $u = trim($u) !== '' ? trim($u) : trim($e);
                if (self::urlValido($u)) {
                    $link[] = ['e' => trim($e) !== '' && trim($u) !== trim($e) ? trim($e) : $u, 'u' => $u];
                }
            }
            $si = fn (int $i, int $predefinito): int => isset($c[$i]) && trim((string) $c[$i]) !== '' ? (in_array(mb_strtolower(trim((string) $c[$i])), ['sì', 'si', '1', 'x'], true) ? 1 : 0) : $predefinito;
            $esiste = $perTitolo[mb_strtolower($tit)] ?? null;
            $this->salva((int) ($esiste['id'] ?? 0), [
                'argomento' => $arg, 'titolo' => $tit, 'domande' => $dom, 'parole_chiave' => $par, 'risposta' => $ris, 'link' => $link ?: ($esiste ? self::link($esiste) : []),
                'allegati' => $esiste ? self::allegati($esiste) : [], 'solo_login' => $si(11, (int) ($esiste['solo_login'] ?? 0)), 'proponi_operatore' => $si(10, (int) ($esiste['proponi_operatore'] ?? 0)),
                'attiva' => !isset($c[6]) || mb_strtolower(trim((string) $c[6])) !== 'no', 'ordine' => (int) ($esiste['ordine'] ?? 0),
                'pubblico' => (string) ($esiste['pubblico'] ?? 'studenti'),
            ], $quando);
            $esiste ? ++$aggiornate : ++$nuove;
        }
        fclose($f);

        return [$nuove, $aggiornate, $saltate];
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM didacticus_voci WHERE id = ?', [$id]);
    }

    public function attiva(int $id, bool $attiva): void
    {
        $this->db->esegui('UPDATE didacticus_voci SET attiva = ? WHERE id = ?', [$attiva ? 1 : 0, $id]);
    }

    public function registraUso(int $id): void
    {
        $this->db->esegui('UPDATE didacticus_voci SET usi = usi + 1 WHERE id = ?', [$id]);
    }

    public function registraEsito(int $id, bool $utile): void
    {
        $this->db->esegui('UPDATE didacticus_voci SET ' . ($utile ? 'utile = utile + 1' : 'non_utile = non_utile + 1') . ' WHERE id = ?', [$id]);
    }

    /** I link di una voce: lista di ['e' => etichetta, 'u' => indirizzo]. @param array<string, string|null> $voce @return list<array{e: string, u: string}> */
    public static function link(array $voce): array
    {
        $out = [];
        foreach ((array) json_decode((string) ($voce['link_json'] ?? ''), true) as $l) {
            if (is_array($l) && self::urlValido((string) ($l['u'] ?? ''))) {
                $out[] = ['e' => mb_substr(trim((string) ($l['e'] ?? '')) ?: (string) $l['u'], 0, 100), 'u' => (string) $l['u']];
            }
        }

        return $out;
    }

    /** Gli allegati di una voce: lista di ['n' => nome da mostrare, 'f' => nome del file sul server]. @param array<string, string|null> $voce @return list<array{n: string, f: string}> */
    public static function allegati(array $voce): array
    {
        $out = [];
        foreach ((array) json_decode((string) ($voce['allegati_json'] ?? ''), true) as $a) {
            if (is_array($a) && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', (string) ($a['f'] ?? ''))) {
                $out[] = ['n' => mb_substr(trim((string) ($a['n'] ?? '')) ?: (string) $a['f'], 0, 120), 'f' => (string) $a['f']];
            }
        }

        return $out;
    }

    /** Un indirizzo che si può mettere in un link: https://…, http://… o una pagina del portale (percorso relativo, anche con ?parametri). */
    public static function urlValido(string $u): bool
    {
        $u = trim($u);

        return $u !== '' && strlen($u) <= 500 && !preg_match('/[\s<>"\']/', $u) && (preg_match('#^https?://[^/\s]+#i', $u) === 1 || preg_match('#^[a-z0-9_][a-z0-9_\-./]*(\?[^\s]*)?(\#[^\s]*)?$#i', $u) === 1);
    }
}
