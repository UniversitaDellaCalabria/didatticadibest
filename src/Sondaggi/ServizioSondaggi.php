<?php

declare(strict_types=1);

namespace App\Sondaggi;

use App\Eventi\Turni;
use App\Infrastructure\Mail\Mailer;
use App\Portale\ColoriAree;

/**
 * Sondaggi di gradimento anonimi: compilazione dal link personale, gestione delle domande, invio del link per email,
 * statistiche ed esportazione dei risultati.
 */
final class ServizioSondaggi
{
    private const OGGETTO_PREDEFINITO = 'La tua opinione è importante! Sondaggio Evento: {TITOLO_EVENTO}';
    private const CORPO_PREDEFINITO = '<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ringraziamo per aver partecipato all\'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>La tua opinione per noi è fondamentale. Ti invitiamo a compilare il questionario di gradimento in forma <strong>totalmente anonima</strong>.</p><div style="text-align:center;margin:35px 0;">{LINK_SONDAGGIO}</div>';

    public function __construct(private SondaggioRepository $repo, private Mailer $mailer, private ColoriAree $colori)
    {
    }

    // ---- Compilazione ----------------------------------------------------------------------------------------------

    /**
     * Prenotazione (con evento) del link personale del questionario.
     *
     * @return array<string, mixed>|null
     */
    public function prenotazionePerToken(string $token): ?array
    {
        return $this->repo->prenotazionePerToken($token);
    }

    /** @return array<string, mixed>|null */
    public function attivoDelEvento(int $eventoId): ?array
    {
        return $this->repo->attivoDelEvento($eventoId);
    }

    /** @return list<array<string, mixed>> */
    public function domande(int $sondaggioId): array
    {
        return $this->repo->domande($sondaggioId);
    }

    /**
     * Salva le risposte (anonime) e segna la prenotazione come completata. Accetta solo risposte a domande di questo
     * sondaggio; le obbligatorie non condizionali devono avere risposta. Ritorna false con il motivo in $errore.
     *
     * @param array<int|string, mixed> $risposte risposte per id della domanda (testo, oppure elenco per scelta multipla e matrice)
     */
    public function salvaRisposte(int $sondaggioId, array $risposte, int $prenotazioneId, ?string &$errore = null): bool
    {
        $errore = null;

        // Accetta solo risposte a domande di QUESTO sondaggio
        $domande = [];
        foreach ($this->repo->domande($sondaggioId) as $d) {
            $domande[(int) $d['id']] = $d;
        }

        $valori = [];
        foreach ($risposte as $dId => $valore) {
            $dId = (int) $dId;
            if (!isset($domande[$dId])) {
                continue;
            }
            $val = is_array($valore) ? json_encode($valore, JSON_UNESCAPED_UNICODE) : trim((string) $valore);
            if ($val !== '' && $val !== '[]') {
                $valori[$dId] = $val;
            }
        }

        // Obbligatorie (le condizionali restano verificate solo lato browser, perché possono essere nascoste)
        foreach ($domande as $dId => $d) {
            if (!empty($d['obbligatorio']) && empty($d['condizione_json']) && $d['tipo'] !== 'separator' && !isset($valori[$dId])) {
                $errore = 'Rispondi a tutte le domande obbligatorie.';

                return false;
            }
        }

        $errore = $this->repo->registraRisposte($sondaggioId, $prenotazioneId, $valori);

        return $errore === null;
    }

    // ---- Gestione --------------------------------------------------------------------------------------------------

    /**
     * Evento, sondaggio o domanda dell'area e visibile all'utente.
     *
     * @param string $tipo evento | sondaggio | domanda
     * @param string $filtroRbac frammento SQL ("AND e.id IN (…)") di admin_header.php
     */
    public function autorizzato(string $tipo, int $id, int $areaId, string $filtroRbac): bool
    {
        return $this->repo->autorizzato($tipo, $id, $areaId, $filtroRbac);
    }

    /**
     * Eventi dell'area tra cui scegliere il sondaggio.
     *
     * @return list<array<string, string|null>>
     */
    public function eventiDellArea(int $areaId, bool $archivio, string $filtroRbac): array
    {
        return $this->repo->eventiDellArea($areaId, $archivio ? 1 : 0, $filtroRbac);
    }

    /**
     * Sondaggio di un evento con le sue domande e le statistiche dei risultati; null se l'evento non ha sondaggio.
     *
     * @return array{sondaggio: array<string, string|null>, domande: list<array<string, string|null>>, statistiche: array<int|string, array<string, mixed>>}|null
     */
    public function scheda(int $eventoId): ?array
    {
        $sondaggio = $this->repo->delEvento($eventoId);
        if (!$sondaggio) {
            return null;
        }
        $id = (int) $sondaggio['id'];

        return [
            'sondaggio' => $sondaggio,
            'domande' => $this->repo->domandeAdmin($id),
            'statistiche' => StatisticheSondaggio::calcola($this->repo->risposteConDomande($id)),
        ];
    }

    public function crea(int $eventoId, string $titolo): void
    {
        $this->repo->crea($eventoId, $titolo);
    }

    /**
     * Aggiunge una domanda in fondo. La condizione (mostra solo se un'altra domanda ha una certa risposta) è vuota
     * se manca la domanda o il valore.
     */
    public function aggiungiDomanda(int $sondaggioId, string $testo, string $tipo, string $opzioni, bool $obbligatoria, int $condizioneDomandaId, string $condizioneValore): void
    {
        $this->repo->aggiungiDomanda($sondaggioId, $testo, $tipo, $opzioni, $obbligatoria ? 1 : 0, self::condizioneJson($condizioneDomandaId, $condizioneValore));
    }

    public function modificaDomanda(int $domandaId, string $testo, string $tipo, string $opzioni, bool $obbligatoria, int $condizioneDomandaId, string $condizioneValore): void
    {
        $this->repo->modificaDomanda($domandaId, $testo, $tipo, $opzioni, $obbligatoria ? 1 : 0, self::condizioneJson($condizioneDomandaId, $condizioneValore));
    }

    /** JSON della condizione di una domanda ({"se_id": …, "se_val": …}) o stringa vuota. */
    public static function condizioneJson(int $domandaId, string $valore): string
    {
        if ($domandaId > 0 && $valore !== '') {
            return (string) json_encode(['se_id' => $domandaId, 'se_val' => $valore]);
        }

        return '';
    }

    /** Sposta una domanda su o giù di 15 punti di ordine (le domande sono a passi di 10). */
    public function spostaDomanda(int $domandaId, bool $su): void
    {
        $this->repo->spostaDomanda($domandaId, $su ? -15 : 15);
    }

    public function impostaOrdineDomanda(int $domandaId, int $posizione): void
    {
        $this->repo->impostaOrdineDomanda($domandaId, $posizione);
    }

    public function attiva(int $sondaggioId, bool $attivo): void
    {
        $this->repo->impostaAttivo($sondaggioId, $attivo ? 1 : 0);
    }

    public function eliminaDomanda(int $domandaId): void
    {
        $this->repo->eliminaDomanda($domandaId);
    }

    /** Elimina il sondaggio con domande e risposte. */
    public function elimina(int $sondaggioId): void
    {
        $this->repo->elimina($sondaggioId);
    }

    /**
     * Risultati per l'esportazione in Excel: le domande e, per ogni compilazione (data), le risposte per domanda.
     *
     * @return array{domande: array<string, array<string, string|null>>, risposte: array<string, array<string, string|null>>}
     */
    public function esportazione(int $sondaggioId): array
    {
        $risposte = [];
        foreach ($this->repo->risposteEsportazione($sondaggioId) as $r) {
            $risposte[(string) $r['data_risposta']][$r['domanda_id']] = $r['risposta'];
        }

        return ['domande' => $this->repo->domandeEsportazione($sondaggioId), 'risposte' => $risposte];
    }

    // ---- Invio del link del questionario ---------------------------------------------------------------------------

    /**
     * Manda a chi ha partecipato (e non ha ancora compilato) il link personale del questionario; crea il link se manca.
     * Il testo è quello delle impostazioni di sistema, con segnaposto {NOME} {COGNOME} {MATRICOLA} {TITOLO_EVENTO}
     * {DATA_TURNO} {ORARIO_TURNO} {LUOGO} {LINK_SONDAGGIO}. Ritorna quante email sono partite.
     *
     * @param string $dominio indirizzo del sito per i link (https://host/cartella)
     */
    public function inviaLink(int $eventoId, string $dominio): int
    {
        $sys = $this->repo->impostazioniEmail();
        $oggettoBase = ($sys['email_sondaggio_oggetto'] ?? '') ?: self::OGGETTO_PREDEFINITO;
        $corpoBase = ($sys['email_sondaggio_corpo'] ?? '') ?: self::CORPO_PREDEFINITO;
        $inviate = 0;
        foreach ($this->repo->prenotazioniPerInvio($eventoId) as $p) {
            if (empty($p['email'])) {
                continue;
            }
            if ((int) ($p['sondaggio_completato'] ?? 0) === 1) {
                continue;
            }
            if ((int) ($p['abilita_presenze'] ?? 1) === 1 && (int) $p['presente'] === 0) {
                continue;
            }
            $token = $p['token_sondaggio'] ?? '';
            if (empty($token)) {
                $token = bin2hex(random_bytes(16));
                $this->repo->impostaToken((int) $p['id'], $token);
            }
            $url = $dominio . '/sondaggio.php?token=' . $token;
            $bottone = "<a href='$url' style='background-color:#17a2b8;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:bold;font-size:16px;'>📝 Compila il Questionario</a>";
            $cerca = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_SONDAGGIO}'];
            $dataTurno = !empty($p['data_turno']) ? date('d/m/Y', (int) strtotime((string) $p['data_turno'])) : '';
            $sostituisci = [(string) $p['nome'], (string) $p['cognome'], (string) $p['matricola'], (string) $p['evento_titolo'], $dataTurno, Turni::orario($p), (string) $p['evento_luogo'], $bottone];
            $this->mailer->invia(
                (string) $p['email'],
                str_replace($cerca, $sostituisci, $oggettoBase),
                str_replace($cerca, $sostituisci, $corpoBase),
                $this->colori->delTurno((int) $p['turno_id'])
            );
            $inviate++;
        }

        return $inviate;
    }
}
