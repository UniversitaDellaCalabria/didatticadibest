<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Il «cervello» di Didacticus: confronta la domanda con le voci della base di conoscenza e dà a ognuna un punteggio da 0 a 100. Non indovina e non inventa:
 * calcola quanto la domanda somiglia alle domande e alle parole chiave scritte dal pannello, dando più peso alle parole rare («iscrizione» pesa più di
 * «certificato» se quest'ultima compare in dieci voci). Le parole si riducono a una radice (senza vocale finale, al massimo 5 lettere), così «certificato»,
 * «certificati» e «certificare» si riconoscono; i sinonimi si insegnano con le parole chiave della voce.
 */
final class Motore
{
    /** Parole che non dicono nulla sull'argomento (articoli, preposizioni, «vorrei sapere»…), già senza accenti. */
    private const PAROLE_VUOTE = [
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'e', 'ed', 'o', 'ma', 'che', 'non', 'mi', 'ti', 'ci', 'si', 'vi',
        'come', 'cosa', 'dove', 'quando', 'quale', 'quali', 'chi', 'perche', 'quanto', 'quanta', 'quanti', 'posso', 'devo', 'vorrei', 'voglio', 'fare', 'avere', 'essere', 'sono', 'ho', 'ha',
        'hai', 'puo', 'potrei', 'potete', 'puoi', 'questo', 'questa', 'quello', 'quella', 'del', 'dello', 'della', 'dei', 'degli', 'delle', 'al', 'allo', 'alla', 'ai', 'agli', 'alle',
        'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle', 'nel', 'nello', 'nella', 'nei', 'negli', 'nelle', 'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle', 'mio', 'mia', 'miei', 'mie',
        'tuo', 'tua', 'suo', 'sua', 'se', 'anche', 'piu', 'molto', 'poco', 'ancora', 'gia', 'qui', 'qua', 'li', 'la', 'informazioni', 'informazione', 'info', 'sapere', 'saper', 'dire',
        'dimmi', 'sapreste', 'saprei', 'bisogno', 'avrei', 'ciao', 'buongiorno', 'buonasera', 'salve', 'grazie', 'prego', 'scusa', 'scusi', 'per favore', 'favore', 'gentilmente', 'va',
        'fa', 'fanno', 'serve', 'servono', 'occorre', 'occorrono', 'necessario', 'necessita',
    ];

    /** Frasi di sola cortesia: Didacticus risponde con un saluto o un «prego» senza cercare nella base di conoscenza. */
    private const SALUTI = ['ciao', 'buongiorno', 'buonasera', 'buonpomeriggio', 'salve', 'hey', 'ehi', 'hello', 'buondi'];
    private const RINGRAZIAMENTI = ['grazie', 'grazie mille', 'ti ringrazio', 'vi ringrazio', 'perfetto grazie', 'ok grazie', 'ottimo grazie', 'molto gentile', 'gentilissimo'];

    /** @var list<array{voce: array<string, mixed>, varianti: list<list<string>>, chiavi: list<list<string>>, tutti: array<string, true>}> */
    private array $indice = [];
    /** @var array<string, float> peso (rarità) di ogni parola */
    private array $pesi = [];

    /** @param list<array<string, mixed>> $voci righe di didacticus_voci (solo quelle da considerare) */
    public function __construct(array $voci)
    {
        $df = [];
        foreach ($voci as $v) {
            $varianti = [];
            foreach (array_merge([(string) ($v['titolo'] ?? '')], self::righe((string) ($v['domande'] ?? ''))) as $d) {
                $t = self::parole($d);
                if ($t) {
                    $varianti[] = $t;
                }
            }
            $chiavi = [];
            foreach (self::righe((string) ($v['parole_chiave'] ?? '')) as $k) {
                $t = self::parole($k);
                if ($t) {
                    $chiavi[] = $t;
                }
            }
            $tutti = [];
            foreach (array_merge($varianti, $chiavi) as $lista) {
                foreach ($lista as $p) {
                    $tutti[$p] = true;
                }
            }
            foreach (array_keys($tutti) as $p) {
                $df[$p] = ($df[$p] ?? 0) + 1;
            }
            $this->indice[] = ['voce' => $v, 'varianti' => $varianti, 'chiavi' => $chiavi, 'tutti' => $tutti];
        }
        $n = count($voci);
        foreach ($df as $p => $c) {
            $this->pesi[$p] = 1.0 + log(($n + 1) / ($c + 1));
        }
    }

    /** @return list<string> le righe di un campo (una per riga, oppure separate da virgola o punto e virgola), senza vuote */
    public static function righe(string $testo): array
    {
        $out = [];
        foreach (preg_split('/[\r\n;]+|,(?=\s)|,$/u', $testo) ?: [] as $r) {
            $r = trim($r);
            if ($r !== '') {
                $out[] = $r;
            }
        }

        return $out;
    }

    /** Il testo in minuscolo, senza accenti né punteggiatura: solo lettere e numeri separati da spazi. */
    public static function normalizza(string $testo): string
    {
        $t = mb_strtolower($testo);
        $t = strtr($t, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u']);

        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $t));
    }

    /** La radice di una parola: senza vocale finale (se è lunga più di 3 lettere), al massimo 5 lettere. */
    public static function radice(string $parola): string
    {
        if (strlen($parola) > 3 && strpos('aeio', substr($parola, -1)) !== false) {
            $parola = substr($parola, 0, -1);
        }

        return substr($parola, 0, 5);
    }

    /** @return list<string> le radici delle parole che contano, senza doppioni */
    public static function parole(string $testo): array
    {
        $out = [];
        foreach (explode(' ', self::normalizza($testo)) as $p) {
            if ($p === '' || in_array($p, self::PAROLE_VUOTE, true)) {
                continue;
            }
            $out[self::radice($p)] = true;
        }

        return array_keys($out);
    }

    /** Il testo è solo un saluto («ciao», «buongiorno»…)? */
    public static function eSaluto(string $testo): bool
    {
        $t = self::normalizza($testo);

        return $t !== '' && count(array_diff(explode(' ', $t), self::SALUTI)) === 0;
    }

    /** Il testo è solo un ringraziamento? */
    public static function eRingraziamento(string $testo): bool
    {
        $t = self::normalizza($testo);

        return $t !== '' && (in_array($t, self::RINGRAZIAMENTI, true) || (str_contains($t, 'grazie') && strlen($t) < 30 && count(self::parole($t)) === 0));
    }

    /** La persona chiede di parlare con un operatore? */
    public static function chiedeOperatore(string $testo): bool
    {
        $t = ' ' . self::normalizza($testo) . ' ';

        return (bool) preg_match('/ (operator[ei]|operatrice|umano|persona vera|persona reale|persona fisica|qualcuno di vero|parlare con (una persona|un persona|qualcuno|un segretari\w*|una segretaria)|voglio parlare con|parlare con una persona|essere richiamat\w*) /', $t);
    }

    /**
     * Le voci in ordine di somiglianza con la domanda.
     *
     * @return list<array{voce: array<string, mixed>, punteggio: int, corrispondenze: list<string>}> solo quelle con almeno una parola in comune
     */
    public function classifica(string $domanda, ?string $argomento = null): array
    {
        $q = self::parole($domanda);
        if (!$q) {
            return [];
        }
        $peso = fn(string $p): float => $this->pesi[$p] ?? 1.0;
        $wQ = array_sum(array_map($peso, $q));
        $out = [];
        foreach ($this->indice as $i) {
            $comuni = array_values(array_intersect($q, array_keys($i['tutti'])));
            if (!$comuni) {
                continue;
            }
            $migliore = 0.0;
            foreach ($i['varianti'] as $v) {
                $m = array_intersect($q, $v);
                if (!$m) {
                    continue;
                }
                $wM = array_sum(array_map($peso, $m));
                $wV = array_sum(array_map($peso, $v));
                $migliore = max($migliore, 2 * $wM / ($wQ + $wV));
            }
            // parole chiave: se la domanda le contiene tutte (una frase intera) la voce sale subito di grado
            // quanto di più sale dipende da quanta parte della domanda è coperta dalla parola chiave: una sola parola generica («pratica») pesa poco
            $colpi = 0;
            $copertura = 0.0;
            foreach ($i['chiavi'] as $k) {
                if (!array_diff($k, $q)) {
                    ++$colpi;
                    $copertura = max($copertura, array_sum(array_map($peso, $k)) / $wQ);
                }
            }
            if ($colpi > 0) {
                $migliore = max($migliore, min(0.95, 0.5 + 0.3 * $copertura + 0.05 * ($colpi - 1)));
            }
            if ($argomento !== null && $argomento !== '' && $argomento === ($i['voce']['argomento'] ?? '')) {
                $migliore += 0.04;
            }
            $out[] = ['voce' => $i['voce'], 'punteggio' => (int) round(100 * min(1.0, $migliore)), 'corrispondenze' => $comuni];
        }
        usort($out, static fn (array $a, array $b): int => [$b['punteggio'], count($b['corrispondenze'])] <=> [$a['punteggio'], count($a['corrispondenze'])]);

        return $out;
    }
}
