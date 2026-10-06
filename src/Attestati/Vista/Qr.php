<?php

declare(strict_types=1);

namespace App\Attestati\Vista;

/** QR disegnato nella pagina (SVG, libreria qrcode-generator): nessun servizio esterno riceve il contenuto. */
final class Qr
{
    /** Lo script di disegno si aggiunge una sola volta per pagina (per istanza). */
    private bool $scriptInviato = false;

    public function __construct(private string $urlBase)
    {
    }

    /** $stile imposta la larghezza (il QR è quadrato). */
    public function html(string $dati, string $stile = 'width:150px', string $alt = 'QR code', string $classi = ''): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $out = '<div class="qr-locale ' . $h($classi) . '" data-qr="' . $h($dati) . '" role="img" aria-label="' . $h($alt) . '" style="aspect-ratio:1/1;' . $h($stile) . '"></div>';
        if (!$this->scriptInviato) {
            $this->scriptInviato = true;
            $out .= '<style>.qr-locale svg{width:100%;height:100%;display:block}</style>'
                  . Librerie::scriptLibreria($this->urlBase, 'qrcode')
                  . '<script>(function(){function d(){document.querySelectorAll(".qr-locale[data-qr]").forEach(function(el){'
                  . 'if(el.firstChild)return;if(typeof qrcode!=="function"){el.textContent="QR non disponibile";return;}'
                  . 'var q=qrcode(0,"M");q.addData(el.dataset.qr);q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true});});}'
                  . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",d);else d();})();</script>';
        }

        return $out;
    }
}
