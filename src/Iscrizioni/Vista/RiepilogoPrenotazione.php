<?php

declare(strict_types=1);

namespace App\Iscrizioni\Vista;

use App\Eventi\Turni;

/** Tabella di riepilogo di una prenotazione per le email a gestori, referenti e indirizzi aggiuntivi. */
final class RiepilogoPrenotazione
{
    private const STATI = [
        'confermata' => 'Confermata', 'in_attesa' => "In lista d'attesa", 'da_approvare' => 'Da approvare',
        'richiesta_conferma' => 'Posto offerto, da confermare', 'annullata' => 'Annullata', 'rifiutata' => 'Rifiutata', 'scaduta' => 'Scaduta',
    ];

    /**
     * Dati anagrafici, evento e turno, stato e TUTTI i campi aggiuntivi del modulo con la loro etichetta (gli allegati diventano link).
     *
     * @param array<string, string|null> $p prenotazione completa (NotificheRepository::prenotazioneCompleta)
     * @param array<string, string> $etichette nome_campo => etichetta, nell'ordine del modulo
     * @return array{oggetto_evento: string|null, html: string, html_senza_admin: string, dati: array<string, string|null>}
     */
    public static function costruisci(array $p, array $etichette, string $urlBase): array
    {
        $righe = [
            'Partecipante' => trim($p['nome'] . ' ' . $p['cognome']),
            'Email' => $p['email'],
            'Matricola' => $p['matricola'] ?? '',
            'Area' => $p['area_titolo'],
            'Evento' => $p['evento_titolo'],
            'Turno' => Turni::etichetta($p),
            'Luogo' => $p['luogo'] ?? '',
            'Posti' => (string) max(1, (int) $p['num_posti']),
            'Stato' => self::STATI[$p['stato'] ?? 'confermata'] ?? (string) $p['stato'],
            'Convenzione' => ['si' => 'Già stipulata (dichiarato dalla scuola)', 'no' => 'Da stipulare: prenotazione in attesa della convenzione'][$p['convenzione'] ?? ''] ?? '',
            'Codice' => $p['codice_prenotazione'],
            'Registrata il' => !empty($p['data_prenotazione']) ? date('d/m/Y H:i', (int) strtotime($p['data_prenotazione'])) : '',
        ];
        $htmlRighe = '';
        foreach ($righe as $etichetta => $valore) {
            if ($valore === '' || $valore === null) {
                continue;
            }
            $htmlRighe .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($etichetta) . '</td>'
                         . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;font-weight:bold;">' . htmlspecialchars((string) $valore) . '</td></tr>';
        }

        // Campi aggiuntivi: prima quelli nell'ordine del modulo, poi eventuali valori di campi non più presenti
        $custom = json_decode((string) ($p['dati_custom_json'] ?? ''), true) ?: [];
        if ($custom) {
            $chiavi = array_merge(array_values(array_intersect(array_keys($etichette), array_keys($custom))), array_diff(array_keys($custom), array_keys($etichette)));
            $htmlRighe .= '<tr><td colspan="2" style="padding:12px 10px 4px;font-weight:bold;color:#1f2937;">Informazioni aggiuntive</td></tr>';
            foreach ($chiavi as $k) {
                $v = trim((string) $custom[$k]);
                if ($v === '') {
                    continue;
                }
                if (strpos($v, 'uploads/allegati_prenotazioni/') !== false) {
                    $link = [];
                    foreach (array_filter(array_map('trim', explode(',', $v))) as $i => $path) {
                        $link[] = '<a href="' . htmlspecialchars($urlBase . '/' . ltrim($path, '/')) . '">Allegato ' . ($i + 1) . '</a>';
                    }
                    $cella = implode(' · ', $link);
                } else {
                    $cella = nl2br(htmlspecialchars($v));
                }
                $htmlRighe .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;color:#6b7280;vertical-align:top;">' . htmlspecialchars($etichette[$k] ?? ucfirst(str_replace('_', ' ', (string) $k))) . '</td>'
                             . '<td style="padding:6px 10px;border-bottom:1px solid #e5e7eb;">' . $cella . '</td></tr>';
            }
        }

        $linkAdmin = $urlBase . '/admin/iscritti.php?p_id=' . (int) $p['pagina_id'] . '&f_turno=' . (int) $p['turno_id'];
        $tabella = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;margin:8px 0 16px;">' . $htmlRighe . '</table>';
        $html = $tabella . '<p><a href="' . htmlspecialchars($linkAdmin) . '" style="background:#B30000;color:#ffffff;padding:10px 18px;text-decoration:none;border-radius:6px;font-weight:bold;">Apri gli iscritti del turno</a></p>';

        // 'html_senza_admin': per i referenti dei progetti, che non hanno accesso all'amministrazione
        return ['oggetto_evento' => $p['evento_titolo'], 'html' => $html, 'html_senza_admin' => $tabella, 'dati' => $p];
    }
}
