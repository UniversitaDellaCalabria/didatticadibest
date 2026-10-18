<?php

declare(strict_types=1);

namespace App\Didattica;

/**
 * Collegamento automatico delle cartelle dell'archivio agli organi. La struttura tipica è «Corso/Organo/anno/…»: la cartella del corso (per esempio
 * VERBALI_GEOLOGIA) contiene una sottocartella per organo (CONSIGLIO_CORSO_DI_STUDIO, COMMISSIONE_DIDATTICA, GRUPPO_RIESAME_AQ, RIUNIONE_STUDENTI_WELCOME_DAY…).
 * Il consiglio si abbina per nome a quello già nel portale; gli altri organi si creano nuovi, con il tipo capito dal nome della cartella e il corso del consiglio.
 * Si collega il secondo livello («Corso/Organo»): quello che sta più sotto (anni, cartelle di verbali con allegati) ne fa parte.
 */
final class ProposteCartelle
{
    public function __construct(private ScansioneArchivio $scansione, private ConsiglioRepository $consigli, private OrganiArchivio $organi)
    {
    }

    /** «VERBALI_BIOLOGIA_BIOTECNOLOGIE» → «Biologia biotecnologie». */
    public static function nomeCorso(string $cartella): string
    {
        $n = preg_replace('/^verbali[\s_\-]+/iu', '', $cartella) ?? $cartella;
        $n = trim(preg_replace('/\s+/u', ' ', str_replace(['_', '-'], ' ', $n)) ?? '');

        return mb_strtoupper(mb_substr($n, 0, 1)) . mb_strtolower(mb_substr($n, 1));
    }

    /**
     * Il consiglio che somiglia di più al nome di una cartella di corso (le prime lettere delle parole coincidono: «GEOLOGIA» ↔ «Geologiche»).
     *
     * @param array<int, array<string, mixed>> $consigli
     */
    public static function abbina(string $cartellaCorso, array $consigli): ?int
    {
        $parole = array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower(self::nomeCorso($cartellaCorso))) ?: [], static fn (string $p): bool => mb_strlen($p) >= 4);
        $migliore = null;
        $punti = 0;
        $pari = false;
        foreach ($consigli as $id => $c) {
            $nome = array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower((string) $c['nome'])) ?: [], static fn (string $p): bool => mb_strlen($p) >= 4);
            $s = 0;
            foreach ($parole as $p) {
                $radice = mb_substr($p, 0, min(6, mb_strlen($p)));
                foreach ($nome as $w) {
                    if (str_starts_with($w, $radice)) {
                        $s++;
                        break;
                    }
                }
            }
            if ($s > $punti) {
                $punti = $s;
                $migliore = (int) $id;
                $pari = false;
            } elseif ($s === $punti && $s > 0) {
                $pari = true;
            }
        }

        return $punti > 0 && !$pari ? $migliore : null;
    }

    /**
     * Cosa farebbe il collegamento automatico, per ogni cartella («Corso/Organo») non ancora assegnata.
     *
     * @return list<array{cartella: string, n_pdf: int, azione: string, consiglio_id: ?int, nome: string, tipo: string, corso: string}>
     */
    public function proponi(): array
    {
        $consigli = $this->consigli->tutti();
        $gruppi = [];
        foreach ($this->scansione->nonCollegate() as $cart => $n) {
            if ($cart === '') {
                continue;
            }
            $seg = explode('/', $cart);
            $chiave = count($seg) >= 2 ? $seg[0] . '/' . $seg[1] : $seg[0];
            $gruppi[$chiave] = ($gruppi[$chiave] ?? 0) + $n;
        }
        ksort($gruppi, SORT_NATURAL | SORT_FLAG_CASE);
        $usati = [];
        $out = [];
        foreach ($gruppi as $cart => $n) {
            $seg = explode('/', $cart);
            $corsoCartella = $seg[0];
            $organoCartella = $seg[1] ?? $seg[0];
            $idConsiglio = self::abbina($corsoCartella, $consigli);
            $corso = $idConsiglio ? (string) $consigli[$idConsiglio]['nome'] : self::nomeCorso($corsoCartella);
            $tipo = OrganiArchivio::indovinaTipo($organoCartella);
            if (count($seg) === 1 && $tipo === 'altro') {
                $tipo = 'consiglio'; // file direttamente nella cartella del corso: sono del consiglio
            }
            if ($tipo === 'consiglio' && $idConsiglio && empty($consigli[$idConsiglio]['cartella_archivio']) && !isset($usati[$idConsiglio])) {
                $usati[$idConsiglio] = true;
                $out[] = ['cartella' => $cart, 'n_pdf' => $n, 'azione' => 'consiglio', 'consiglio_id' => $idConsiglio, 'nome' => (string) $consigli[$idConsiglio]['nome'], 'tipo' => 'consiglio', 'corso' => $corso];
                continue;
            }
            $out[] = ['cartella' => $cart, 'n_pdf' => $n, 'azione' => 'nuovo', 'consiglio_id' => null, 'nome' => OrganiArchivio::nomeSuggerito($organoCartella, $tipo, $corso), 'tipo' => $tipo, 'corso' => $corso];
        }

        return $out;
    }

    /**
     * Applica le proposte. Ritorna quante cartelle ha collegato e gli eventuali problemi.
     *
     * @return array{collegate: int, problemi: list<string>}
     */
    public function applica(): array
    {
        $collegate = 0;
        $problemi = [];
        foreach ($this->proponi() as $p) {
            $err = $p['azione'] === 'consiglio'
                ? $this->organi->cartellaConsiglio((int) $p['consiglio_id'], $p['cartella'])
                : $this->organi->salva(0, $p['nome'], $p['tipo'], $p['corso'], $p['cartella']);
            if ($err === null) {
                $collegate++;
            } else {
                $problemi[] = $p['cartella'] . ': ' . $err;
            }
        }

        return ['collegate' => $collegate, 'problemi' => $problemi];
    }
}
