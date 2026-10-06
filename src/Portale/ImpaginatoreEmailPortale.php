<?php

declare(strict_types=1);

namespace App\Portale;

use App\Infrastructure\Mail\ImpaginatoreEmail;

/**
 * Impaginazione comune delle email: intestazione con il colore dell'area, corpo, piè di pagina.
 * I pulsanti col rosso istituzionale nel corpo prendono il colore dell'area.
 * Se il corpo è già un documento HTML completo (template personalizzato) resta com'è.
 */
final class ImpaginatoreEmailPortale implements ImpaginatoreEmail
{
    public function impagina(string $corpo, string $titolo, ?string $colore = null): string
    {
        if (stripos($corpo, '<html') !== false || stripos($corpo, '<body') !== false) {
            return $corpo;
        }
        $col = Colori::valido($colore ?? '');
        $testo = Colori::testoSu($col);
        if ($colore !== null) {
            // Pulsanti "sfondo rosso + testo bianco": sfondo dell'area e testo a contrasto
            $corpo = (string) preg_replace(
                '/background(-color)?\s*:\s*#B[38]0000\s*;\s*color\s*:\s*(#fff(fff)?|white)/i',
                'background$1:' . $col . '; color:' . $testo,
                $corpo
            );
            $corpo = str_ireplace(['#B30000', '#B80000'], $col, $corpo);
        }

        return '<div style="background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
            . '<tr><td style="background:' . $col . ';color:' . $testo . ';padding:16px 24px;font-size:18px;font-weight:bold;">' . htmlspecialchars($titolo) . '</td></tr>'
            . '<tr><td style="padding:24px;color:#1f2937;font-size:15px;line-height:1.6;">' . $corpo . '</td></tr>'
            . '<tr><td style="padding:12px 24px;background:#f9fafb;color:#6b7280;font-size:12px;">Messaggio automatico: non rispondere a questa email. Gestisci le tue prenotazioni dall\'Area Personale del portale.</td></tr>'
            . '</table></div>';
    }
}
