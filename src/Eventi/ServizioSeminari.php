<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Sito;
use App\Infrastructure\Storage\Upload;

/**
 * Seminari: relatore (dall'anagrafe di Ateneo o esterno, con ente), abstract, diretta online, registrazione e slide.
 * Si compilano nel modulo dell'evento (admin/eventi.php, riquadro «Seminario»).
 */
final class ServizioSeminari
{
    public function __construct(private EventoRepository $eventi, private Anagrafe $anagrafe, private Upload $upload, private Sito $sito)
    {
    }

    /**
     * Salva i dati del seminario dal POST del modulo dell'evento; le slide sono un PDF caricato ($files['slide_pdf']).
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function salva(int $eventoId, array $post, array $files = []): void
    {
        $url = static function ($v): string {
            $v = trim((string) $v);

            return preg_match('#^https?://[^\s]+$#i', $v) ? mb_substr($v, 0, 500) : '';
        };
        $pid = trim((string) ($post['relatore_persona_id'] ?? ''));
        if ($pid !== '' && !$this->anagrafe->persona($pid)) {
            $pid = '';
        }
        $abstract = trim(strip_tags((string) ($post['abstract'] ?? '')));
        $this->eventi->salvaSeminario(
            $eventoId,
            mb_substr(trim((string) ($post['relatore'] ?? '')), 0, 255),
            mb_substr(trim((string) ($post['relatore_ente'] ?? '')), 0, 255),
            $pid ?: null,
            $abstract !== '' ? mb_substr($abstract, 0, 5000) : null,
            $url($post['link_streaming'] ?? ''),
            $url($post['link_registrazione'] ?? '')
        );
        if (!empty($post['elimina_slide'])) {
            $this->eventi->impostaSlide($eventoId, null);
        }
        if (!empty($files['slide_pdf']) && ($files['slide_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fn = $this->upload->salva($files['slide_pdf'], $this->sito->radice() . '/uploads/', ['pdf'], ['application/pdf']);
            if ($fn) {
                $this->eventi->impostaSlide($eventoId, 'uploads/' . $fn);
            }
        }
    }

    /** L'evento è un seminario (ha un relatore o un abstract). */
    public static function eSeminario(array $ev): bool
    {
        return trim((string) ($ev['relatore'] ?? '')) !== '' || trim((string) ($ev['abstract'] ?? '')) !== '';
    }
}
