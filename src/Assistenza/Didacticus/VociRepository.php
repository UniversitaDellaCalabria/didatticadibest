<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

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
        ];
        if ($id > 0) {
            $this->db->esegui(
                'UPDATE didacticus_voci SET argomento = ?, titolo = ?, domande = ?, parole_chiave = ?, risposta = ?, link_json = ?, allegati_json = ?, solo_login = ?, proponi_operatore = ?, attiva = ?, ordine = ?, aggiornato_il = ? WHERE id = ?',
                array_merge($valori, [$quando, $id])
            );

            return $id;
        }

        return $this->db->inserisci(
            'INSERT INTO didacticus_voci (argomento, titolo, domande, parole_chiave, risposta, link_json, allegati_json, solo_login, proponi_operatore, attiva, ordine, creato_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge($valori, [$quando])
        );
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
