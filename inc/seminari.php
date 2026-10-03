<?php
// inc/seminari.php - Seminari: relatore (dall'anagrafe di Ateneo o esterno, con ente), abstract, diretta online,
// registrazione e slide. Si compilano nel modulo dell'evento (admin/eventi.php, riquadro «Seminario») e compaiono nella
// scheda pubblica dell'evento, nell'agenda e nel calendario .ics.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!function_exists('salva_seminario_evento')) {
    // Salva i dati del seminario dal POST del modulo dell'evento; le slide sono un PDF caricato ($_FILES['slide_pdf'])
    function salva_seminario_evento($conn, int $ev_id, array $post, array $files = []): void {
        $url = function ($v) { $v = trim((string)$v); return preg_match('#^https?://[^\s]+$#i', $v) ? mb_substr($v, 0, 500) : ''; };
        $pid = trim((string)($post['relatore_persona_id'] ?? ''));
        if ($pid !== '' && !(function_exists('persona_ateneo') && persona_ateneo($conn, $pid))) $pid = '';
        $abstract = trim(strip_tags((string)($post['abstract'] ?? '')));
        db_esegui($conn, "UPDATE eventi SET relatore = ?, relatore_ente = ?, relatore_persona_id = ?, abstract = ?, link_streaming = ?, link_registrazione = ? WHERE id = ?", [
            mb_substr(trim((string)($post['relatore'] ?? '')), 0, 255), mb_substr(trim((string)($post['relatore_ente'] ?? '')), 0, 255), $pid ?: null,
            $abstract !== '' ? mb_substr($abstract, 0, 5000) : null, $url($post['link_streaming'] ?? ''), $url($post['link_registrazione'] ?? ''), $ev_id]);
        if (!empty($post['elimina_slide'])) db_esegui($conn, "UPDATE eventi SET slide_pdf = NULL WHERE id = ?", [$ev_id]);
        if (!empty($files['slide_pdf']) && ($files['slide_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = secure_upload($files['slide_pdf'], RADICE_SITO . '/uploads/', ['pdf'], ['application/pdf']);
            if ($fn) db_esegui($conn, "UPDATE eventi SET slide_pdf = ? WHERE id = ?", ['uploads/' . $fn, $ev_id]);
        }
    }
    function e_seminario(array $ev): bool {
        return trim((string)($ev['relatore'] ?? '')) !== '' || trim((string)($ev['abstract'] ?? '')) !== '';
    }
    // Riquadro pubblico del seminario: relatore, abstract, diretta (solo prima della fine), registrazione e slide
    function html_seminario(array $ev, bool $concluso = false): string {
        if (!e_seminario($ev) && empty($ev['link_streaming']) && empty($ev['link_registrazione']) && empty($ev['slide_pdf'])) return '';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $o = '<article class="ev-box p-4 mb-4 seminario">';
        if (trim((string)$ev['relatore']) !== '')
            $o .= '<h2><i class="fa fa-chalkboard-user me-1" aria-hidden="true"></i>Relatore</h2><p class="mb-3"><strong>' . $h($ev['relatore']) . '</strong>' . (trim((string)$ev['relatore_ente']) !== '' ? '<br><span class="text-secondary">' . $h($ev['relatore_ente']) . '</span>' : '') . '</p>';
        if (trim((string)$ev['abstract']) !== '') $o .= '<h2><i class="fa fa-align-left me-1" aria-hidden="true"></i>Abstract</h2><div class="mb-3" style="line-height:1.6;">' . nl2br($h($ev['abstract'])) . '</div>';
        $b = [];
        if (!$concluso && !empty($ev['link_streaming'])) $b[] = '<a class="btn btn-danger fw-bold" href="' . $h($ev['link_streaming']) . '" target="_blank" rel="noopener"><i class="fa fa-video me-1" aria-hidden="true"></i>Segui in diretta</a>';
        if (!empty($ev['link_registrazione'])) $b[] = '<a class="btn btn-outline-dark fw-bold" href="' . $h($ev['link_registrazione']) . '" target="_blank" rel="noopener"><i class="fa fa-circle-play me-1" aria-hidden="true"></i>Registrazione</a>';
        if (!empty($ev['slide_pdf'])) $b[] = '<a class="btn btn-outline-danger fw-bold" href="' . $h($ev['slide_pdf']) . '" target="_blank" rel="noopener"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Slide (PDF)</a>';
        if ($b) $o .= '<div class="d-flex flex-wrap gap-2">' . implode('', $b) . '</div>';
        return $o . '</article>';
    }
}
