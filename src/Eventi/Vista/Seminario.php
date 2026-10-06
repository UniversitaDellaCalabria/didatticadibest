<?php

declare(strict_types=1);

namespace App\Eventi\Vista;

use App\Eventi\ServizioSeminari;

/** Riquadro pubblico del seminario nella scheda dell'evento. */
final class Seminario
{
    /** Relatore, abstract, diretta (solo prima della fine), registrazione e slide. */
    public static function riquadro(array $ev, bool $concluso = false): string
    {
        if (!ServizioSeminari::eSeminario($ev) && empty($ev['link_streaming']) && empty($ev['link_registrazione']) && empty($ev['slide_pdf'])) {
            return '';
        }
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $o = '<article class="ev-box p-4 mb-4 seminario">';
        if (trim((string) $ev['relatore']) !== '') {
            $o .= '<h2><i class="fa fa-chalkboard-user me-1" aria-hidden="true"></i>Relatore</h2><p class="mb-3"><strong>' . $h($ev['relatore']) . '</strong>' . (trim((string) $ev['relatore_ente']) !== '' ? '<br><span class="text-secondary">' . $h($ev['relatore_ente']) . '</span>' : '') . '</p>';
        }
        if (trim((string) $ev['abstract']) !== '') {
            $o .= '<h2><i class="fa fa-align-left me-1" aria-hidden="true"></i>Abstract</h2><div class="mb-3" style="line-height:1.6;">' . nl2br($h($ev['abstract'])) . '</div>';
        }
        $b = [];
        if (!$concluso && !empty($ev['link_streaming'])) {
            $b[] = '<a class="btn btn-danger fw-bold" href="' . $h($ev['link_streaming']) . '" target="_blank" rel="noopener"><i class="fa fa-video me-1" aria-hidden="true"></i>Segui in diretta</a>';
        }
        if (!empty($ev['link_registrazione'])) {
            $b[] = '<a class="btn btn-outline-dark fw-bold" href="' . $h($ev['link_registrazione']) . '" target="_blank" rel="noopener"><i class="fa fa-circle-play me-1" aria-hidden="true"></i>Registrazione</a>';
        }
        if (!empty($ev['slide_pdf'])) {
            $b[] = '<a class="btn btn-outline-danger fw-bold" href="' . $h($ev['slide_pdf']) . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Slide (PDF)</a>';
        }
        if ($b) {
            $o .= '<div class="d-flex flex-wrap gap-2">' . implode('', $b) . '</div>';
        }

        return $o . '</article>';
    }
}
