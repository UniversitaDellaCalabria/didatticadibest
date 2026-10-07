<?php

declare(strict_types=1);

namespace Tests\Doppi;

use App\Iscrizioni\RegoleFsl;

/** Regole di classe e convenzioni dei test: stesse regole di inc/fsl.php, con le convenzioni del registro in un array. */
final class IscrizioniRegoleFslFinte implements RegoleFsl
{
    /** @var array<string, array<string, mixed>> convenzione valida per codice scuola */
    public array $convenzioni = [];

    public function prenotazioneDiClasse(bool $eProgetto, ?array $dettagli): bool
    {
        if ($eProgetto) {
            return (int) ($dettagli['per_scuole'] ?? 1) === 1;
        }

        return (int) ($dettagli['attestati'] ?? 0) === 1 || (int) ($dettagli['dedicata_scuole'] ?? 0) === 1 || (int) ($dettagli['convenzione'] ?? 0) === 1;
    }

    public function attestatiDiClasse(array $p): bool
    {
        $eProgetto = ($p['evento_tipo'] ?? $p['tipo'] ?? 'evento') === 'progetto';

        return (int) ($p['attestati'] ?? 0) === 1 && $this->prenotazioneDiClasse($eProgetto, $p);
    }

    public function campoFormVisibile(array $campo, bool $eProgetto, ?array $dettagli): bool
    {
        if (($campo['nome_campo'] ?? '') !== CAMPO_PARTECIPANTI) {
            return true;
        }

        return $this->prenotazioneDiClasse($eProgetto, $dettagli);
    }

    public function istruzioniConvenzione(array $areaCfg, bool $perEmail = false, string $codice = '', bool $inAttesa = true): string
    {
        return '[istruzioni convenzione ' . ($perEmail ? 'email' : 'pagina') . ' ' . $codice . ']';
    }

    public function avvisoDocumentiClasse(?array $dettagli, bool $eProgetto, string $codice, ?string $dataTurno): string
    {
        return (int) ($dettagli['convenzione'] ?? 0) === 1 ? '[avviso documenti ' . $codice . ']' : '';
    }

    public function periodoAttivita(?string $inizio, ?string $fine, ?string $dataTurno = null): array
    {
        $dal = $inizio ?: ($dataTurno ?: ($fine ?: null));
        $al = $fine ?: ($dataTurno ?: $dal);
        if (!$dal) {
            $dal = $al = '2026-10-05';
        }

        return [$dal, $al];
    }

    public function convenzioneValida(?string $codiceScuola, ?string $dal = null, ?string $al = null): ?array
    {
        return $this->convenzioni[(string) $codiceScuola] ?? null;
    }

    public function convenzioniDellaScuola(?string $codiceScuola): array
    {
        return isset($this->convenzioni[(string) $codiceScuola]) ? [$this->convenzioni[(string) $codiceScuola]] : [];
    }
}
