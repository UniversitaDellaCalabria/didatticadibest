<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Voce del menu del sito (tabella menu_voci), con le sottovoci che il visitatore può vedere.
 * $conSottovoci dice se la voce ha sottovoci visibili, anche se nessuna è per il ruolo del visitatore:
 * come prima, la voce resta un menu a tendina (vuoto) e non diventa un collegamento.
 */
final class VoceMenu
{
    /** @param list<VoceMenu> $figli */
    public function __construct(
        public readonly int $id,
        public readonly int $genitoreId,
        public readonly string $etichetta,
        public readonly string $url,
        public readonly int $ordine,
        public readonly bool $nuovaScheda,
        public readonly int $ruoloVisibilita,
        public readonly bool $visibile,
        public readonly array $figli = [],
        public readonly bool $conSottovoci = false,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function daRiga(array $r): self
    {
        return new self(
            (int) $r['id'],
            (int) $r['genitore_id'],
            (string) $r['etichetta'],
            (string) ($r['url'] ?? ''),
            (int) $r['ordine'],
            (bool) $r['apri_nuova_scheda'],
            (int) $r['ruolo_visibilita_id'],
            !isset($r['visibile']) || (int) $r['visibile'] === 1,
        );
    }

    /** @param list<VoceMenu> $figli */
    public function conFigli(array $figli, bool $conSottovoci): self
    {
        return new self($this->id, $this->genitoreId, $this->etichetta, $this->url, $this->ordine, $this->nuovaScheda, $this->ruoloVisibilita, $this->visibile, $figli, $conSottovoci);
    }

    /** Attributo target per i collegamenti ('target="_blank"' o ''). */
    public function target(): string
    {
        return $this->nuovaScheda ? 'target="_blank"' : '';
    }

    /** L'indirizzo, o $senza se manca (vuoto, '0' come per empty(), o '#'): es. intestazioni di colonna che non portano altrove. */
    public function urlOppure(string $senza): string
    {
        return $this->url !== '' && $this->url !== '0' && $this->url !== '#' ? $this->url : $senza;
    }
}
