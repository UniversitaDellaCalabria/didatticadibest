<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Sito;
use App\Infrastructure\Pdf\Documento;

/**
 * Dichiarazione individuale del tutor (modello «N. 6» dell'Ateneo) compilata online durante l'accettazione dell'incarico, in due passi:
 *   passo 2: dati personali, posizione fiscale e accredito su IBAN (il compenso si paga solo con bonifico);
 *   passo 3: posizione contributiva (Gestione Separata INPS 35,03 % o 24 %, o dipendente di un ente) e rischio INAIL.
 * Qui ci sono i campi, i controlli (compreso l'IBAN) e il PDF della dichiarazione compilata.
 */
final class DichiarazioneIndividuale
{
    public const INPS = ['gs35' => 'Gestione Separata INPS, aliquota 35,03 % (non iscritto ad altra cassa previdenziale obbligatoria)',
                         'gs24' => 'Gestione Separata INPS, aliquota 24,00 % (già iscritto ad altra cassa previdenziale o titolare di pensione diretta)',
                         'ente' => 'Dipendente di un Ente pubblico o privato'];

    public const INAIL = ['didattica' => 'didattica e/o utilizzo di videoterminali', 'laboratorio' => 'utilizzo di apparecchiature scientifiche in laboratorio'];

    public function __construct(private Sito $sito)
    {
    }

    /**
     * Controlla i campi di un passo e li ripulisce. Ritorna [errori, dati ripuliti].
     *
     * @param int $passo 2 | 3
     * @param array<string, mixed> $post
     * @param array<string, mixed> $incarico lettera (dati già noti del tutor)
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    public function valida(int $passo, array $post, array $incarico): array
    {
        return $passo === 2 ? $this->passo2($post, $incarico) : $this->passo3($post);
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function passo2(array $p, array $i): array
    {
        $in = static fn (string $k, int $max = 200): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($p[$k] ?? ''))), 0, $max);
        $err = [];
        $d = [
            'telefono' => $in('telefono', 30), 'email' => strtolower($in('email', 150)), 'residenza_invariata' => !empty($p['residenza_invariata']),
            'indirizzo' => $in('indirizzo'), 'civico' => $in('civico', 20), 'citta' => $in('citta', 120), 'cap' => $in('cap', 10), 'prov' => strtoupper($in('prov', 3)),
            'estero_codice' => $in('estero_codice', 60), 'non_dipendente' => !empty($p['non_dipendente']), 'irpef' => $in('irpef', 10),
            'iban' => self::normalizzaIban((string) ($p['iban'] ?? '')), 'banca' => $in('banca', 150), 'swift' => strtoupper(str_replace(' ', '', $in('swift', 11))),
            'banca_indirizzo' => $in('banca_indirizzo'), 'banca_citta' => $in('banca_citta', 120),
        ];
        if (!preg_match('/^[0-9+ ()\/.-]{6,30}$/', $d['telefono'])) {
            $err[] = 'il telefono';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = "l'email";
        }
        if ($d['residenza_invariata']) {
            // La residenza è quella già scritta nella lettera di incarico
            $d['indirizzo'] = trim((string) ($i['indirizzo'] ?? ''));
            $d['civico'] = trim((string) ($i['civico'] ?? ''));
            $d['citta'] = trim((string) ($i['comune_residenza'] ?? ''));
        } elseif ($d['indirizzo'] === '' || $d['citta'] === '') {
            $err[] = 'la residenza (via e città)';
        }
        if (!$d['non_dipendente']) {
            $err[] = 'la dichiarazione di non essere dipendente UNICAL (se lo sei scrivi all\'ufficio: il modello è diverso)';
        }
        $irpef = str_replace([',', '%', ' '], ['.', '', ''], $d['irpef']);
        if ($irpef === '' || !is_numeric($irpef) || (float) $irpef < 0 || (float) $irpef > 50) {
            $err[] = 'l\'aliquota IRPEF massima (un numero, per esempio 23)';
        } else {
            $d['irpef'] = rtrim(rtrim(number_format((float) $irpef, 2, ',', ''), '0'), ',');
        }
        if (!self::ibanValido($d['iban'])) {
            $err[] = 'l\'IBAN (controlla di averlo scritto bene)';
        } elseif (!str_starts_with($d['iban'], 'IT') && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $d['swift'])) {
            $err[] = 'il codice SWIFT/BIC (obbligatorio per un conto estero)';
        }
        if ($d['banca'] === '') {
            $err[] = 'la banca o l\'istituto postale';
        }

        return [$err, $d];
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} */
    private function passo3(array $p): array
    {
        $in = static fn (string $k, int $max = 200): string => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($p[$k] ?? ''))), 0, $max);
        $err = [];
        $inps = isset(self::INPS[$p['inps'] ?? '']) ? (string) $p['inps'] : '';
        $inail = array_values(array_intersect(array_keys(self::INAIL), (array) ($p['inail'] ?? [])));
        $d = ['inps' => $inps, 'inail' => $inail, 'imponibile' => '', 'cassa_ente' => '', 'cassa_dal' => '', 'cassa_al' => '', 'cassa_professione' => '',
              'ente_denominazione' => '', 'ente_indirizzo' => '', 'ente_citta' => '', 'ente_prov' => '', 'ente_cf' => ''];
        if ($inps === '') {
            $err[] = 'la tua situazione contributiva (INPS)';
        } elseif ($inps === 'gs35') {
            $imp = str_replace(['.', ','], ['', '.'], $in('imponibile', 20));
            $d['imponibile'] = $imp === '' ? '0,00' : (is_numeric($imp) && (float) $imp >= 0 ? number_format((float) $imp, 2, ',', '.') : '');
            if ($d['imponibile'] === '') {
                $err[] = 'l\'imponibile teorico annuo (un importo in euro)';
            }
        } elseif ($inps === 'gs24') {
            $d['cassa_ente'] = $in('cassa_ente');
            $d['cassa_professione'] = $in('cassa_professione');
            foreach (['cassa_dal', 'cassa_al'] as $k) {
                $d[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in($k, 10)) ? $in($k, 10) : '';
            }
            if ($d['cassa_ente'] === '') {
                $err[] = 'la cassa o l\'ente previdenziale a cui sei iscritto (o la pensione diretta)';
            }
        } else {
            foreach (['ente_denominazione' => 200, 'ente_indirizzo' => 200, 'ente_citta' => 120, 'ente_prov' => 3, 'ente_cf' => 16] as $k => $max) {
                $d[$k] = $in($k, $max);
            }
            $d['ente_prov'] = strtoupper($d['ente_prov']);
            $d['ente_cf'] = strtoupper(str_replace(' ', '', $d['ente_cf']));
            if ($d['ente_denominazione'] === '') {
                $err[] = 'la denominazione dell\'ente di cui sei dipendente';
            }
        }
        if (!$inail) {
            $err[] = 'l\'ambito del rischio INAIL (almeno uno)';
        }

        return [$err, $d];
    }

    /** IBAN in maiuscolo, senza spazi. */
    public static function normalizzaIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    /** IBAN valido: lunghezza (27 per l'Italia, da 15 a 34 per gli altri) e controllo modulo 97. */
    public static function ibanValido(string $iban): bool
    {
        $iban = self::normalizzaIban($iban);
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        if (str_starts_with($iban, 'IT') && strlen($iban) !== 27) {
            return false;
        }
        $r = substr($iban, 4) . substr($iban, 0, 4);
        $n = '';
        foreach (str_split($r) as $c) {
            $n .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }
        $resto = 0;
        foreach (str_split($n, 7) as $blocco) {
            $resto = (int) ($resto . $blocco) % 97;
        }

        return $resto === 1;
    }

    /** IBAN a gruppi di quattro: IT60 X054 2811 … */
    public static function ibanLeggibile(string $iban): string
    {
        return trim(chunk_split(self::normalizzaIban($iban), 4, ' '));
    }

    /** IBAN con la parte centrale nascosta: IT60 X054 •••• •••• ••• 456 (si vede per intero solo su richiesta). */
    public static function ibanMascherato(string $iban): string
    {
        $iban = self::normalizzaIban($iban);

        // «#» segna le cifre nascoste (non compare mai in un IBAN normalizzato): si divide in gruppi di quattro e poi si sostituisce con il pallino
        return strlen($iban) < 12 ? '••••' : str_replace('#', '•', trim(chunk_split(substr($iban, 0, 8) . str_repeat('#', strlen($iban) - 11) . substr($iban, -3), 4, ' ')));
    }

    /**
     * PDF della dichiarazione compilata (stesse voci del modello «N. 6»).
     *
     * @param array<string, mixed> $i lettera di incarico
     * @param array<string, mixed> $dati passo2 e passo3
     */
    public function pdf(array $i, array $dati, string $data): string
    {
        $a = (array) ($dati['passo2'] ?? []);
        $b = (array) ($dati['passo3'] ?? []);
        $nome = trim($i['cognome'] . ' ' . $i['nome']);
        $pdf = new Documento(['logo' => $this->sito->radice() . '/' . Costanti::LOGO_LETTERA, 'logo_larghezza' => 210,
            'piede' => "Dichiarazione individuale – lettera di incarico {$i['codice']}",
            'info' => ['Title' => 'Dichiarazione individuale – ' . $nome, 'Author' => 'Dipartimento DiBEST – Università della Calabria', 'Subject' => 'Dichiarazione dei dati personali per assegni di tutorato']]);
        $riga = static fn (string $etichetta, string $valore): string => '**' . $etichetta . ':** ' . ($valore !== '' ? $valore : '—');
        $pdf->paragrafo('**DICHIARAZIONE DEI DATI PERSONALI PER ASSEGNI DI TUTORATO**', ['al' => 'centro', 'dopo' => 14]);
        $pdf->paragrafo("Io sottoscritto/a **$nome**, codice fiscale **{$i['codice_fiscale']}**, sotto la propria personale responsabilità DICHIARO:", ['dopo' => 8]);
        $nasc = ($i['data_nascita'] ? date('d/m/Y', (int) strtotime((string) $i['data_nascita'])) : '—') . ' a ' . ($i['luogo_nascita'] ?: '—');
        foreach ([
            $riga('Nato/a il', $nasc),
            $riga('Recapiti', 'tel. ' . ($a['telefono'] ?? '') . ' · email ' . ($a['email'] ?? '')),
            $riga('Residente in', trim(($a['indirizzo'] ?? '') . ' ' . ($a['civico'] ?? '') . ', ' . ($a['citta'] ?? '') . ' ' . ($a['cap'] ?? '') . ' ' . ($a['prov'] ?? ''), ' ,') . (!empty($a['residenza_invariata']) ? ' (dati invariati rispetto al precedente incarico)' : '')),
            $riga('Codice identificativo estero', (string) ($a['estero_codice'] ?? '')),
            $riga('Dipendente UNICAL', 'NO'),
            $riga('Aliquota IRPEF massima', ($a['irpef'] ?? '') . ' %'),
        ] as $r) {
            $pdf->paragrafo($r, ['dopo' => 4]);
        }
        $pdf->paragrafo('**Modalità di pagamento:** accredito su conto bancario/postale intestato o cointestato al collaboratore', ['dopo' => 4, 'prima' => 6]);
        foreach ([$riga('IBAN', self::ibanLeggibile((string) ($a['iban'] ?? ''))), $riga('Istituto', (string) ($a['banca'] ?? '')),
                  $riga('Codice SWIFT/BIC', (string) ($a['swift'] ?? '')), $riga('Filiale', trim(($a['banca_indirizzo'] ?? '') . ' ' . ($a['banca_citta'] ?? '')))] as $r) {
            $pdf->paragrafo($r, ['dopo' => 4]);
        }
        $pdf->paragrafo('**DICHIARAZIONE DI TITOLARITÀ DI POSIZIONE CONTRIBUTIVA A FINI PREVIDENZIALI**', ['al' => 'centro', 'dopo' => 10, 'prima' => 14]);
        $pdf->paragrafo("In relazione all'incarico conferitomi presso il Dipartimento di Biologia, Ecologia e Scienze della Terra in qualità di tutor, dichiaro di essere iscritto alla Gestione Separata INPS e, relativamente al periodo di svolgimento dell'incarico:", ['dopo' => 6]);
        $inps = (string) ($b['inps'] ?? '');
        $pdf->paragrafo('**' . (self::INPS[$inps] ?? '—') . '**', ['dopo' => 4]);
        if ($inps === 'gs35') {
            $pdf->paragrafo($riga('Imponibile teorico annuo riferito alle sole prestazioni di co.co.co.', '€ ' . ($b['imponibile'] ?? '0,00')), ['dopo' => 4]);
        } elseif ($inps === 'gs24') {
            $pdf->paragrafo($riga('Cassa o ente previdenziale', (string) ($b['cassa_ente'] ?? '')) . ($b['cassa_dal'] ? ' dal ' . date('d/m/Y', (int) strtotime((string) $b['cassa_dal'])) : '') . ($b['cassa_al'] ? ' al ' . date('d/m/Y', (int) strtotime((string) $b['cassa_al'])) : '') . ($b['cassa_professione'] ? ' · professione: ' . $b['cassa_professione'] : ''), ['dopo' => 4]);
        } elseif ($inps === 'ente') {
            $pdf->paragrafo($riga('Ente di appartenenza', trim(($b['ente_denominazione'] ?? '') . ', ' . ($b['ente_indirizzo'] ?? '') . ' ' . ($b['ente_citta'] ?? '') . ' ' . ($b['ente_prov'] ?? ''), ' ,')) . ($b['ente_cf'] ? ' · codice fiscale / P. IVA ' . $b['ente_cf'] : ''), ['dopo' => 4]);
        }
        $pdf->paragrafo($riga('Rischio INAIL (l\'incarico rientra nell\'ambito di)', implode('; ', array_map(static fn ($k) => self::INAIL[$k] ?? $k, (array) ($b['inail'] ?? [])))), ['dopo' => 10]);
        $pdf->paragrafo("Dichiarazione compilata online e accettata dal tutor con la lettera di incarico il $data.", ['sz' => 9]);

        return $pdf->pdf();
    }
}
