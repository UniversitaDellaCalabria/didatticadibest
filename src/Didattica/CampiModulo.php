<?php

declare(strict_types=1);

namespace App\Didattica;

/** I campi di un modulo online letti dal JSON del costruttore: tipi, colonne delle tabelle, condizioni e colonna del piano di studi. */
final class CampiModulo
{
    /**
     * Campi normalizzati: ['nome', 'etichetta', 'tipo', 'opzioni' => [...], 'obbligatorio', 'aiuto', 'ufficio',
     * 'colonne' => [['nome', 'tipo', 'scelte']] (tabelle), 'cond' => [campo, nome, op, valore] | null, 'auto' => [campo, nome, op, valore, imposta] | null].
     * «ufficio» = lo compila l'ufficio nell'istruttoria (non compare allo studente). Per «tabella» le opzioni sono i nomi delle colonne
     * (scritte «Nome:tipo», es. «Insegnamento:insegnamento, CFU:cfu, Voto:voto»). «cond» = il campo si mostra solo se la condizione
     * sull'altro campo è vera; «auto» = se la condizione è vera il campo si compila da solo con «imposta».
     *
     * @return list<array<string, mixed>>
     */
    public static function da(?string $json): array
    {
        $out = [];
        $grezzi = json_decode((string) $json, true) ?: [];
        foreach ($grezzi as $i => $c) {
            $tipo = isset(Costanti::TIPI_CAMPO_PRATICA[$c['tipo'] ?? '']) ? $c['tipo'] : 'text';
            $et = mb_substr(trim((string) ($c['etichetta'] ?? '')), 0, 200);
            if ($et === '') {
                continue;
            }
            $opzRaw = is_array($c['opzioni'] ?? null) ? $c['opzioni'] : preg_split('/[,;\n]/', (string) ($c['opzioni'] ?? ''));
            $opz = array_values(array_filter(array_map('trim', (array) $opzRaw), static fn (string $o): bool => $o !== ''));
            $x = ['nome' => 'c' . ($i + 1), 'etichetta' => $et, 'tipo' => $tipo, 'obbligatorio' => !empty($c['obbligatorio']) && !in_array($tipo, Costanti::TIPI_SOLO_TESTO, true),
                  'opzioni' => $opz, 'aiuto' => mb_substr(trim((string) ($c['aiuto'] ?? '')), 0, $tipo === 'info' || $tipo === 'dichiarazione' ? 3000 : 300), 'ufficio' => !empty($c['ufficio']),
                  'colonne' => [], 'cond' => null, 'auto' => null];
            if ($tipo === 'tabella') {
                $x['colonne'] = self::colonne($opz ?: ['Descrizione']);
                $x['opzioni'] = array_column($x['colonne'], 'nome');
            }
            foreach (['cond', 'auto'] as $k) {
                $r = $c[$k] ?? null;
                if (!is_array($r) || trim((string) ($r['campo'] ?? '')) === '' || !isset(Costanti::OPERATORI_CONDIZIONE[$r['op'] ?? ''])) {
                    continue;
                }
                $x[$k] = ['campo' => mb_substr(trim((string) $r['campo']), 0, 200), 'nome' => '', 'op' => $r['op'], 'valore' => mb_substr(trim((string) ($r['valore'] ?? '')), 0, 300)]
                       + ($k === 'auto' ? ['imposta' => mb_substr(trim((string) ($r['imposta'] ?? '')), 0, 500)] : []);
            }
            $out[] = $x;
        }
        // Le condizioni indicano l'altro campo con la sua domanda: si risolve il nome (c1, c2…); se manca la condizione si toglie
        $perEt = [];
        foreach ($out as $x) {
            $perEt[mb_strtolower($x['etichetta'])] = $x['nome'];
        }
        foreach ($out as &$x) {
            foreach (['cond', 'auto'] as $k) {
                if (!$x[$k]) {
                    continue;
                }
                $n = $perEt[mb_strtolower($x[$k]['campo'])] ?? '';
                if ($n === '' || $n === $x['nome']) {
                    $x[$k] = null;
                } else {
                    $x[$k]['nome'] = $n;
                }
            }
        }
        unset($x);

        return $out;
    }

    /**
     * Colonne di una tabella a righe da «Nome:tipo» (tipo facoltativo: si riconosce dal nome). «Esito:scelta(Sì|No)» = tendina.
     *
     * @param list<string> $spec
     * @return list<array{nome: string, tipo: string, scelte: list<string>}>
     */
    public static function colonne(array $spec): array
    {
        $out = [];
        foreach ($spec as $s) {
            $s = trim((string) $s);
            $tipo = '';
            $scelte = [];
            if (preg_match('/^(.*?):\s*([a-z_]+)(?:\((.*)\))?\s*$/u', $s, $m) && isset(Costanti::TIPI_COLONNA_TABELLA[$m[2]])) {
                $s = trim($m[1]);
                $tipo = $m[2];
                if ($tipo === 'scelta') {
                    $scelte = array_values(array_filter(array_map('trim', explode('|', (string) ($m[3] ?? ''))), static fn (string $o): bool => $o !== ''));
                }
            }
            if ($s === '') {
                continue;
            }
            if ($tipo === '') {
                $tipo = self::tipoColonnaDaNome($s);
            }
            $out[] = ['nome' => mb_substr($s, 0, 100), 'tipo' => $tipo, 'scelte' => $scelte];
        }

        return $out ?: [['nome' => 'Descrizione', 'tipo' => 'testo', 'scelte' => []]];
    }

    /** Colonne scritte prima dei tipi: «Insegnamento», «CFU», «Voto», «Data», «S.S.D.», «Relatore»… */
    public static function tipoColonnaDaNome(string $n): string
    {
        $n = mb_strtolower($n);
        if (preg_match('/^(insegnament|esam)/u', $n)) {
            return 'insegnamento';
        }
        if (preg_match('/docente|relatore|tutor/u', $n)) {
            return 'docente';
        }
        if (preg_match('/^(tot\.? )?cfu|crediti/u', $n) && !preg_match('/integrar|ricon|convalid|da /u', $n)) {
            return 'cfu';
        }
        if (preg_match('/^voto/u', $n)) {
            return 'voto';
        }
        if (preg_match('/^data$/u', $n)) {
            return 'data';
        }
        if (preg_match('/^s\.?\s?s\.?\s?d\.?$/u', $n)) {
            return 'ssd';
        }
        if (preg_match('/^denominazion/u', $n)) {
            return 'denominazione';
        }
        if (preg_match('/^codice/u', $n)) {
            return 'codice';
        }
        if (preg_match('/piano/u', $n)) {
            return 'piano';
        }

        return 'testo';
    }

    /**
     * Colonne di nuovo in testo per il costruttore: «Nome:tipo».
     *
     * @param list<array{nome: string, tipo: string, scelte: list<string>}> $colonne
     */
    public static function testoColonne(array $colonne): string
    {
        return implode(', ', array_map(fn ($c) => $c['nome'] . ($c['tipo'] !== 'testo' ? ':' . $c['tipo'] . ($c['tipo'] === 'scelta' ? '(' . implode('|', $c['scelte']) . ')' : '') : ''), $colonne));
    }

    /**
     * Valuta una condizione ('op', 'valore') sul valore di un altro campo (testo; le scelte multiple sono separate da virgola).
     *
     * @param array<string, mixed> $cond
     */
    public static function condizioneVera(array $cond, string $valore): bool
    {
        $v = mb_strtolower(trim($valore));
        $att = mb_strtolower(trim((string) $cond['valore']));

        return match ($cond['op']) {
            'uguale' => $v === $att || in_array($att, array_map('trim', explode(',', $v)), true),
            'diverso' => !($v === $att || in_array($att, array_map('trim', explode(',', $v)), true)),
            'contiene' => $att !== '' && mb_strpos($v, $att) !== false,
            'compilato' => $v !== '',
            'vuoto' => $v === '',
            default => true,
        };
    }

    /**
     * @param list<array<string, mixed>> $campi
     * @return list<array<string, mixed>> i campi che compila lo studente
     */
    public static function studente(array $campi): array
    {
        return array_values(array_filter($campi, fn ($c) => !$c['ufficio']));
    }

    /**
     * @param list<array<string, mixed>> $campi
     * @return list<array<string, mixed>> i campi che compila l'ufficio (non gli allegati né il solo testo)
     */
    public static function ufficio(array $campi): array
    {
        return array_values(array_filter($campi, fn ($c) => $c['ufficio'] && !in_array($c['tipo'], ['file', 'titolo', 'info'], true)));
    }

    /** Colonna «piano di studi»: [da inserire come a scelta?, insegnamento del piano da eliminare]. */
    public static function valorePiano(string $v): array
    {
        if (!preg_match('/^A scelta(?:;\s*elimina:\s*(.*))?$/su', trim($v), $m)) {
            return [false, ''];
        }

        return [true, mb_substr(trim((string) ($m[1] ?? '')), 0, 250)];
    }
}
