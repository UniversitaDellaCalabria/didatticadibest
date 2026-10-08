<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Anagrafi\ServizioScuole;
use App\Anagrafi\Testi;
use App\Eventi\Turni;

/**
 * Pagina «Convenzioni da stipulare» del pannello FSL: le scuole con prenotazioni in essere alle attività FSL, per ognuna lo stato della
 * convenzione e i documenti già precompilati (Allegato A in PDF e Word, Convenzione Word) da scaricare, anche per le prenotazioni già
 * confermate e senza che la scuola abbia compilato il modulo online.
 */
final class ServizioConvenzioniScuole
{
    public const DOCUMENTI = ['allegato_pdf', 'allegato', 'convenzione'];

    public function __construct(
        private PannelloRepository $pannello,
        private ServizioPannelloFsl $servizioPannello,
        private ServizioConvenzioni $convenzioni,
        private PeriodiConvenzione $periodi,
        private ServizioScuole $scuole,
        private ServizioConvenzioneOnline $online
    ) {
    }

    /**
     * Le scuole con prenotazioni in essere: prima quelle con una convenzione da stipulare, poi le altre, per nome.
     *
     * @return list<array{
     *     chiave: string, codice: string, nome: string, comune: string, da_stipulare: bool, n_da_stipulare: int, n_studenti: int, validita: ?string,
     *     dal: string, al: string,
     *     prenotazioni: list<array{id: int, codice: string, titolo: string, area: string, pagina_id: int, turno: string, periodo: string, stato: string, conv: string, studenti: int, docente: string, email: string}>
     * }>
     */
    public function scuole(): array
    {
        $daStipulare = array_flip(array_map(static fn (array $x): int => (int) $x['id'], $this->servizioPannello->daStipulare()[0]));
        $gruppi = [];
        foreach ($this->pannello->iscrizioniInEssere() as $x) {
            $chiave = $this->chiave($x);
            [$dal, $al] = $this->periodi->prenotazione($x);
            $registro = !empty($x['scuola_codice']) ? $this->convenzioni->valida($x['scuola_codice'], false, $dal, $al) : null;
            $conv = isset($daStipulare[(int) $x['id']]) ? 'da_stipulare'
                : ($registro ? 'coperta' : (($x['convenzione'] ?? '') === 'ricevuta' ? 'ricevuta' : 'dichiarata'));
            if (!isset($gruppi[$chiave])) {
                $anagrafe = !empty($x['scuola_codice']) ? $this->scuole->perCodice((string) $x['scuola_codice']) : null;
                $gruppi[$chiave] = [
                    'chiave' => $chiave, 'codice' => (string) ($x['scuola_codice'] ?? ''), 'nome' => $this->scuole->nomeDaPrenotazione($x) ?: 'Scuola non indicata',
                    'comune' => $anagrafe ? Testi::maiuscoleScuola((string) $anagrafe['comune']) . (!empty($anagrafe['provincia']) ? ' (' . Testi::maiuscoleScuola((string) $anagrafe['provincia']) . ')' : '') : '',
                    'da_stipulare' => false, 'n_da_stipulare' => 0, 'n_studenti' => 0, 'validita' => null, 'dal' => $dal, 'al' => $al, 'prenotazioni' => [],
                ];
            }
            $g = &$gruppi[$chiave];
            $custom = json_decode((string) ($x['dati_custom_json'] ?? ''), true) ?: [];
            $studenti = (int) ($custom[\CAMPO_PARTECIPANTI] ?? 0);
            $g['prenotazioni'][] = [
                'id' => (int) $x['id'], 'codice' => (string) $x['codice_prenotazione'], 'titolo' => (string) $x['titolo'], 'area' => (string) $x['area'], 'pagina_id' => (int) $x['pagina_id'],
                'turno' => ($x['evento_tipo'] ?? '') === 'progetto' ? trim((string) ($x['nome_turno'] ?? '')) : Turni::etichetta($x),
                'periodo' => $dal === $al ? date('d/m/Y', (int) strtotime($dal)) : date('d/m/Y', (int) strtotime($dal)) . ' – ' . date('d/m/Y', (int) strtotime($al)),
                'stato' => (string) ($x['stato'] ?: 'confermata'), 'conv' => $conv, 'studenti' => $studenti,
                'docente' => trim($x['nome'] . ' ' . $x['cognome']), 'email' => (string) $x['email'],
            ];
            $g['n_studenti'] += $studenti;
            if ($conv === 'da_stipulare') {
                $g['da_stipulare'] = true;
                ++$g['n_da_stipulare'];
            }
            if ($registro && !empty($registro['scadenza']) && ($g['validita'] === null || $registro['scadenza'] < $g['validita'])) {
                $g['validita'] = (string) $registro['scadenza'];
            }
            $g['dal'] = min($g['dal'], $dal);
            $g['al'] = max($g['al'], $al);
            unset($g);
        }
        $elenco = array_values($gruppi);
        usort($elenco, static fn (array $a, array $b): int => [$b['da_stipulare'], $a['nome']] <=> [$a['da_stipulare'], $b['nome']]);

        return $elenco;
    }

    /**
     * Documento precompilato per tutte le prenotazioni in essere di una scuola.
     *
     * @return array{file: string, nome: string}|null file temporaneo da cancellare dopo l'uso
     */
    public function documento(string $doc, string $chiave): ?array
    {
        foreach ($this->scuole() as $g) {
            if ($g['chiave'] === $chiave) {
                return $this->online->scaricaPerPrenotazioni($this->tipo($doc), array_column($g['prenotazioni'], 'id'));
            }
        }

        return null;
    }

    /**
     * Documento precompilato dalla riga di una prenotazione (pulsante in Iscrizioni): include tutte le prenotazioni in essere della stessa scuola;
     * se la prenotazione non è più in essere (conclusa) solo quella.
     *
     * @return array{file: string, nome: string}|null
     */
    public function documentoDellaPrenotazione(string $doc, int $prenotazioneId): ?array
    {
        foreach ($this->scuole() as $g) {
            if (in_array($prenotazioneId, array_column($g['prenotazioni'], 'id'), true)) {
                return $this->online->scaricaPerPrenotazioni($this->tipo($doc), array_column($g['prenotazioni'], 'id'));
            }
        }

        return $this->online->scaricaPerPrenotazioni($this->tipo($doc), [$prenotazioneId]);
    }

    /** Nome del download e tipo MIME del documento. */
    public static function mime(string $nomeFile): string
    {
        return str_ends_with($nomeFile, '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    private function tipo(string $doc): string
    {
        return in_array($doc, self::DOCUMENTI, true) ? $doc : 'allegato_pdf';
    }

    /**
     * Una scuola dell'anagrafe è identificata dal codice meccanografico, una scritta a mano dal suo nome.
     *
     * @param array<string, mixed> $x
     */
    private function chiave(array $x): string
    {
        if (!empty($x['scuola_codice'])) {
            return strtoupper((string) $x['scuola_codice']);
        }

        return 'x' . substr(md5(mb_strtolower(trim(ServizioScuole::nomeScrittoNelModulo($x)) ?: 'p' . $x['id'])), 0, 12);
    }
}
