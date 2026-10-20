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
     */
    public function invia(?string $a, string $chiave, array $dati, string $link = '', array $allegati = [], string $coda = ''): bool
    {
        $m = $this->modelli->modello($chiave);
        if ($m === null) {
            throw new \InvalidArgumentException("Modello di email sconosciuto: $chiave");
        }
        $r = $this->modelli->componi((string) $m['oggetto'], (string) $m['corpo'], $m['bottone'], $dati);
        $colore = self::coloreDi($m);

        return $this->mailer->invia((string) $a, $r['oggetto'], $r['corpo'] . ($link !== '' && $r['bottone'] !== null ? self::pulsante($link, $r['bottone'], $colore) : '') . $coda, $colore, $allegati);
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

        return "<p style='margin-top:18px;'><a href='" . $h($link) . "' style='background:" . ($colore ?? '#0f172a') . ";color:#fff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;'>" . $h($testo) . '</a></p>';
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
