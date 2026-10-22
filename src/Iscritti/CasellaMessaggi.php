<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\IdsGestori;
use App\Assistenza\Costanti as CostantiAssistenza;
use App\Assistenza\OperatoriAssistenza;
use App\Assistenza\ServizioAssistenza;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Fsl\MessaggiConvenzioneRepository;
use App\Portale\AreeRepository;
use App\Portale\Sezioni;
use App\Uffici\ServizioUffici;

/**
 * Casella unica dei messaggi di chi lavora nel pannello: le conversazioni sulle prenotazioni di tutte le aree in cui si gestiscono
 * gli iscritti e le risposte delle scuole sulla convenzione FSL, ognuna con il modulo di provenienza. I permessi sono quelli di sempre:
 * un gestore di singole attività vede solo le conversazioni di quelle attività.
 */
final class CasellaMessaggi
{
    public const PRENOTAZIONE = 'prenotazione';
    public const CONVENZIONE = 'convenzione';
    public const ASSISTENZA = 'assistenza';

    public function __construct(
        private AreeRepository $aree,
        private AbilitazioniRepository $abilitazioniRepo,
        private ServizioAbilitazioni $abilitazioni,
        private MessaggiRepository $prenotazioni,
        private MessaggiConvenzioneRepository $convenzioni,
        private ServizioUffici $uffici,
        private ServizioAssistenza $assistenza,
        private OperatoriAssistenza $operatoriAssistenza,
    ) {
    }

    /**
     * Chi risponde alle chat di assistenza (ufficio «Assistenza» e amministratori) le vede nella casella.
     *
     * @param array<string, mixed> $utente
     */
    public function puoAssistenza(array $utente): bool
    {
        return $this->operatoriAssistenza->eOperatore($utente);
    }

    /** @param array<string, mixed> $utente riga dell'utente (ruolo_id e ruoli_secondari) */
    public static function eAmministratore(array $utente): bool
    {
        return (int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', array_filter(explode(',', (string) ($utente['ruoli_secondari'] ?? ''))), true);
    }

    /**
     * Chi lavora le convenzioni FSL (modulo FSL, perimetro «fsl_convenzioni» o ufficio delle convenzioni) vede anche le risposte delle scuole.
     *
     * @param array<string, mixed> $utente
     */
    public function puoConvenzioniFsl(array $utente): bool
    {
        $uid = (int) $utente['id'];

        return self::eAmministratore($utente) || $this->abilitazioni->haModulo($uid, 'fsl') || $this->abilitazioni->haAmbito($uid, 'fsl_convenzioni')
            || $this->uffici->utenteMembro($utente, 'convenzioni_fsl');
    }

    /**
     * Filtro sulle attività (AND e.id IN …) con cui l'utente gestisce gli iscritti di un'area: '' se gestisce tutta l'area, null se
     * non li gestisce. Stesse regole del pannello (admin_header.php).
     *
     * @param array<string, mixed> $area riga di pagine_eventi
     */
    public function filtroArea(int $uid, bool $amministratore, array $area): ?string
    {
        if ($amministratore) {
            return '';
        }
        $pid = (int) $area['id'];
        if (in_array($uid, IdsGestori::daCampi($area['gestore_utente_id'] ?? 0, $area['gestori_utenti_ids'] ?? '', $area['permessi_gestori_json'] ?? ''), true)
            || $this->abilitazioni->areaNelModuloUtente($uid, $area)) {
            return '';
        }
        $consentite = [];
        foreach ($this->abilitazioniRepo->gestoriAttivitaAreaConTitolo($pid) as $e) {
            if (in_array($uid, IdsGestori::daCampi(0, $e['gestori_utenti_ids'] ?? '', $e['permessi_gestori_json'] ?? ''), true)) {
                $consentite[] = (int) $e['id'];
            }
        }
        $consentite = array_values(array_unique(array_merge($consentite, $this->abilitazioni->attivitaDaAmbiti($uid, $pid))));
        if (!$consentite && !$this->abilitazioni->haAmbito($uid, 'progetti', $pid) && !$this->abilitazioni->haAmbito($uid, 'eventi', $pid)) {
            return null;
        }

        return $consentite ? ' AND e.id IN (' . implode(',', $consentite) . ') ' : ' AND e.id = -1 ';
    }

    /**
     * Le aree in cui l'utente gestisce gli iscritti, con il filtro sulle attività.
     *
     * @return list<array{area: array<string, mixed>, rbac: string}>
     */
    public function aree(int $uid, bool $amministratore): array
    {
        $out = [];
        foreach ($this->aree->tutte() as $area) {
            $rbac = $this->filtroArea($uid, $amministratore, $area);
            if ($rbac !== null) {
                $out[] = ['area' => $area, 'rbac' => $rbac];
            }
        }

        return $out;
    }

    /**
     * Tutte le conversazioni, prima quelle con messaggi da leggere, poi le più recenti.
     *
     * Con $assistenza si aggiungono le chat di assistenza (tipo «assistenza», modulo «Assistenza»).
     *
     * @return list<array{tipo: string, id: int, titolo: string, rif: string, nome: string, email: string, modulo: string, modulo_nome: string, area: string,
     *     pagina_id: int, ultimo: string, totale: int, da_leggere: int, archiviata: bool}>
     */
    public function conversazioni(int $uid, bool $amministratore, bool $convenzioniFsl, bool $assistenza = false): array
    {
        $out = [];
        if ($assistenza) {
            // Le chat di assistenza: le chiuse stanno tra le archiviate; si aprono nella pagina Assistenza, non in una finestra
            foreach (array_merge($this->assistenza->elenco('tutte', $uid), $this->assistenza->elenco('chiuse', $uid)) as $c) {
                $out[] = [
                    'tipo' => self::ASSISTENZA, 'id' => (int) $c['id'], 'titolo' => CostantiAssistenza::nomeArgomento((string) $c['argomento']), 'rif' => (string) $c['contesto_testo'],
                    'nome' => (string) $c['nome'], 'email' => (string) $c['email'], 'modulo' => 'assistenza', 'modulo_nome' => 'Assistenza', 'area' => '', 'pagina_id' => 0,
                    'ultimo' => (string) $c['ultimo_il'], 'totale' => (int) $c['totale'], 'da_leggere' => (int) $c['da_leggere'], 'archiviata' => $c['stato'] === CostantiAssistenza::STATO_CHIUSA,
                ];
            }
        }
        foreach ($this->aree($uid, $amministratore) as $a) {
            $modulo = Sezioni::moduloDiArea($a['area']);
            foreach ($this->prenotazioni->conversazioni((int) $a['area']['id'], $a['rbac']) as $c) {
                $out[] = [
                    'tipo' => self::PRENOTAZIONE, 'id' => (int) $c['prenotazione_id'], 'titolo' => (string) $c['evento_titolo'], 'rif' => (string) $c['codice_prenotazione'],
                    'nome' => trim($c['nome'] . ' ' . $c['cognome']), 'email' => (string) $c['email'], 'modulo' => $modulo, 'modulo_nome' => self::nomeModulo($modulo),
                    'area' => (string) ($a['area']['titolo'] ?? ''), 'pagina_id' => (int) $a['area']['id'], 'ultimo' => (string) $c['ultimo_messaggio_data'],
                    'totale' => (int) $c['totale_messaggi'], 'da_leggere' => (int) $c['messaggi_da_leggere'], 'archiviata' => (bool) $c['archiviata'],
                ];
            }
        }
        if ($convenzioniFsl) {
            foreach ($this->convenzioni->conversazioni() as $c) {
                $dati = json_decode((string) ($c['dati_json'] ?? ''), true) ?: [];
                $out[] = [
                    'tipo' => self::CONVENZIONE, 'id' => (int) $c['compilazione_id'], 'titolo' => 'Convenzione con la scuola', 'rif' => (string) ($c['scuola_codice'] ?? ''),
                    'nome' => (string) ($dati['scuola']['denominazione'] ?? '') ?: 'Scuola', 'email' => (string) ($c['email'] ?? ''), 'modulo' => 'fsl', 'modulo_nome' => self::nomeModulo('fsl'),
                    'area' => '', 'pagina_id' => 0, 'ultimo' => (string) $c['ultimo'], 'totale' => (int) $c['totale'], 'da_leggere' => (int) $c['da_leggere'], 'archiviata' => (bool) $c['archiviata'],
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$b['da_leggere'] > 0, $b['ultimo']] <=> [$a['da_leggere'] > 0, $a['ultimo']]);

        return $out;
    }

    /** Conversazioni con almeno un messaggio da leggere (numero del pulsante «Messaggi»). */
    public function nonLetti(int $uid, bool $amministratore, bool $convenzioniFsl, bool $assistenza = false): int
    {
        $n = 0;
        foreach ($this->aree($uid, $amministratore) as $a) {
            $n += $this->aree->messaggiNonLetti((int) $a['area']['id'], $a['rbac']);
        }

        return $n + ($convenzioniFsl ? $this->convenzioni->conversazioniNonLette() : 0) + ($assistenza ? $this->assistenza->daLeggere() : 0);
    }

    private static function nomeModulo(string $modulo): string
    {
        return defined('MODULI_PORTALE') ? (string) (\MODULI_PORTALE[$modulo]['nome'] ?? $modulo) : $modulo;
    }
}
