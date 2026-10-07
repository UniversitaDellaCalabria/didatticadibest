<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;

/** Salvataggio della scheda di un progetto (admin/progetti.php): evento, scheda, corso, destinazione ed edizioni in un'unica transazione. */
final class ServizioSchedaProgetto
{
    public function __construct(
        private Database $db,
        private EventoRepository $eventi,
        private ProgettoRepository $progetti,
        private ServizioTurni $turni,
        private Anagrafe $anagrafe,
    ) {
    }

    /**
     * Salva il progetto (nuovo se $eventoId è 0) e le sue edizioni; se qualcosa non riesce non resta nulla a metà (l'eccezione sale).
     *
     * @param array<string, mixed> $evento titolo, luogo, desc, evid, ord, notif_csv, attestati, locandina (nuova o null), pdf (nuovo o null), elimina_locandina, elimina_pdf
     * @param array<string, mixed> $d campi generali della scheda (struttura, date, ore, min/max studenti…)
     * @param array<string, mixed> $json referenti_json, info_json, moduli_json, obiettivi, conoscenze, competenze, per_scuole, attestati
     * @param list<array<string, mixed>> $edizioni id, nome, posti, apertura, chiusura, min, max
     * @return array{evento: int, non_tolte: list<string>} id del progetto e edizioni che non si sono potute eliminare perché hanno iscritti
     */
    public function salva(int $paginaId, int $eventoId, array $evento, array $d, array $json, string|array $corsoCodice, ?string $destinazione, array $edizioni, int $listaAttesa, int $approvazione, int $convenzione): array
    {
        return $this->db->transazione(function () use ($paginaId, $eventoId, $evento, $d, $json, $corsoCodice, $destinazione, $edizioni, $listaAttesa, $approvazione, $convenzione): array {
            if ($eventoId === 0) {
                $eventoId = $this->eventi->inserisciProgetto($paginaId, $evento);
            } else {
                $this->eventi->aggiornaProgetto($eventoId, $evento, (bool) $evento['elimina_locandina'], (bool) $evento['elimina_pdf'], $evento['locandina'] ?? null, $evento['pdf'] ?? null);
            }
            $this->progetti->salvaScheda($eventoId, $d, $json);
            // Corso di studio scelto dall'anagrafe (link alla pagina del corso nella scheda pubblica)
            $this->progetti->impostaCorsi($eventoId, $this->codiciCorsi($corsoCodice));
            $this->progetti->impostaDestinazione($eventoId, $destinazione);
            $this->progetti->impostaConvenzione($eventoId, $convenzione);
            // Edizioni = turni di iscrizione (solo progetti senza rimando)
            $nonTolte = $destinazione === null ? $this->turni->salvaEdizioniProgetto($eventoId, $edizioni, $listaAttesa, $approvazione) : [];
            $this->anagrafe->assicuraCampiProgetto($paginaId);

            return ['evento' => $eventoId, 'non_tolte' => $nonTolte];
        });
    }

    /**
     * Codici dei corsi scelti, validati con l'anagrafe, senza doppioni (al massimo 12).
     *
     * @param string|list<string> $scelti un codice o l'elenco dei codici
     * @return list<string>
     */
    private function codiciCorsi(string|array $scelti): array
    {
        $out = [];
        foreach ((array) $scelti as $c) {
            $corso = $this->anagrafe->corsoStudio(trim((string) $c));
            if ($corso && !in_array($corso['codice'], $out, true)) {
                $out[] = (string) $corso['codice'];
            }
        }

        return array_slice($out, 0, 12);
    }
}
