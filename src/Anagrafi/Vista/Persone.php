<?php

declare(strict_types=1);

namespace App\Anagrafi\Vista;

use App\Anagrafi\Anagrafe;

/** HTML delle persone dell'anagrafe: foto, riga «Contatti» di eventi e progetti, pannello di ricerca (stesso output di prima). */
final class Persone
{
    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** Foto della persona (copia locale nella cartella del sito $radice) o una sagoma grigio chiaro. */
    public static function avatar(?string $foto, string $radice, string $alt = '', int $lato = 48, string $base = ''): string
    {
        if ($foto && preg_match('#^uploads/personale/[a-z0-9._-]+$#', $foto) && is_file($radice . '/' . $foto)) {
            return '<img src="' . htmlspecialchars($base . $foto) . '" alt="' . htmlspecialchars($alt) . '" width="' . $lato . '" height="' . $lato . '" loading="lazy" style="width:' . $lato . 'px;height:' . $lato . 'px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#f1f5f9;">';
        }

        return '<svg width="' . $lato . '" height="' . $lato . '" viewBox="0 0 48 48" role="img" aria-label="' . htmlspecialchars($alt !== '' ? $alt : 'Nessuna foto') . '" style="flex-shrink:0;border-radius:50%;">'
             . '<circle cx="24" cy="24" r="24" fill="#e5e7eb"/><circle cx="24" cy="19" r="8.5" fill="#f8fafc"/><path d="M8.5 41.5c2.6-8 8.6-12 15.5-12s12.9 4 15.5 12A23.9 23.9 0 0 1 24 48a23.9 23.9 0 0 1-15.5-6.5z" fill="#f8fafc"/></svg>';
    }

    /**
     * Riga «Contatti» di eventi e progetti: foto (o sagoma), ruolo, nome e recapiti. Chi è stato scelto dall'anagrafe porta
     * alla sua pagina nel portale (persona.php), gli altri al link indicato a mano.
     *
     * @param array<string, mixed> $rf referente scritto nella scheda
     * @param array<string, mixed>|null $pers persona dell'anagrafe, se scelta
     * @param array<string, mixed> $det scheda completa della persona (solo la foto serve qui)
     */
    public static function referentePubblico(array $rf, ?array $pers, array $det, string $nomePersona, string $radice, string $colTesto): string
    {
        $nome = trim((string) ($rf['nome'] ?? '')) ?: ($pers ? $nomePersona : '');
        $out = '<div class="pj-persona ev-persona">' . self::avatar($det['foto'] ?? '', $radice, '', 48) . '<div style="min-width:0;">';
        if (!empty($rf['ruolo'])) {
            $out .= '<div class="small text-uppercase fw-bold text-secondary" style="letter-spacing:.05em;">' . self::h($rf['ruolo']) . '</div>';
        }
        if ($nome !== '') {
            $out .= '<div class="fw-bold">';
            if ($pers) {
                $out .= '<a href="persona.php?id=' . self::h(rawurlencode($pers['id'])) . '" style="color:' . self::h($colTesto) . ';">' . self::h($nome) . '</a>';
            } elseif (!empty($rf['link']) && preg_match('#^https?://#i', $rf['link'])) {
                $out .= '<a href="' . self::h($rf['link']) . '" target="_blank" rel="noopener" style="color:' . self::h($colTesto) . ';">' . self::h($nome) . ' <i class="fa fa-arrow-up-right-from-square small" aria-hidden="true"></i><span class="visually-hidden"> (pagina personale, si apre in una nuova scheda)</span></a>';
            } else {
                $out .= self::h($nome);
            }
            $out .= '</div>';
        }
        if ($pers && $pers['ruolo'] !== '' && mb_strtolower($pers['ruolo']) !== mb_strtolower((string) ($rf['ruolo'] ?? ''))) {
            $out .= '<div class="small text-secondary">' . self::h($pers['ruolo']) . ($pers['ssd'] !== '' ? ' · ' . self::h($pers['ssd']) : '') . '</div>';
        }
        if (!empty($rf['email'])) {
            $out .= '<div class="small"><i class="fa fa-envelope me-1 text-secondary" aria-hidden="true"></i><a href="mailto:' . self::h($rf['email']) . '">' . self::h($rf['email']) . '</a></div>';
        }
        if (!empty($rf['telefono'])) {
            $out .= '<div class="small"><i class="fa fa-phone me-1 text-secondary" aria-hidden="true"></i><a href="tel:' . self::h(preg_replace('/[^0-9+]/', '', $rf['telefono'])) . '">' . self::h($rf['telefono']) . '</a></div>';
        }

        return $out . '</div></div>';
    }

    /**
     * Pannello «Cerca nell'anagrafe di Ateneo» (pagine del pannello): filtri per gruppo, ruolo e struttura + nome.
     * Scegliendo una persona il pannello lancia l'evento «persona-scelta» con i dati (assets/js/ricerca-personale.js).
     *
     * @param list<array<string, mixed>> $ruoli ruolo_cod, ruolo, n
     * @param list<array<string, mixed>> $strutture struttura_cod, struttura, n
     */
    public static function ricerca(array $ruoli, array $strutture, string $pulsante, string $righe, string $id): string
    {
        $h = self::h(...);
        $out = '<div class="ricerca-personale border rounded p-2 mb-2" style="background:#f8fafc;" data-endpoint="cerca_personale.php" data-base="../" data-pulsante="' . $h($pulsante) . '"' . ($righe !== '' ? ' data-righe="' . $h($righe) . '"' : '') . '>'
             . '<div class="small fw-bold mb-1"><i class="fa fa-magnifying-glass me-1" aria-hidden="true"></i>Cerca nell\'anagrafe di Ateneo</div>'
             . '<div class="row g-2">'
             . '<div class="col-md-3"><label class="visually-hidden" for="' . $id . 'g">Gruppo</label><select id="' . $id . 'g" class="form-select form-select-sm rp-gruppo"><option value="">Tutti i gruppi</option>';
        foreach (Anagrafe::GRUPPI_PERSONALE as $k => $v) {
            $out .= '<option value="' . $h($k) . '">' . $h($v) . '</option>';
        }
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 'r">Ruolo</label><select id="' . $id . 'r" class="form-select form-select-sm rp-ruolo"><option value="">Tutti i ruoli</option>';
        foreach ($ruoli as $x) {
            $out .= '<option value="' . $h($x['ruolo_cod']) . '">' . $h($x['ruolo']) . ' (' . (int) $x['n'] . ')</option>';
        }
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 's">Struttura</label><select id="' . $id . 's" class="form-select form-select-sm rp-struttura"><option value="">Tutte le strutture</option>';
        foreach ($strutture as $x) {
            $out .= '<option value="' . $h($x['struttura_cod']) . '">' . $h($x['struttura']) . ' (' . (int) $x['n'] . ')</option>';
        }
        $out .= '</select></div><div class="col-md-3"><label class="visually-hidden" for="' . $id . 'q">Nome o cognome</label><input type="search" id="' . $id . 'q" class="form-control form-control-sm rp-q" placeholder="Nome o cognome" autocomplete="off"></div>'
              . '</div><div class="rp-risultati mt-2" aria-live="polite"></div></div>';

        return $out;
    }

    /** Avviso al posto del pannello quando l'anagrafe non è ancora stata caricata. */
    public static function ricercaAnagrafeVuota(bool $amministratore): string
    {
        return '<div class="alert alert-light border small py-2 mb-2"><i class="fa fa-address-book me-1" aria-hidden="true"></i>L\'anagrafe del personale di Ateneo è vuota: '
             . ($amministratore ? 'caricala da <a href="anagrafe_personale.php?vista=strutture">Anagrafe personale</a>.' : 'chiedi a un amministratore di caricarla.') . ' Intanto puoi inserire le persone a mano.</div>';
    }
}
