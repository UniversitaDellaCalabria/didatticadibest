<?php

declare(strict_types=1);

namespace App\Fsl\Vista;

use App\Core\Sito;
use App\Fsl\ModelliConvenzione;

/** Cosa fare quando la scuola non ha ancora la convenzione: testo della pagina di prenotazione, delle email e dell'Area personale. */
final class Istruzioni
{
    public function __construct(private ModelliConvenzione $modelli, private Sito $sito)
    {
    }

    /**
     * $inAttesa = false: prenotazione già confermata a cui si chiede comunque la convenzione. $codice (della prenotazione): se
     * l'area usa il modello del Dipartimento si offre la convenzione già compilata con i dati della prenotazione
     * (convenzione_precompilata.php); il codice non va scritto nella PEC.
     *
     * @param array<string, mixed> $cfg riga di pagine_eventi dell'area
     */
    public function html(array $cfg, bool $perEmail = false, string $codice = '', bool $inAttesa = true): string
    {
        $c = $this->modelli->dati($cfg);
        $url = $this->sito->urlBase();
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $a = $perEmail ? " style='color:#B30000;font-weight:bold;'" : " target='_blank' rel='noopener' class='fw-bold'";
        $nomeFile = static fn ($u): string => strtoupper(pathinfo((string) parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
        $fmt = static fn ($u): string => in_array($nomeFile($u), ['DOC', 'DOCX', 'PDF', 'ODT'], true) ? ' <span style="font-weight:normal;">(' . $nomeFile($u) . ')</span>' : '';
        $frase = $inAttesa
            ? 'La prenotazione resta <strong>in attesa</strong> finché la scuola non stipula la convenzione con il Dipartimento.'
            : 'Per partecipare la scuola deve stipulare la <strong>convenzione</strong> con il Dipartimento.';
        $precompilata = $codice !== '' && empty($cfg['conv_url_modello']) && $this->modelli->haPrecompilabile('convenzione');
        $allegatoPre = $codice !== '' && empty($cfg['conv_url_allegato']) && $this->modelli->haPrecompilabile('allegato');
        $linkVuoto = $perEmail ? " style='color:#B30000;'" : " target='_blank' rel='noopener'";
        $voceAll = $allegatoPre
            ? "<a href='" . $h($url . '/convenzione_precompilata.php?doc=allegato&code=' . urlencode($codice)) . "'$a>Scarica l'Allegato A già compilato</a> <span style='font-weight:normal;'>(DOCX)</span>"
              . " · <a href='" . $h($c['allegato']) . "'" . $linkVuoto . '>modello vuoto</a>'
            : "<a href='" . $h($c['allegato']) . "'$a>Scarica l'Allegato A</a>" . $fmt($c['allegato']);
        $voceConv = $precompilata
            ? "<a href='" . $h($url . '/convenzione_precompilata.php?code=' . urlencode($codice)) . "'$a>Scarica la Convenzione già compilata</a> <span style='font-weight:normal;'>(DOCX, con i dati della scuola e della prenotazione: completa i campi evidenziati in giallo)</span>"
              . " · <a href='" . $h($c['modello']) . "'" . $linkVuoto . '>modello vuoto</a>'
            : "<a href='" . $h($c['modello']) . "'$a>Scarica il modello di Convenzione</a>" . $fmt($c['modello']);
        // Con il modello del Dipartimento la scuola compila Convenzione e Allegato A online (convenzione_online.php):
        // nel modulo di prenotazione (senza codice) si annuncia, dopo la prenotazione c'è il link
        $online = empty($cfg['conv_url_modello']) && $this->modelli->haPrecompilabile('convenzione');
        if ($online && $codice === '') {
            return "<p style='margin:0 0 6px;'>$frase</p><p style='margin:0;'><strong>Al termine della prenotazione</strong> potrai compilare online la <strong>Convenzione</strong> e l'<strong>Allegato A</strong> con un modulo guidato: trovi il link nella pagina di conferma e nell'email. Il Dirigente li firma digitalmente in PAdES (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . '</a>.</p>';
        }
        if ($online) {
            $urlOn = $url . '/convenzione_online.php?code=' . urlencode($codice);
            $bottone = $perEmail
                ? "<p style='margin:12px 0;'><a href='" . $h($urlOn) . "' style='background:#B30000;color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>Compila online la Convenzione e l'Allegato A</a></p>"
                : "<p style='margin:8px 0;'><a href='" . $h($urlOn) . "' class='btn btn-danger btn-sm fw-bold'><i class='fa fa-file-signature me-1'></i>Compila online la Convenzione e l'Allegato A</a></p>";

            return "<p style='margin:0 0 6px;'>$frase Compila online i documenti con un modulo guidato (dati della scuola e del Dirigente, attività da inserire nell'Allegato A, logo della scuola): li scarichi già pronti in Word.</p>"
                 . $bottone
                 . "<p style='margin:0;'>Poi il Dirigente li <strong>firma digitalmente in PAdES</strong> (PDF firmato, non .p7m) e la scuola li invia via PEC a <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . '</a>. '
                 . ($inAttesa ? 'Appena riceviamo la convenzione confermiamo la prenotazione e ti avvisiamo per email.' : "Se la scuola l'ha già inviata, puoi ignorare questo messaggio.")
                 . " <span style='font-size:.9em;'>Preferisci i modelli vuoti? <a href='" . $h($c['modello']) . "'" . ($perEmail ? " style='color:#B30000;'" : '') . ">Convenzione</a> · <a href='" . $h($c['allegato']) . "'" . ($perEmail ? " style='color:#B30000;'" : '') . '>Allegato A</a></span></p>';
        }

        return "<p style='margin:0 0 6px;'>$frase Compila i modelli:</p>"
             . "<ul style='margin:0 0 6px;'><li>$voceConv</li><li>$voceAll</li></ul>"
             . "<p style='margin:0;'>e inviali <strong>firmati digitalmente in PAdES</strong> (PDF firmato, non .p7m) alla PEC <a href='mailto:" . $h($c['pec']) . "'$a>" . $h($c['pec']) . '</a>. '
             . ($inAttesa ? 'Appena riceviamo la convenzione confermiamo la prenotazione e ti avvisiamo per email.</p>' : "Se la scuola l'ha già inviata, puoi ignorare questo messaggio.</p>");
    }
}
