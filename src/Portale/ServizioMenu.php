<?php

declare(strict_types=1);

namespace App\Portale;

/**
 * Menu del sito pubblico (testata, computer e telefono): voci visibili, su tre livelli (voce, colonna, collegamenti),
 * filtrate per il visitatore (voci solo per chi è entrato o per un ruolo).
 */
final class ServizioMenu
{
    private const LIVELLI = 3;

    public function __construct(private MenuRepository $menu)
    {
    }

    /** @return list<VoceMenu> voci di primo livello con le sottovoci che il visitatore può vedere */
    public function menuPrincipale(Visitatore $visitatore): array
    {
        $perGenitore = [];
        foreach ($this->menu->vociVisibili() as $v) {
            $perGenitore[$v->genitoreId][] = $v;
        }

        return $this->albero($perGenitore, 0, $visitatore, 1);
    }

    /**
     * @param array<int, list<VoceMenu>> $perGenitore
     * @return list<VoceMenu>
     */
    private function albero(array $perGenitore, int $genitore, Visitatore $visitatore, int $livello): array
    {
        $out = [];
        foreach ($perGenitore[$genitore] ?? [] as $v) {
            if (!$visitatore->vede($v->ruoloVisibilita)) {
                continue;
            }
            $conSottovoci = $livello < self::LIVELLI && !empty($perGenitore[$v->id]);
            $out[] = $v->conFigli($conSottovoci ? $this->albero($perGenitore, $v->id, $visitatore, $livello + 1) : [], $conSottovoci);
        }

        return $out;
    }
}
