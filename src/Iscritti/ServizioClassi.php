<?php

declare(strict_types=1);

namespace App\Iscritti;

/** Studenti delle iscrizioni di classe: elenco delle classi dell'area e gestione dell'elenco (aggiunta, esclusione, eliminazione, presenza). */
final class ServizioClassi
{
    public function __construct(private ClassiRepository $classi)
    {
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function dellArea(int $paginaId, string $rbac): array
    {
        return $this->classi->dellArea($paginaId, $rbac);
    }

    /**
     * Aggiunge nomi in fondo all'elenco (i codici già assegnati non si toccano): salta chi c'è già (stesso cognome e nome).
     *
     * @param list<array<string, string>> $nuovi studenti letti dal testo o dal file (cognome, nome)
     * @param list<array<string, mixed>> $esistenti studenti già in elenco
     * @return array{aggiunti: int, totale: int} aggiunti ora e studenti in elenco dopo l'aggiunta
     */
    public function aggiungi(int $prenotazioneId, array $nuovi, array $esistenti): array
    {
        $chiavi = array_flip(array_map(static fn ($s) => mb_strtolower($s['cognome'] . '|' . $s['nome']), $esistenti));
        $ordine = count($esistenti);
        $aggiunti = 0;
        foreach ($nuovi as $r) {
            if (isset($chiavi[mb_strtolower($r['cognome'] . '|' . $r['nome'])])) {
                continue;
            }
            $this->classi->aggiungiStudente($prenotazioneId, $r['cognome'], $r['nome'], $ordine);
            ++$ordine;
            ++$aggiunti;
        }

        return ['aggiunti' => $aggiunti, 'totale' => $ordine];
    }

    public function escludi(int $studenteId, int $prenotazioneId, bool $escluso): void
    {
        $this->classi->impostaEscluso($studenteId, $prenotazioneId, $escluso ? 1 : 0);
    }

    public function elimina(int $studenteId, int $prenotazioneId): void
    {
        $this->classi->eliminaStudente($studenteId, $prenotazioneId);
    }

    public function svuota(int $prenotazioneId): void
    {
        $this->classi->svuota($prenotazioneId);
    }

    public function segnaPresente(int $prenotazioneId): void
    {
        $this->classi->segnaPresente($prenotazioneId);
    }
}
