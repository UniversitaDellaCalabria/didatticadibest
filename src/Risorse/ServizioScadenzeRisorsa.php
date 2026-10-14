<?php

declare(strict_types=1);

namespace App\Risorse;

/**
 * Le scadenze di una risorsa (bollo, assicurazione, tagliando, revisione di un'auto; taratura di uno strumento…): le inserisce l'admin, il responsabile
 * le rinnova. Prima della data (preavviso) e il giorno dopo la scadenza, se non rinnovata, parte un'email a chi si occupa della risorsa.
 */
final class ServizioScadenzeRisorsa
{
    /** Nomi proposti nel pannello (si può scrivere qualunque altro). */
    public const PROPOSTE = ['Bollo', 'Assicurazione', 'Tagliando', 'Revisione', 'Taratura', 'Manutenzione'];

    public function __construct(private ScadenzaRisorsaRepository $scadenze)
    {
    }

    /**
     * Stato di una scadenza alla data: «scaduta» (la data è passata), «in_scadenza» (entro il preavviso), «ok»; con i giorni che mancano (negativi se scaduta).
     *
     * @param array<string, mixed> $s
     * @return array{stato: string, giorni: int}
     */
    public static function stato(array $s, ?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');
        $g = (int) round(((int) strtotime((string) $s['scade_il']) - (int) strtotime($oggi)) / 86400);

        return ['stato' => $g < 0 ? 'scaduta' : ($g <= (int) $s['preavviso_giorni'] ? 'in_scadenza' : 'ok'), 'giorni' => $g];
    }

    /**
     * Le scadenze della risorsa con lo stato, dalla più vicina.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(int $risorsaId, ?string $oggi = null): array
    {
        return array_map(fn ($s) => $s + self::stato($s, $oggi), $this->scadenze->elenco($risorsaId));
    }

    /**
     * Le scadenze in scadenza o scadute delle risorse indicate (per il riquadro del responsabile).
     *
     * @param list<int> $risorseIds
     * @return list<array<string, mixed>>
     */
    public function daControllare(array $risorseIds, ?string $oggi = null): array
    {
        return array_map(fn ($s) => $s + self::stato($s, $oggi), $this->scadenze->dellaRisorseConAvviso($risorseIds, $oggi ?? date('Y-m-d')));
    }

    /**
     * Crea (id = 0) o modifica una scadenza dal modulo del pannello. Ritorna [errore, id].
     *
     * @param array<string, mixed> $post
     * @return array{0: ?string, 1: int}
     */
    public function salva(int $risorsaId, int $id, array $post): array
    {
        $nome = mb_substr(trim((string) ($post['nome'] ?? '')), 0, 100);
        $data = (string) ($post['scade_il'] ?? '');
        if ($nome === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
            return ['Scrivi cosa scade (es. «Assicurazione») e la data.', $id];
        }
        if ($id && (!($x = $this->scadenze->perId($id)) || (int) $x['risorsa_id'] !== $risorsaId)) {
            return ['Scadenza non trovata.', $id];
        }
        $id = $this->scadenze->salva($risorsaId, $id, $nome, $data, max(0, min(365, (int) ($post['preavviso_giorni'] ?? 30))), mb_substr(trim((string) ($post['note'] ?? '')), 0, 255));

        return [null, $id];
    }

    /** Rinnova una scadenza con la nuova data (nel futuro). Ritorna l'errore, null se fatto. */
    public function rinnova(int $risorsaId, int $id, string $nuovaData): ?string
    {
        $x = $this->scadenze->perId($id);
        if (!$x || (int) $x['risorsa_id'] !== $risorsaId) {
            return 'Scadenza non trovata.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nuovaData) || $nuovaData <= date('Y-m-d') || $nuovaData <= (string) $x['scade_il']) {
            return 'La nuova scadenza deve essere una data futura, dopo quella attuale.';
        }
        $this->scadenze->rinnova($id, $nuovaData);

        return null;
    }

    public function elimina(int $risorsaId, int $id): void
    {
        $x = $this->scadenze->perId($id);
        if ($x && (int) $x['risorsa_id'] === $risorsaId) {
            $this->scadenze->elimina($id);
        }
    }

    /** @return list<array<string, string|null>> da avvisare oggi (con i dati della risorsa), ognuna con «scaduta» vero/falso */
    public function daAvvisare(?string $oggi = null): array
    {
        $oggi ??= date('Y-m-d');

        return array_map(fn ($s) => $s + ['scaduta' => (string) $s['scade_il'] < $oggi ? '1' : '0'], $this->scadenze->daAvvisare($oggi));
    }

    public function segnaAvviso(int $id, bool $scaduta): void
    {
        $this->scadenze->segnaAvviso($id, $scaduta);
    }
}
