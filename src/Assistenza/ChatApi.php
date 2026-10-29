<?php

declare(strict_types=1);

namespace App\Assistenza;

/**
 * Le risposte che le pagine di chat chiedono per aggiornarsi (JSON): la casella e le chat degli operatori, la chat di chi chiede aiuto. Qui ci sono solo i
 * dati; i permessi (chi è operatore, di chi è la chat) li controlla l'endpoint prima di chiamare. Ogni risposta ha «ok» e, se qualcosa non va, «errore».
 */
final class ChatApi
{
    public function __construct(private ServizioAssistenza $servizio, private OperatoriAssistenza $operatori)
    {
    }

    /** @return array{ok: false, errore: string} */
    private static function errore(string $testo): array
    {
        return ['ok' => false, 'errore' => $testo];
    }

    /**
     * @param array<string, string|null> $m
     * @return array{id: int, da: string, tipo: string, testo: string, autore: string, quando: string, extra: array<string, mixed>|null}
     */
    private static function messaggio(array $m): array
    {
        $extra = $m['da'] === Costanti::DA_BOT && (string) ($m['extra'] ?? '') !== '' ? json_decode((string) $m['extra'], true) : null;

        return ['id' => (int) $m['id'], 'da' => (string) $m['da'], 'tipo' => (string) $m['tipo'], 'testo' => (string) $m['testo'], 'autore' => (string) $m['autore'], 'quando' => (string) $m['creato_il'], 'extra' => is_array($extra) ? $extra : null];
    }

    /**
     * @param list<array<string, string|null>> $messaggi
     * @return list<array<string, mixed>>
     */
    private static function messaggi(array $messaggi): array
    {
        return array_map(self::messaggio(...), $messaggi);
    }

    /** @param array<string, string|null> $c @return array<string, mixed> */
    private static function convPerOperatore(array $c): array
    {
        return [
            'id' => (int) $c['id'], 'nome' => (string) $c['nome'], 'email' => (string) $c['email'], 'canale' => (string) $c['canale'],
            'argomento' => (string) $c['argomento'], 'argomento_nome' => Costanti::nomeArgomento((string) $c['argomento']), 'contesto_testo' => (string) $c['contesto_testo'],
            'stato' => (string) $c['stato'], 'stato_nome' => Costanti::STATI[(string) $c['stato']] ?? (string) $c['stato'],
            'operatore_id' => (int) $c['operatore_id'], 'operatore_nome' => (string) $c['operatore_nome'],
            'creata' => (string) $c['creato_il'], 'ultimo' => (string) $c['ultimo_il'], 'valutazione' => $c['valutazione'] === null ? null : (int) $c['valutazione'],
            'da_leggere' => (int) ($c['da_leggere'] ?? 0), 'totale' => (int) ($c['totale'] ?? 0),
        ];
    }

    /**
     * La casella di un operatore: le conversazioni della scheda scelta e i numeri di tutte le schede.
     *
     * @param array{id: int, nome: string, uffici?: list<int>|null} $operatore gli uffici dicono quali chat può vedere (assente o null = tutte)
     * @return array<string, mixed>
     */
    public function elenco(array $operatore, string $vista): array
    {
        $vista = in_array($vista, [ConversazioniRepository::VISTA_DA_PRENDERE, ConversazioniRepository::VISTA_MIE, ConversazioniRepository::VISTA_TUTTE, ConversazioniRepository::VISTA_CHIUSE, ConversazioniRepository::VISTA_BOT], true)
            ? $vista : ConversazioniRepository::VISTA_DA_PRENDERE;

        return [
            'ok' => true, 'vista' => $vista, 'contatori' => $this->servizio->contatori($operatore['id'], $operatore['uffici'] ?? null),
            'elenco' => array_map(self::convPerOperatore(...), $this->servizio->elenco($vista, $operatore['id'], $operatore['uffici'] ?? null)),
        ];
    }

    /**
     * Una chat aperta da un operatore: i messaggi dopo $dopoId (note comprese). Aprirla segna letti i messaggi della persona.
     *
     * @return array<string, mixed>
     */
    public function chatOperatore(int $id, int $dopoId, ?array $uffici = null): array
    {
        $c = $this->servizio->perId($id);
        if ($c === null || !$this->servizio->visibileA($c, $uffici)) {
            return self::errore('Conversazione non trovata.');
        }
        $this->servizio->apertaDa($c, Costanti::DA_OPERATORE);
        $m = self::messaggi($this->servizio->messaggi($c, $dopoId, true));

        return ['ok' => true, 'conv' => self::convPerOperatore($this->servizio->perId($id) ?? $c), 'messaggi' => $m, 'colleghi' => $this->colleghi((int) $c['ufficio_id'] ?: null)];
    }

    /** @return list<array{id: int, nome: string}> */
    private function colleghi(?int $ufficioId = null): array
    {
        $out = [];
        foreach ($this->operatori->colleghi($ufficioId) as $id => $nome) {
            $out[] = ['id' => $id, 'nome' => $nome];
        }

        return $out;
    }

    /**
     * Un'azione di un operatore su una chat: invia (testo, nota), prendi, passa (a), chiudi, riapri.
     *
     * @param array{id: int, nome: string} $operatore
     * @param array<string, mixed> $dati
     * @return array<string, mixed>
     */
    public function azioneOperatore(string $azione, int $id, array $operatore, array $dati): array
    {
        $c = $this->servizio->perId($id);
        if ($c === null || !$this->servizio->visibileA($c, $operatore['uffici'] ?? null)) {
            return self::errore('Conversazione non trovata.');
        }
        $err = match ($azione) {
            'invia' => !empty($dati['nota']) ? $this->servizio->nota($c, $operatore, (string) ($dati['testo'] ?? '')) : $this->servizio->rispondi($c, $operatore, (string) ($dati['testo'] ?? '')),
            'prendi' => $this->servizio->prendiInCarico($c, $operatore),
            'passa' => $this->passa($c, $operatore, (int) ($dati['a'] ?? 0)),
            'chiudi' => $this->servizio->chiudi($c, Costanti::DA_OPERATORE),
            'riapri' => $this->servizio->riapri($c),
            default => 'Azione non valida.',
        };

        return $err === null ? ['ok' => true] : self::errore($err);
    }

    /** @param array<string, string|null> $c @param array{id: int, nome: string} $da */
    private function passa(array $c, array $da, int $a): ?string
    {
        $colleghi = $this->operatori->colleghi((int) ($c['ufficio_id'] ?? 0) ?: null);
        if (!isset($colleghi[$a])) {
            return 'Scegli un collega dell\'ufficio.';
        }

        return $this->servizio->passaA($c, $da, ['id' => $a, 'nome' => $colleghi[$a]]);
    }

    /**
     * La chat vista da chi ha chiesto aiuto: niente note interne, niente dati degli operatori oltre al nome.
     *
     * @param array<string, string|null> $conv
     * @return array<string, mixed>
     */
    public function chatUtente(array $conv, int $dopoId): array
    {
        $this->servizio->apertaDa($conv, Costanti::DA_UTENTE);
        $c = $this->servizio->perId((int) $conv['id']) ?? $conv;

        return [
            'ok' => true,
            'conv' => [
                'stato' => (string) $c['stato'], 'argomento_nome' => Costanti::nomeArgomento((string) $c['argomento']), 'operatore_nome' => (string) $c['operatore_nome'],
                'chiusa' => $c['stato'] === Costanti::STATO_CHIUSA, 'valutata' => $c['valutazione'] !== null, 'bot' => $c['stato'] === Costanti::STATO_BOT,
            ],
            'messaggi' => self::messaggi($this->servizio->messaggi($c, $dopoId, false)),
        ];
    }

    /**
     * Un'azione di chi ha chiesto aiuto: invia (testo), chiudi, valuta (utile) e i pulsanti di Didacticus (bot_utile, bot_non_utile, operatore, bot_voce, bot_argomento con «valore»).
     *
     * @param array<string, string|null> $conv
     * @param array<string, mixed> $dati
     * @return array<string, mixed>
     */
    public function azioneUtente(string $azione, array $conv, array $dati): array
    {
        $err = match ($azione) {
            'invia' => $this->servizio->scriviComeUtente($conv, (string) ($dati['testo'] ?? '')),
            'chiudi' => $this->servizio->chiudi($conv, Costanti::DA_UTENTE),
            'valuta' => $this->servizio->valuta($conv, !empty($dati['utile'])),
            'bot_utile' => $this->servizio->azioneBot($conv, 'utile'),
            'bot_non_utile' => $this->servizio->azioneBot($conv, 'non_utile'),
            'operatore' => $this->servizio->azioneBot($conv, 'operatore'),
            'bot_ufficio' => $this->servizio->azioneBot($conv, 'ufficio', (string) ($dati['valore'] ?? '')),
            'bot_corso' => $this->servizio->azioneBot($conv, 'corso', (string) ($dati['valore'] ?? '')),
            'bot_voce' => $this->servizio->azioneBot($conv, 'voce', (string) ($dati['valore'] ?? '')),
            'bot_argomento' => $this->servizio->azioneBot($conv, 'argomento', (string) ($dati['valore'] ?? '')),
            default => 'Azione non valida.',
        };

        return $err === null ? ['ok' => true] : self::errore($err);
    }
}
