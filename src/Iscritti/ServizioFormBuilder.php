<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Orologio;

/** Form Builder: campi personalizzati dei moduli di iscrizione di un'area, con i permessi dei gestori di area e di singolo evento. */
final class ServizioFormBuilder
{
    public function __construct(
        private FormBuilderRepository $campi,
        private Orologio $orologio,
    ) {
    }

    /**
     * Il campo è modificabile solo se appartiene all'area corrente; i gestori di singolo evento possono toccare solo i campi dei propri eventi.
     *
     * @param list<int> $eventiConsentiti
     */
    public function campoAutorizzato(int $campoId, int $paginaId, bool $gestoreArea, array $eventiConsentiti): bool
    {
        $riga = $this->campi->campoDellArea($campoId, $paginaId);
        if (!$riga) {
            return false;
        }

        return $gestoreArea || in_array((int) $riga['evento_id'], $eventiConsentiti, true);
    }

    /**
     * @param list<int> $eventiConsentiti
     */
    public static function eventoAutorizzato(int $eventoId, bool $gestoreArea, array $eventiConsentiti): bool
    {
        return $gestoreArea || ($eventoId > 0 && in_array($eventoId, $eventiConsentiti, true));
    }

    /**
     * Salva l'ordine scelto trascinando i campi (solo quelli autorizzati); l'ordine è la posizione nell'elenco ricevuto.
     *
     * @param array<int, int> $ids
     * @param list<int> $eventiConsentiti
     */
    public function salvaOrdine(array $ids, int $paginaId, bool $gestoreArea, array $eventiConsentiti): void
    {
        foreach ($ids as $pos => $id) {
            if (!$this->campoAutorizzato($id, $paginaId, $gestoreArea, $eventiConsentiti)) {
                continue;
            }
            $this->campi->impostaOrdine($id, (int) $pos);
        }
    }

    /** Sposta il campo su o giù di 15 posizioni (mai sotto 0). */
    public function sposta(int $campoId, bool $su): void
    {
        $ordine = $this->campi->ordine($campoId);
        if ($ordine !== null) {
            $this->campi->impostaOrdine($campoId, max(0, $ordine + ($su ? -15 : 15)));
        }
    }

    /**
     * Aggiunge un campo all'area ($eventoId 0 = tutti gli eventi dell'area). Il nome tecnico deriva dall'etichetta.
     * La condizione «mostra solo se un altro campo vale…» si salva se campo e valore sono indicati.
     */
    public function aggiungi(int $paginaId, int $eventoId, string $etichetta, string $tipo, string $opzioni, bool $obbligatorio, int $ordine, int $condCampoId, string $condValore): void
    {
        $etichetta = trim($etichetta);
        $nome = $this->nomeTecnico($etichetta);
        if (empty($nome)) {
            $nome = 'campo_' . $this->orologio->adesso()->getTimestamp();
        }
        $this->campi->inserisci($paginaId, $eventoId > 0 ? $eventoId : null, $nome, $etichetta, $tipo, trim($opzioni), $obbligatorio ? 1 : 0, $ordine, $this->condizione($condCampoId, $condValore));
    }

    /** Modifica un campo (etichetta, tipo, opzioni, obbligatorio, evento, condizione); il nome tecnico e l'ordine restano. */
    public function modifica(int $campoId, int $eventoId, string $etichetta, string $tipo, string $opzioni, bool $obbligatorio, int $condCampoId, string $condValore): void
    {
        $this->campi->aggiorna($campoId, $eventoId > 0 ? $eventoId : null, trim($etichetta), $tipo, trim($opzioni), $obbligatorio ? 1 : 0, $this->condizione($condCampoId, $condValore));
    }

    public function elimina(int $campoId): void
    {
        $this->campi->elimina($campoId);
    }

    /**
     * Eventi non archiviati dell'area.
     *
     * @return list<array<string, string|null>>
     */
    public function eventi(int $paginaId): array
    {
        return $this->campi->eventi($paginaId);
    }

    /**
     * Campi dell'area e dei suoi eventi, con il titolo dell'evento di destinazione.
     *
     * @return list<array<string, string|null>>
     */
    public function campi(int $paginaId): array
    {
        return $this->campi->campi($paginaId);
    }

    private function condizione(int $campoId, string $valore): ?string
    {
        $valore = trim($valore);
        if ($campoId > 0 && $valore !== '') {
            return (string) json_encode(['se_id' => $campoId, 'se_val' => $valore]);
        }

        return null;
    }

    /**
     * Nome tecnico del campo: l'etichetta con gli spazi come «_», solo lettere, cifre e «_», minuscola. Come prima, si parte dal testo
     * già «protetto» per MySQL (apici e barre con la barra davanti, \n → \\n…): le barre poi cadono, ma le lettere dei codici restano.
     */
    private function nomeTecnico(string $etichetta): string
    {
        $protetta = strtr($etichetta, ["\0" => '\\0', "\n" => '\\n', "\r" => '\\r', '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z']);

        return strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $protetta)));
    }
}
