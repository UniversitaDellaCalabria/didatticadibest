<?php

declare(strict_types=1);

namespace App\Fsl;

/**
 * Il percorso di una scuola nella Formazione Scuola Lavoro (iscrizione → convenzione → attività → elenco studenti → attestati →
 * valutazione) e i numeri per fase del pannello. Solo regole: i dati arrivano da ServizioPannelloFsl::riepilogo() e
 * ServizioConvenzioniScuole::scuole(); niente database, così si prova da sola.
 */
final class PercorsoFsl
{
    /** Chiave del passo => nome mostrato. */
    public const PASSI = [
        'iscrizione'  => 'Iscrizione',
        'convenzione' => 'Convenzione',
        'attivita'    => 'Attività svolta',
        'elenco'      => 'Elenco studenti',
        'attestati'   => 'Attestati',
        'valutazione' => 'Valutazione',
    ];

    /**
     * Riga di iscrizione con attestati per gli studenti della classe (progetto per le scuole o evento con attestati).
     *
     * @param array<string, mixed> $x riga di riepilogo() con tipo, pd_attestati, pd_per_scuole, stato
     */
    public static function haAttestati(array $x): bool
    {
        if ((int) ($x['pd_attestati'] ?? 0) !== 1 || ($x['stato'] ?: 'confermata') !== 'confermata') {
            return false;
        }

        return ($x['tipo'] ?? 'evento') !== 'progetto' || (int) ($x['pd_per_scuole'] ?? 1) === 1;
    }

    /**
     * Numeri per fase dell'anno scolastico.
     *
     * @param list<array<string, mixed>> $righe righe di riepilogo() (dal, al, stato, n_elenco, attestato_inviato, scuola_codice…)
     * @return array{scuole: int, in_attesa: int, elenchi_mancanti: list<array<string, mixed>>, attestati_dovuti: int, attestati_inviati: int}
     */
    public static function contatori(array $righe, string $oggi): array
    {
        $scuole = [];
        $inAttesa = 0;
        $mancanti = [];
        $dovuti = $inviati = 0;
        foreach ($righe as $x) {
            $scuole[(string) ($x['scuola_codice'] ?: 'txt:' . mb_strtolower((string) ($x['scuola_nome'] ?? '')))] = true;
            if (($x['stato'] ?: 'confermata') !== 'confermata') {
                ++$inAttesa;
                continue;
            }
            if (!self::haAttestati($x) || self::conclusa($x, $oggi) === false) {
                continue;
            }
            ++$dovuti;
            if ((int) ($x['n_elenco'] ?? 0) === 0) {
                $mancanti[] = $x;
            }
            if ((int) ($x['attestato_inviato'] ?? 0) === 1) {
                ++$inviati;
            }
        }

        return ['scuole' => count($scuole), 'in_attesa' => $inAttesa, 'elenchi_mancanti' => $mancanti, 'attestati_dovuti' => $dovuti, 'attestati_inviati' => $inviati];
    }

    /** L'attività è finita (data di fine prima di oggi). */
    public static function conclusa(array $x, string $oggi): bool
    {
        return !empty($x['al']) && (string) $x['al'] < $oggi;
    }

    /**
     * I passi di una scuola: i fatti, il primo che manca («qui») e quelli ancora da fare. Un passo senza oggetto (nessuna
     * attività con attestati, nessuna valutazione prevista) si indica con null e non compare.
     *
     * @return list<array{chiave: string, nome: string, stato: string}> stato = fatto | qui | ''
     */
    public static function passi(bool $iscritta, bool $convenzione, bool $conclusa, ?bool $elenco, ?bool $attestati, ?bool $valutazione): array
    {
        $fatti = ['iscrizione' => $iscritta, 'convenzione' => $convenzione, 'attivita' => $conclusa, 'elenco' => $elenco, 'attestati' => $attestati, 'valutazione' => $valutazione];
        $out = [];
        $primo = true;
        foreach (self::PASSI as $k => $nome) {
            if ($fatti[$k] === null) {
                continue;
            }
            $stato = $fatti[$k] ? 'fatto' : ($primo ? 'qui' : '');
            $primo = $primo && $fatti[$k];
            $out[] = ['chiave' => $k, 'nome' => $nome, 'stato' => $stato];
        }

        return $out;
    }
}
