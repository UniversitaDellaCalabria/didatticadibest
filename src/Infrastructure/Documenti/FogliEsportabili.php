<?php

declare(strict_types=1);

namespace App\Infrastructure\Documenti;

use App\Infrastructure\Pdf\Documento;

/**
 * Più tabelle in un solo file (i report con più fogli): Excel con un foglio per tabella, PDF con le tabelle una dopo l'altra,
 * CSV con le tabelle separate da una riga vuota e dal loro titolo. Stesso uso di TabellaEsportabile.
 */
final class FogliEsportabili
{
    /** @param list<TabellaEsportabile> $tabelle */
    public function __construct(private readonly string $titolo, private readonly array $tabelle)
    {
    }

    /**
     * Scarica nel formato scelto il report che $costruisci prepara come Excel (ritorna il percorso del file): per xlsx si manda quel file,
     * per pdf e csv si rifanno gli stessi fogli. Non ritorna se il file parte; ritorna se il formato non c'è o il file non si può fare.
     *
     * @param callable(): ?string $costruisci
     */
    public static function scaricaDa(string $formato, string $titolo, string $nomeBase, callable $costruisci): void
    {
        if (!in_array($formato, ['xlsx', 'pdf', 'csv'], true)) {
            return;
        }
        $file = $costruisci();
        if ($file === null || !is_file($file)) {
            return;
        }
        if ($formato === 'xlsx') {
            Download::invia($file, preg_replace('/[^\w\-]+/u', '_', $nomeBase) . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        @unlink($file);
        $tabelle = [];
        foreach (Excel::ultimiFogli() as $nome => $f) {
            $tabelle[] = new TabellaEsportabile((string) $nome, (array) ($f['intestazioni'] ?? []), (array) ($f['righe'] ?? []), '', array_values((array) ($f['larghezze'] ?? [])));
        }
        (new self($titolo, $tabelle))->scarica($formato, $nomeBase);
    }

    public function csv(): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($this->tabelle as $k => $t) {
            if ($k > 0) {
                fputcsv($f, [''], ';', '"', '\\');
            }
            fputcsv($f, [$t->titolo()], ';', '"', '\\');
            foreach ($t->righeCsv() as $r) {
                fputcsv($f, $r, ';', '"', '\\');
            }
        }
        rewind($f);
        $s = (string) stream_get_contents($f);
        fclose($f);

        return $s;
    }

    public function xlsx(): ?string
    {
        $fogli = [];
        foreach ($this->tabelle as $t) {
            $fogli[$t->titolo()] = $t->foglio();
        }

        return Excel::crea($fogli);
    }

    public function pdf(): string
    {
        $pdf = new Documento(['piede' => $this->titolo . ' – stampato il ' . date('d/m/Y'), 'info' => ['Title' => $this->titolo, 'Creator' => 'Portale DiBEST']]);
        if (max(array_map(static fn (TabellaEsportabile $t) => $t->numeroColonne(), $this->tabelle) ?: [0]) > 5) {
            [$pdf->larg, $pdf->alt] = [$pdf->alt, $pdf->larg];
        }
        $pdf->sx = $pdf->dx = 40;
        $pdf->alto = 40;
        $pdf->y = $pdf->alto;
        $pdf->paragrafo($this->titolo, ['font' => 'H', 'b' => true, 'sz' => 17, 'al' => 'sinistra', 'dopo' => 10]);
        foreach ($this->tabelle as $t) {
            $pdf->serve(90);
            $t->disegna($pdf);
            $pdf->spazio(10);
        }

        return $pdf->pdf();
    }

    public function scarica(string $formato, string $nomeBase): void
    {
        $nome = preg_replace('/[^\w\-]+/u', '_', $nomeBase) ?: 'report';
        if ($formato === 'csv') {
            $tmp = tempnam(sys_get_temp_dir(), 'csv');
            file_put_contents($tmp, $this->csv());
            Download::invia($tmp, "$nome.csv", 'text/csv; charset=utf-8');
        }
        if ($formato === 'xlsx' && ($tmp = $this->xlsx()) !== null) {
            Download::invia($tmp, "$nome.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        if ($formato === 'pdf') {
            $tmp = tempnam(sys_get_temp_dir(), 'pdf');
            file_put_contents($tmp, $this->pdf());
            Download::invia($tmp, "$nome.pdf", 'application/pdf');
        }
    }
}
