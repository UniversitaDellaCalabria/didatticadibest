<?php

declare(strict_types=1);

namespace App\Fsl\Vista;

use App\Core\Sito;

/** Testi sui documenti da consegnare prima dell'attività FSL (elenco degli studenti e autorizzazione della scuola): pagina, email e promemoria. */
final class AvvisoDocumenti
{
    /** Aspetto dei riquadri informativi del modulo di prenotazione FSL (questo e quello del programma, in master_template.php: devono restare uguali). */
    public const STILE_RIQUADRO = 'border:1px solid #0d6efd;border-left-width:6px;background:#eef5ff;border-radius:8px;padding:12px 16px;margin-bottom:14px;font-size:.95rem;line-height:1.5;';
    public const STILE_TITOLO = 'font-weight:700;font-size:1.02rem;margin-bottom:4px;color:#0a4aa8;';

    public function __construct(private Sito $sito)
    {
    }

    /** Indirizzo della pagina in cui il docente carica elenco e autorizzazione. */
    public function link(string $codice): string
    {
        return $this->sito->urlBase() . '/elenco_studenti.php?code=' . urlencode($codice);
    }

    /**
     * Avviso dopo la prenotazione, in aggiunta alla convenzione: cosa caricare e entro quando.
     *
     * @param string|null $scadenza ultimo giorno per consegnare (Y-m-d)
     */
    public function dopoPrenotazione(string $codice, ?string $scadenza, bool $perEmail = false): string
    {
        $titolo = '<strong>Elenco degli studenti e autorizzazione della scuola</strong>';
        $quando = $scadenza !== null
            ? 'entro il <strong>' . date('d/m/Y', (int) strtotime($scadenza)) . '</strong>'
            : 'prima dell\'inizio dell\'attività';
        $elenco = "<ul style='margin:6px 0 10px;padding-left:20px;'>"
            . '<li>l\'<strong>elenco degli studenti</strong> che partecipano (cognome e nome: puoi scriverlo, incollarlo da Excel o caricare il modello compilato);</li>'
            . '<li>l\'<strong>autorizzazione della scuola</strong> alla partecipazione, in <strong>PDF</strong>.</li></ul>';
        $bottone = $this->bottone($codice, 'Carica elenco e autorizzazione', $perEmail);
        $testo = "<p style='margin:0 0 4px;'>Oltre alla convenzione, $quando devi caricare nel portale:</p>$elenco"
            . '<p style=\'margin:0 0 8px;\'>Ti ricordiamo noi con un\'email se qualcosa manca. Puoi farlo anche subito, in attesa della convenzione.</p>' . $bottone;

        return $perEmail
            ? "<div style='margin:18px 0;padding:12px 14px;border-left:4px solid #0d6efd;background:#f1f6ff;'><p style='margin:0 0 6px;'>📎 $titolo</p>$testo</div>"
            : "<div class='alert alert-primary text-start my-3 shadow-sm border-0 border-start border-5 border-primary small'><div class='fw-bold mb-1'><i class='fa fa-paperclip me-1' aria-hidden='true'></i> Elenco degli studenti e autorizzazione della scuola</div>$testo</div>";
    }

    /**
     * Riquadro ben visibile nel modulo di prenotazione (prima di prenotare): dopo la prenotazione vanno caricati elenco e autorizzazione.
     *
     * @param string|null $scadenza ultimo giorno per consegnare (Y-m-d), se si prenotasse oggi
     */
    public function nelModulo(?string $scadenza): string
    {
        $quando = $scadenza !== null ? 'entro il <strong>' . date('d/m/Y', (int) strtotime($scadenza)) . '</strong>' : 'prima dell\'inizio dell\'attività';

        return "<div role='note' style='" . self::STILE_RIQUADRO . "'>"
            . "<div style='" . self::STILE_TITOLO . "'><i class='fa fa-triangle-exclamation me-1' aria-hidden='true'></i> Dopo la prenotazione dovrai caricare due documenti</div>"
            . "<ol style='margin:0 0 8px;padding-left:22px;'>"
            . "<li>l'<strong>elenco degli studenti</strong> (cognome e nome: puoi scriverlo, incollarlo da Excel o caricare il modello compilato);</li>"
            . "<li>l'<strong>autorizzazione della scuola</strong> alla partecipazione, in <strong>PDF</strong>.</li></ol>"
            . "<div style='margin-bottom:4px;'>Vanno consegnati $quando, oltre alla convenzione con il Dipartimento. Si caricano dalla tua <strong>Area personale</strong> (accesso con SPID, CIE o credenziali Unical, con la stessa email) e ti ricordiamo noi con un'email se manca qualcosa.</div>"
            . '</div>';
    }

    /**
     * Dati per l'email di promemoria (o ultimo avviso) con quello che ancora manca: i segnaposto dei modelli fsl_documenti_*.
     *
     * @param array<string, mixed> $p prenotazione (nome, cognome, titolo, codice_prenotazione, data_turno/data_inizio)
     * @param array{studenti: int, elenco: bool, autorizzazione: bool, scadenza: ?string, scaduti: bool} $stato
     * @return array<string, string>
     */
    public function datiPromemoria(array $p, array $stato, bool $ultimo, ?string $inizio): array
    {
        $manca = [];
        if (!$stato['elenco']) {
            $manca[] = "l'elenco degli studenti";
        }
        if (!$stato['autorizzazione']) {
            $manca[] = "l'autorizzazione della scuola (PDF)";
        }
        $entro = $stato['scadenza'] !== null ? '<strong>' . date('d/m/Y', (int) strtotime($stato['scadenza'])) . '</strong>' : '';

        return [
            'nome' => trim($p['nome'] . ' ' . $p['cognome']),
            'titolo' => (string) $p['titolo'],
            'codice' => (string) $p['codice_prenotazione'],
            'inizio_testo' => $inizio !== null ? 'inizia il ' . date('d/m/Y', (int) strtotime($inizio)) : 'sta per iniziare',
            'manca' => implode(' e ', $manca),
            'scadenza' => $entro !== '' ? '<p>Puoi caricarli entro il ' . $entro . '.</p>' : '',
            'consegna' => '<p>I documenti andavano consegnati' . ($entro !== '' ? ' entro il ' . $entro : '') . ': caricali al più presto.</p>',
        ];
    }

    /**
     * Più classi dello stesso docente per la stessa attività: l'email è una sola e dice cosa manca a ciascuna (con il codice).
     *
     * @param array<string, string> $dati risultato di datiPromemoria() per la prima classe
     * @param list<array<string, mixed>> $prenotazioni
     * @param list<array{studenti: int, elenco: bool, autorizzazione: bool, scadenza: ?string, scaduti: bool}> $stati nello stesso ordine
     * @return array<string, string>
     */
    public function unisciClassi(array $dati, array $prenotazioni, array $stati): array
    {
        $righe = [];
        foreach ($prenotazioni as $i => $p) {
            $manca = [];
            if (!$stati[$i]['elenco']) {
                $manca[] = "l'elenco degli studenti";
            }
            if (!$stati[$i]['autorizzazione']) {
                $manca[] = "l'autorizzazione della scuola (PDF)";
            }
            $righe[] = 'per la prenotazione ' . $p['codice_prenotazione'] . ' ' . implode(' e ', $manca);
        }
        $dati['manca'] = implode('; ', $righe);
        $dati['codice'] = implode(', ', array_map(fn (array $p): string => (string) $p['codice_prenotazione'], $prenotazioni));

        return $dati;
    }

    private function bottone(string $codice, string $etichetta, bool $perEmail): string
    {
        $link = htmlspecialchars($this->link($codice), ENT_QUOTES, 'UTF-8');

        return $perEmail
            ? "<p style='text-align:center;margin:22px 0;'><a href='$link' style='background-color:#0d6efd;color:#ffffff;padding:11px 22px;text-decoration:none;border-radius:6px;font-weight:bold;'>$etichetta</a></p>"
            : "<a href='$link' class='btn btn-primary btn-sm fw-bold'><i class='fa fa-upload me-1' aria-hidden='true'></i>$etichetta</a>";
    }
}
