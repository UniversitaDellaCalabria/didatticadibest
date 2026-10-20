<?php

declare(strict_types=1);

namespace App\Email;

use App\Infrastructure\Mail\Mailer;

/**
 * Invia un'email scritta da un modello del catalogo (testi modificabili dalla pagina «Email» del pannello): oggetto, testo e testo del pulsante
 * vengono dal modello, il link del pulsante lo mette il codice, il colore è quello del modulo. Lo usano tutti i moduli tranne il tutorato,
 * che ha il suo wrapper (NotificheIncarichi::inviaModello) con la frase «Il link è personale».
 */
final class InvioEmail
{
    public function __construct(private Mailer $mailer, private ModelliEmail $modelli)
    {
    }

    /**
     * @param array<string, scalar|null> $dati valori dei segnaposto
     * @param string $link link del pulsante (vuoto = niente pulsante)
     * @param list<array{path: string, nome?: string}> $allegati
     * @param string $coda HTML preparato dal codice da mettere dopo il pulsante (es. l'invito al sondaggio)
     * @param string|null $colore colore dell'email al posto di quello del modello (es. il colore dell'area dell'evento)
     */
    public function invia(?string $a, string $chiave, array $dati, string $link = '', array $allegati = [], string $coda = '', ?string $colore = null): bool
    {
        $p = $this->prepara($chiave, $dati, $link, $coda, $colore);

        return $this->mailer->invia((string) $a, $p['oggetto'], $p['corpo'], $p['colore'], $allegati);
    }

    /**
     * L'email pronta (oggetto, HTML con il pulsante in fondo, colore) senza spedirla: per chi deve preparare il messaggio prima e mandarlo dopo.
     *
     * @param array<string, scalar|null> $dati
     * @return array{oggetto: string, corpo: string, colore: ?string}
     */
    public function prepara(string $chiave, array $dati, string $link = '', string $coda = '', ?string $colore = null): array
    {
        $m = $this->modelli->modello($chiave);
        if ($m === null) {
            throw new \InvalidArgumentException("Modello di email sconosciuto: $chiave");
        }
        $r = $this->modelli->componi((string) $m['oggetto'], (string) $m['corpo'], $m['bottone'], $dati);
        $colore ??= self::coloreDi($m);

        return ['oggetto' => $r['oggetto'], 'corpo' => $r['corpo'] . ($link !== '' && $r['bottone'] !== null ? self::pulsante($link, $r['bottone'], $colore) : '') . $coda, 'colore' => $colore];
    }

    /** Spedisce un'email già preparata con prepara() (o composta a mano) nel colore del modello. */
    public function spedisci(string $a, string $chiave, string $oggetto, string $corpo): bool
    {
        $m = $this->modelli->modello($chiave);

        return $this->mailer->invia($a, $oggetto, $corpo, $m !== null ? self::coloreDi($m) : null);
    }

    /** Motivo dell'ultimo invio non riuscito. */
    public function ultimoErrore(): string
    {
        return $this->mailer->ultimoErrore();
    }

    /**
     * Email in formato HTML (eventi): il testo viene dal modello con i segnaposto {NOME} sostituiti. Ritorna false se non è partita (anche perché
     * l'email è facoltativa e non è attiva). $suggerimento = se non è attiva si usa comunque il testo originale del catalogo.
     *
     * @param array<string, scalar|null> $token valori dei segnaposto, con la graffa
     * @param string $coda HTML preparato dal codice da mettere in fondo
     */
    public function inviaHtml(?string $a, string $chiave, array $token, ?string $colore = null, string $coda = '', bool $suggerimento = false): bool
    {
        $r = $this->modelli->renderHtml($chiave, $token);
        if ($r === null && $suggerimento && ($o = $this->modelli->originale($chiave)) !== null) {
            $r = $this->modelli->componiHtml((string) $o['oggetto'], (string) $o['corpo'], $token);
        }
        if ($r === null) {
            return false;
        }

        return $this->mailer->invia((string) $a, $r['oggetto'], $r['corpo'] . $coda, $colore);
    }

    /** Colore dell'email: quello del modello, altrimenti quello del suo gruppo (null = rosso istituzionale). @param array<string, mixed> $modello */
    public static function coloreDi(array $modello): ?string
    {
        return $modello['colore'] ?? CatalogoEmail::colore((string) $modello['gruppo']);
    }

    /** Pulsante con il link, nel colore dell'email. */
    public static function pulsante(string $link, string $testo, ?string $colore): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:" . ($colore ?? '#B80000') . ";color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $h($testo) . '</a></p>';
    }

    /** Citazione evidenziata con un testo scritto da una persona (come «> » nei modelli); vuota se il testo è vuoto. */
    public static function citazione(string $testo): string
    {
        if (trim($testo) === '') {
            return '';
        }

        return "<blockquote style='" . ModelliEmail::STILE_CITAZIONE . "'>" . str_replace(["\r\n", "\n", "\r"], '<br>', htmlspecialchars($testo, ENT_QUOTES, 'UTF-8')) . '</blockquote>';
    }

    /** Riquadro grigio con un testo scritto da una persona (un messaggio, una nota); vuoto se il testo è vuoto. @param string $dopo HTML già pronto da mettere in fondo al riquadro */
    public static function riquadro(string $testo, string $dopo = ''): string
    {
        if (trim($testo) === '') {
            return '';
        }

        return "<p style='background:#f1f5f9;padding:10px;border-radius:6px;'>" . str_replace(["\r\n", "\n", "\r"], '<br>', htmlspecialchars($testo, ENT_QUOTES, 'UTF-8')) . $dopo . '</p>';
    }
}
