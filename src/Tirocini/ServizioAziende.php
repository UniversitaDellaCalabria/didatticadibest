<?php

declare(strict_types=1);

namespace App\Tirocini;

use App\Core\Database;
use App\Core\Esito;

/**
 * Aziende convenzionate per i tirocini curriculari: il report dell'Albo aziende del TSP (Cineca) si carica dal pannello, resta
 * solo chi ha la «x» in «Esiste convenzione attiva»; l'indirizzo non c'è nel report e si scrive a mano (o con un secondo file).
 * Pubblica solo l'azienda con convenzione attiva, comune e provincia, spunta «pubblica» e, se l'indirizzo è stato trovato sul web, conferma.
 */
final class ServizioAziende
{
    public const ORIGINE_MANUALE = 'manuale';
    public const ORIGINE_WEB = 'web';

    /** Le aziende inserite a mano non hanno un codice del TSP: ne ricevono uno che comincia così (MAN-1, MAN-2…) */
    public const PREFISSO_MANUALE = 'MAN-';

    /** Parole (e loro radici) che indicano un'organizzazione: senza, un nome di 2-4 parole è con ogni probabilità una persona (ditta individuale, professionista) */
    private const PAROLE_ORGANIZZAZIONE = '/\b(?:s\.?r\.?l|s\.?p\.?a|s\.?a\.?s|s\.?n\.?c|soc|societ|asd|a\.s\.d|aps|ets|odv|onlus|ass|associazione|comune|provincia|regione|ente|parco|universit|istituto|azienda|cooperativa|coop|fondazione|consorzio|studio|centro|laborator|clinic|ospedal|asp|agenzia|arpa|camera|museo|biblioteca|club|football|gruppo|group|italia|calabria|cosenza|service|servizi|ditta|farmaci|parafarmaci|hotel|impresa|officina|parrocchia|proloco|pro loco|sport|team|center|lab|cnr|inrca|irccs|stazione|zoolog|ricerca|research|veterinar|medic|analisi|gym|fitness|industry|industrie|consulting|engineering|energy|ambiente|ambiental)\w*/iu';

    public function __construct(private Database $db)
    {
    }

    /**
     * Carica il report del TSP (o il file con gli indirizzi: stessa colonna «Codice azienda»). Chi ha la «x» si aggiunge o si aggiorna,
     * chi non l'ha più perde la convenzione ma conserva l'indirizzo.
     *
     * @param list<array<string, string>> $righe
     * @return array{nuove: int, aggiornate: int, senza_convenzione: int, indirizzi: int, siti: int, scartate: int}
     */
    public function importa(array $righe): array
    {
        $r = ['nuove' => 0, 'aggiornate' => 0, 'senza_convenzione' => 0, 'indirizzi' => 0, 'siti' => 0, 'scartate' => 0];
        $this->db->transazione(function () use ($righe, &$r): void {
            foreach ($righe as $riga) {
                $cod = $this->cella($riga, ['codice azienda', 'codice tsp', 'codice']);
                if ($cod === '') {
                    $r['scartate']++;
                    continue;
                }
                $esiste = $this->db->riga('SELECT id, origine, comune FROM tirocini_aziende WHERE cod_tsp = ?', [$cod]);
                if ($this->haColonna($riga, ['esiste convenzione attiva'])) {
                    $attiva = mb_strtolower($this->cella($riga, ['esiste convenzione attiva'])) === 'x';
                    if (!$attiva) {
                        if ($esiste) {
                            $this->db->esegui('UPDATE tirocini_aziende SET convenzione = 0, modificata_il = NOW() WHERE id = ?', [$esiste['id']]);
                            $r['senza_convenzione']++;
                        }
                        continue;
                    }
                    $nome = $this->cella($riga, ['descrizione', 'nome', 'denominazione']);
                    $cf = $this->cella($riga, ['codice fiscale']);
                    $piva = $this->cella($riga, ['partita iva']);
                    $settore = self::maiuscolaIniziale($this->cella($riga, ['settore ateco', 'settore']));
                    if ($esiste) {
                        $this->db->esegui(
                            "UPDATE tirocini_aziende SET nome = IF(? <> '', ?, nome), codice_fiscale = ?, partita_iva = ?, settore = IF(? <> '', ?, settore), convenzione = 1, modificata_il = NOW() WHERE id = ?",
                            [$nome, $nome, $cf, $piva, $settore, $settore, $esiste['id']]
                        );
                        $r['aggiornate']++;
                    } else {
                        $this->db->esegui(
                            'INSERT INTO tirocini_aziende (cod_tsp, nome, codice_fiscale, partita_iva, settore, convenzione, pubblica) VALUES (?, ?, ?, ?, ?, 1, ?)',
                            [$cod, mb_substr($nome, 0, 255), $cf, $piva, mb_substr($settore, 0, 255), self::sembraPersona($nome, $cf) ? 0 : 1]
                        );
                        $r['nuove']++;
                        $esiste = $this->db->riga('SELECT id, origine, comune FROM tirocini_aziende WHERE cod_tsp = ?', [$cod]);
                    }
                }
                // Indirizzo nel file (secondo caricamento, compilato a mano o in blocco)
                if ($esiste && $this->haColonna($riga, ['indirizzo', 'comune', 'provincia'])) {
                    $prov = Province::sigla($this->cella($riga, ['provincia']));
                    $comune = $this->cella($riga, ['comune', 'città', 'citta']);
                    $web = mb_strtolower($this->cella($riga, ['origine'])) === self::ORIGINE_WEB;
                    $giaScritto = $esiste['comune'] !== '' && $esiste['origine'] === self::ORIGINE_MANUALE;
                    if (($comune !== '' || $this->cella($riga, ['indirizzo']) !== '') && !($web && $giaScritto)) {
                        // Colonna «Origine» = web: indirizzo cercato in rete, da confermare prima di pubblicarlo (non sostituisce uno scritto a mano)
                        $this->salvaIndirizzo((int) $esiste['id'], $this->cella($riga, ['indirizzo']), $this->cella($riga, ['cap']), $comune, $prov, $web ? self::ORIGINE_WEB : self::ORIGINE_MANUALE, !$web);
                        $r['indirizzi']++;
                    }
                }
                // Sito web nel file: si scrive solo dove manca (non cancella né sostituisce uno scritto a mano)
                $sito = $this->cella($riga, ['sito web', 'sito']);
                if ($esiste && $sito !== '') {
                    $sito = preg_match('#^https?://#i', $sito) ? $sito : 'https://' . $sito;
                    if (filter_var($sito, FILTER_VALIDATE_URL)) {
                        if ($this->db->esegui("UPDATE tirocini_aziende SET sito = ? WHERE id = ? AND sito = ''", [mb_substr($sito, 0, 255), $esiste['id']]) > 0) {
                            $r['siti']++;
                        }
                    }
                }
            }
        });

        return $r;
    }

    /** Scrive l'indirizzo; provincia e regione vanno insieme. Un indirizzo scritto a mano è subito confermato. */
    public function salvaIndirizzo(int $id, string $indirizzo, string $cap, string $comune, string $provincia, string $origine, bool $verificato): void
    {
        $prov = Province::sigla($provincia);
        $this->db->esegui(
            'UPDATE tirocini_aziende SET indirizzo = ?, cap = ?, comune = ?, provincia = ?, regione = ?, origine = ?, verificato = ?, modificata_il = NOW() WHERE id = ?',
            [mb_substr(trim($indirizzo), 0, 255), mb_substr(trim($cap), 0, 10), mb_substr(trim($comune), 0, 100), $prov, Province::regione($prov), $origine, $verificato ? 1 : 0, $id]
        );
    }

    /** Dati scritti dal pannello per una riga: indirizzo, sito, pubblica, nota */
    public function salvaScheda(int $id, string $indirizzo, string $cap, string $comune, string $provincia, string $sito, bool $pubblica, string $settore): ?string
    {
        if ($provincia !== '' && Province::sigla($provincia) === '') {
            return 'Scegli la provincia dall\'elenco.';
        }
        $sito = self::normalizzaSito($sito);
        if ($sito === null) {
            return 'Il sito web non è un indirizzo valido.';
        }
        $this->salvaIndirizzo($id, $indirizzo, $cap, $comune, $provincia, self::ORIGINE_MANUALE, true);
        $this->db->esegui('UPDATE tirocini_aziende SET sito = ?, pubblica = ?, settore = ? WHERE id = ?', [mb_substr($sito, 0, 255), $pubblica ? 1 : 0, mb_substr(trim($settore), 0, 255), $id]);

        return null;
    }

    /**
     * Aggiunge a mano un'azienda con convenzione attiva che non è nel report del TSP. Si pubblica se ha comune e provincia e la si lascia
     * pubblica. Non si accettano doppioni (stessa partita IVA, stesso codice fiscale o stesso nome).
     */
    public function creaManuale(string $nome, string $partitaIva, string $codiceFiscale, string $settore, string $indirizzo, string $cap, string $comune, string $provincia, string $sito, bool $pubblica): Esito
    {
        $nome = trim($nome);
        $piva = preg_replace('/\s+/', '', $partitaIva) ?? '';
        $cf = strtoupper(preg_replace('/\s+/', '', $codiceFiscale) ?? '');
        if ($nome === '') {
            return Esito::errore('Scrivi il nome dell\'azienda.');
        }
        if ($provincia !== '' && Province::sigla($provincia) === '') {
            return Esito::errore('Scegli la provincia dall\'elenco.');
        }
        $sitoOk = self::normalizzaSito($sito);
        if ($sitoOk === null) {
            return Esito::errore('Il sito web non è un indirizzo valido.');
        }
        $doppione = $this->db->riga(
            'SELECT nome FROM tirocini_aziende WHERE LOWER(nome) = LOWER(?) OR (? <> \'\' AND partita_iva = ?) OR (? <> \'\' AND codice_fiscale = ?) LIMIT 1',
            [$nome, $piva, $piva, $cf, $cf]
        );
        if ($doppione) {
            return Esito::errore('«' . $doppione['nome'] . '» è già nell\'elenco: cercala e modificala da lì.');
        }
        $n = (int) $this->db->valore("SELECT MAX(CAST(SUBSTRING(cod_tsp, " . (strlen(self::PREFISSO_MANUALE) + 1) . ") AS UNSIGNED)) FROM tirocini_aziende WHERE cod_tsp LIKE ?", [self::PREFISSO_MANUALE . '%']);
        $id = $this->db->inserisci(
            'INSERT INTO tirocini_aziende (cod_tsp, nome, codice_fiscale, partita_iva, settore, convenzione, sito, pubblica) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [self::PREFISSO_MANUALE . ($n + 1), mb_substr($nome, 0, 255), mb_substr($cf, 0, 20), mb_substr($piva, 0, 20), mb_substr(self::conIniziale($settore), 0, 255), mb_substr($sitoOk, 0, 255), $pubblica ? 1 : 0]
        );
        if ($id <= 0) {
            return Esito::errore('Azienda non salvata: riprova.');
        }
        if (trim($comune) !== '' || trim($indirizzo) !== '') {
            $this->salvaIndirizzo($id, $indirizzo, $cap, $comune, $provincia, self::ORIGINE_MANUALE, true);
        }

        return Esito::ok((string) $id);
    }

    /** Ragione sociale, partita IVA e codice fiscale: si cambiano solo per le aziende inserite a mano (quelle del TSP si aggiornano dal report). */
    public function salvaAnagrafica(int $id, string $nome, string $partitaIva, string $codiceFiscale): ?string
    {
        $a = $this->perId($id);
        if (!$a || !$this->eManuale($a)) {
            return 'Il nome delle aziende del TSP si aggiorna caricando il report.';
        }
        if (trim($nome) === '') {
            return 'Scrivi il nome dell\'azienda.';
        }
        $this->db->esegui(
            'UPDATE tirocini_aziende SET nome = ?, partita_iva = ?, codice_fiscale = ?, modificata_il = NOW() WHERE id = ?',
            [mb_substr(trim($nome), 0, 255), mb_substr(preg_replace('/\s+/', '', $partitaIva) ?? '', 0, 20), mb_substr(strtoupper(preg_replace('/\s+/', '', $codiceFiscale) ?? ''), 0, 20), $id]
        );

        return null;
    }

    /** Toglie un'azienda inserita a mano (quelle del TSP escono dall'elenco da sole quando perdono la convenzione). */
    public function eliminaManuale(int $id): ?string
    {
        $a = $this->perId($id);
        if (!$a || !$this->eManuale($a)) {
            return 'Si possono togliere solo le aziende inserite a mano.';
        }
        $this->db->esegui('DELETE FROM tirocini_aziende WHERE id = ?', [$id]);

        return null;
    }

    /** @param array<string, mixed> $azienda */
    public function eManuale(array $azienda): bool
    {
        return str_starts_with((string) ($azienda['cod_tsp'] ?? ''), self::PREFISSO_MANUALE);
    }

    /** Scritto a mano: solo la prima lettera diventa maiuscola (il resto resta com'è, sigle comprese) */
    private static function conIniziale(string $testo): string
    {
        $t = self::maiuscolaIniziale($testo);

        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    /** «www.azienda.it» → «https://www.azienda.it»; '' resta vuoto, un indirizzo non valido dà null. */
    public static function normalizzaSito(string $sito): ?string
    {
        $sito = trim($sito);
        if ($sito !== '' && !preg_match('#^https?://#i', $sito)) {
            $sito = 'https://' . $sito;
        }

        return $sito !== '' && !filter_var($sito, FILTER_VALIDATE_URL) ? null : $sito;
    }

    /** Conferma gli indirizzi trovati sul web (di tutte o di una azienda): da quel momento si pubblicano. */
    public function confermaTrovati(?int $id = null): int
    {
        return $this->db->esegui(
            "UPDATE tirocini_aziende SET verificato = 1, modificata_il = NOW() WHERE origine = 'web' AND verificato = 0 AND comune <> ''" . ($id ? ' AND id = ?' : ''),
            $id ? [$id] : []
        );
    }

    /** Scarta l'indirizzo trovato sul web (di una azienda) */
    public function scartaIndirizzo(int $id): void
    {
        $this->db->esegui("UPDATE tirocini_aziende SET indirizzo = '', cap = '', comune = '', provincia = '', regione = '', origine = '', verificato = 0 WHERE id = ?", [$id]);
    }

    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM tirocini_aziende WHERE id = ?', [$id]);
    }

    /**
     * Elenco per il pannello. Filtri: «senza_indirizzo», «da_confermare» (trovati sul web), «non_pubbliche», «pubblicate», «tutte» (solo con convenzione attiva).
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(string $filtro = 'tutte', string $cerca = ''): array
    {
        $dove = ['convenzione = 1'];
        $par = [];
        $dove[] = match ($filtro) {
            'senza_indirizzo' => "comune = ''",
            'da_confermare' => "origine = 'web' AND verificato = 0",
            'non_pubbliche' => 'pubblica = 0',
            'pubblicate' => "pubblica = 1 AND comune <> '' AND (origine <> 'web' OR verificato = 1)",
            default => '1 = 1',
        };
        if (trim($cerca) !== '') {
            $dove[] = '(nome LIKE ? OR comune LIKE ? OR partita_iva LIKE ? OR codice_fiscale LIKE ?)';
            $l = '%' . trim($cerca) . '%';
            array_push($par, $l, $l, $l, $l);
        }

        return $this->db->righe('SELECT * FROM tirocini_aziende WHERE ' . implode(' AND ', $dove) . ' ORDER BY nome', $par);
    }

    /** @return array{totale: int, pubblicate: int, senza_indirizzo: int, da_confermare: int, non_pubbliche: int, senza_convenzione: int} */
    public function conteggi(): array
    {
        $c = $this->db->riga(
            "SELECT COUNT(*) AS t,
                SUM(CASE WHEN convenzione = 1 AND pubblica = 1 AND comune <> '' AND (origine <> 'web' OR verificato = 1) THEN 1 ELSE 0 END) AS p,
                SUM(CASE WHEN convenzione = 1 AND comune = '' THEN 1 ELSE 0 END) AS s,
                SUM(CASE WHEN convenzione = 1 AND origine = 'web' AND verificato = 0 THEN 1 ELSE 0 END) AS d,
                SUM(CASE WHEN convenzione = 1 AND pubblica = 0 THEN 1 ELSE 0 END) AS n,
                SUM(CASE WHEN convenzione = 0 THEN 1 ELSE 0 END) AS z
             FROM tirocini_aziende"
        ) ?? [];

        return ['totale' => (int) ($c['t'] ?? 0) - (int) ($c['z'] ?? 0), 'pubblicate' => (int) ($c['p'] ?? 0), 'senza_indirizzo' => (int) ($c['s'] ?? 0),
                'da_confermare' => (int) ($c['d'] ?? 0), 'non_pubbliche' => (int) ($c['n'] ?? 0), 'senza_convenzione' => (int) ($c['z'] ?? 0)];
    }

    /** Elenco pubblico: solo le aziende pubblicabili @return list<array<string, mixed>> */
    public function pubbliche(): array
    {
        return $this->db->righe(
            "SELECT nome, settore, indirizzo, cap, comune, provincia, regione, sito FROM tirocini_aziende
             WHERE convenzione = 1 AND pubblica = 1 AND comune <> '' AND provincia <> '' AND (origine <> 'web' OR verificato = 1)
             ORDER BY nome"
        );
    }

    /** File Excel con le aziende ancora senza indirizzo, da completare in blocco e ricaricare dal pannello */
    public function righeDaCompletare(): array
    {
        $righe = [];
        foreach ($this->elenco('senza_indirizzo') as $a) {
            $righe[] = [$a['cod_tsp'], $a['nome'], $a['partita_iva'] !== '' ? $a['partita_iva'] : $a['codice_fiscale'], $a['settore'], '', '', '', '', ''];
        }

        return $righe;
    }

    public static function sembraPersona(string $nome, string $codiceFiscale): bool
    {
        if (preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/i', trim($codiceFiscale))) {
            return true;
        }
        $parole = preg_split('/\s+/u', trim($nome)) ?: [];

        return count($parole) >= 2 && count($parole) <= 4 && !preg_match(self::PAROLE_ORGANIZZAZIONE, $nome) && !preg_match('/[\d&@]/', $nome);
    }

    /** «ASSISTENZA SANITARIA» → «Assistenza sanitaria» (le sigle e le frasi già in minuscolo restano) */
    public static function maiuscolaIniziale(string $testo): string
    {
        $t = trim($testo);
        if ($t === '' || $t !== mb_strtoupper($t)) {
            return $t;
        }
        $t = mb_strtolower($t);

        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    /** @param array<string, string> $riga @param list<string> $nomi */
    private function cella(array $riga, array $nomi): string
    {
        foreach ($nomi as $n) {
            if (isset($riga[$n]) && $riga[$n] !== '') {
                return trim($riga[$n]);
            }
        }

        return '';
    }

    /** @param array<string, string> $riga @param list<string> $nomi */
    private function haColonna(array $riga, array $nomi): bool
    {
        foreach ($nomi as $n) {
            if (array_key_exists($n, $riga)) {
                return true;
            }
        }

        return false;
    }
}
