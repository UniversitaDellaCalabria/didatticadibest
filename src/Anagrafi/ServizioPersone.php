<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Auth\Abilitazioni\IdsGestori;
use App\Core\Sito;

/**
 * Anagrafe del personale di Ateneo: persona per id, ricerca, scheda completa dal portale (foto, ORCID, ricevimento…) con le
 * modifiche fatte dalla persona dall'Area personale, e avvisi su gestori e referenti usciti dall'Ateneo.
 * Spostato da inc/anagrafi.php, dove restano le facciate. Il risultato di persona() resta in memoria per la richiesta, come prima.
 */
final class ServizioPersone
{
    /** @var array<string, array<string, mixed>|null> */
    private array $cache = [];

    public function __construct(
        private PersonaRepository $persone,
        private CollegamentiRepository $collegamenti,
        private ClientApiAteneo $api,
        private Sito $sito,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function persona(?string $id): ?array
    {
        $id = trim((string) $id);
        if ($id === '' || !preg_match('/^[A-Za-z0-9._\'-]{2,80}$/', $id)) {
            return null;
        }
        if (!array_key_exists($id, $this->cache)) {
            $this->cache[$id] = $this->persone->perId($id);
        }

        return $this->cache[$id];
    }

    /**
     * Ricerca per parole (cognome, nome, email, settore) con filtri facoltativi. Prima chi è in servizio.
     *
     * @return list<array<string, mixed>>
     */
    public function cerca(string $q, string $gruppo = '', string $ruolo = '', string $struttura = '', int $limite = 20): array
    {
        $parole = array_slice(array_filter(explode(' ', trim((string) preg_replace('/\s+/u', ' ', $q))), static fn (string $x): bool => mb_strlen($x) >= 2), 0, 5);
        $out = [];
        foreach ($this->persone->cerca($parole, isset(Anagrafe::GRUPPI_PERSONALE[$gruppo]) ? $gruppo : '', $ruolo, $struttura, $limite) as $p) {
            $det = json_decode((string) ($p['dettaglio_json'] ?? ''), true) ?: [];
            $telMod = trim((string) ($this->modifiche((string) $p['id'])['telefono'] ?? ''));
            $out[] = ['id' => $p['id'], 'nome' => Testi::nomePersona($p), 'cognome' => $p['cognome'], 'email' => $p['email'], 'telefono' => $telMod !== '' ? $telMod : $p['telefono'],
                'ruolo' => $p['ruolo'], 'struttura' => $p['struttura'], 'ssd' => $p['ssd'], 'gruppo' => Anagrafe::GRUPPI_PERSONALE[$p['gruppo']] ?? '',
                'attivo' => (int) $p['attivo'], 'link' => Testi::urlPortalePersona($p), 'foto' => $det['foto'] ?? ''];
        }

        return $out;
    }

    /** @return array<string, mixed> campi della scheda modificati dalla persona ([] se non ne ha) */
    public function modifiche(?string $id): array
    {
        $id = trim((string) $id);

        return $id === '' ? [] : ($this->persone->modifiche($id) ?: []);
    }

    /**
     * Scheda completa (foto, ORCID, ricevimento, curriculum…) dal portale, conservata 7 giorni.
     * La foto viene copiata in uploads/personale/ così le pagine non si collegano a server esterni.
     * $aggiorna = false: usa solo quello che c'è (nessuna chiamata di rete).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    public function dettaglio(array $p, bool $aggiorna = true): array
    {
        $det = json_decode((string) ($p['dettaglio_json'] ?? ''), true) ?: [];
        $fresco = !empty($p['dettaglio_il']) && strtotime((string) $p['dettaglio_il']) > time() - 7 * 86400;
        if (!$aggiorna || $fresco) {
            return $det;
        }
        $j = $this->api->get((!empty($p['docente']) ? 'teachers/' : 'addressbook/') . rawurlencode((string) $p['id']) . '/', [], 10);
        $r = $j['results'] ?? null;
        if (!is_array($r)) {
            return $det; // API non raggiungibili: resta la scheda precedente
        }
        $puliscoUrl = static function (mixed $u): string {
            $u = trim((string) $u);
            if (str_starts_with($u, '//')) {
                $u = 'https:' . $u;
            }

            return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $u) ? $u : '';
        };
        $nuovo = [
            'orcid' => preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', (string) ($r['ORCID'] ?? '')) ? $r['ORCID'] : '',
            'bio' => mb_substr(Testi::testoDaHtmlApi($r['ShortBio'] ?? ''), 0, 3000),
            'cv_breve' => mb_substr(Testi::testoDaHtmlApi($r['TeacherCVShort'] ?? ''), 0, 1500),
            'ricevimento' => mb_substr(Testi::testoDaHtmlApi($r['ReceptionHours'] ?? ''), 0, 1000),
            'cv_ita' => $puliscoUrl($r['CVPathIta'] ?? ''),
            'cv_en' => $puliscoUrl($r['CVPathEn'] ?? ''),
            'siti' => array_values(array_filter(array_map($puliscoUrl, (array) ($r['TeacherWebSite'] ?? $r['WebSite'] ?? [])))),
            'ufficio' => implode(', ', (array) ($r['TeacherOfficeReference'] ?? $r['OfficeReference'] ?? [])),
            'telefoni' => array_values(array_slice((array) ($r['TeacherTelOffice'] ?? $r['TelOffice'] ?? []), 0, 3)),
            'foto' => $det['foto'] ?? '',
        ];
        // Foto: copia locale (solo immagini, max 2 MB), rinnovata con la scheda
        $urlFoto = $puliscoUrl($r['PhotoPath'] ?? '');
        if ($urlFoto !== '' && preg_match('#^https://storage\.portale\.unical\.it/#i', $urlFoto)) {
            $dati = $this->api->scarica($urlFoto, 10);
            $info = ($dati !== null && strlen($dati) < 2 * 1024 * 1024) ? @getimagesizefromstring($dati) : false;
            $est = $info ? (['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime']] ?? '') : '';
            if ($est !== '') {
                $dir = $this->sito->radice() . '/uploads/personale';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $file = preg_replace('/[^a-z0-9._-]/', '_', strtolower((string) $p['id'])) . '.' . $est;
                if (@file_put_contents("$dir/$file", $dati) !== false) {
                    $nuovo['foto'] = "uploads/personale/$file";
                }
            }
        } elseif ($urlFoto === '') {
            $nuovo['foto'] = '';
        }
        $this->persone->salvaDettaglio((string) $p['id'], (string) json_encode($nuovo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $nuovo;
    }

    /**
     * Scheda da mostrare: dati del portale di Ateneo con sopra le modifiche della persona (campo vuoto = dato del portale).
     * Ritorna ['valori' => […], 'portale' => […], 'modificati' => [campi]] con i campi di Anagrafe::CAMPI_SCHEDA_PERSONA.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed>|null $det
     * @return array{valori: array<string, string>, portale: array<string, string>, modificati: list<string>}
     */
    public function scheda(array $p, ?array $det = null): array
    {
        $det ??= $this->dettaglio($p, false);
        $portale = [
            'telefono' => !empty($det['telefoni']) ? implode(', ', $det['telefoni']) : (string) ($p['telefono'] ?? ''),
            'ufficio' => ($det['ufficio'] ?? '') !== '' ? $det['ufficio'] : (string) ($p['ufficio'] ?? ''),
            'ricevimento' => (string) ($det['ricevimento'] ?? ''),
            'bio' => ($det['bio'] ?? '') !== '' ? $det['bio'] : (string) ($det['cv_breve'] ?? ''),
            'sito' => (string) ($det['siti'][0] ?? ''),
        ];
        $mod = $this->modifiche($p['id'] ?? '');
        $valori = [];
        $modificati = [];
        foreach ($portale as $k => $v) {
            $m = trim((string) ($mod[$k] ?? ''));
            if ($m !== '') {
                $valori[$k] = $m;
                $modificati[] = $k;
            } else {
                $valori[$k] = $v;
            }
        }

        return ['valori' => $valori, 'portale' => $portale, 'modificati' => $modificati];
    }

    /**
     * Salva i campi modificati dalla persona (testi ripuliti, sito solo https). Ritorna null o il messaggio d'errore.
     *
     * @param array<string, mixed> $post
     */
    public function salvaModifiche(string $id, array $post): ?string
    {
        $lim = ['telefono' => 60, 'ufficio' => 255, 'ricevimento' => 1000, 'bio' => 3000, 'sito' => 255];
        $v = [];
        foreach ($lim as $k => $max) {
            $v[$k] = mb_substr(trim(strip_tags(str_replace("\r", '', (string) ($post[$k] ?? '')))), 0, $max);
        }
        if ($v['telefono'] !== '' && !preg_match('/^[0-9+().\/ ,-]{4,60}$/', $v['telefono'])) {
            return 'Il telefono può contenere solo numeri, spazi, + - / ( ) e virgole.';
        }
        if ($v['sito'] !== '' && !preg_match('#^https://#i', $v['sito'])) {
            $v['sito'] = 'https://' . preg_replace('#^https?://#i', '', $v['sito']);
        }
        if ($v['sito'] !== '' && !filter_var($v['sito'], FILTER_VALIDATE_URL)) {
            return 'Il sito web non è un indirizzo valido.';
        }

        return $this->persone->salvaModifiche($id, $v) ? null : 'Salvataggio non riuscito.';
    }

    /**
     * Gestori e referenti che non sono più nell'anagrafe di Ateneo (cessati o trasferiti): per la dashboard.
     *
     * @return array{gestori: list<array<string, mixed>>, referenti: list<array<string, mixed>>}
     */
    public function avvisi(): array
    {
        $out = ['gestori' => [], 'referenti' => []];
        if ($this->persone->conta() === 0) {
            return $out; // anagrafe mai sincronizzata
        }
        $usciti = $this->persone->usciti();
        // Gestori: utenti abilitati su un'area o un evento collegati a una persona uscita
        $idsG = [];
        foreach ($this->collegamenti->gestoriAree() as $x) {
            foreach (IdsGestori::daCampi($x['gestore_utente_id'], $x['gestori_utenti_ids'], $x['permessi_gestori_json']) as $i) {
                $idsG[$i] = true;
            }
        }
        foreach ($this->collegamenti->gestoriEventi() as $x) {
            foreach (IdsGestori::daCampi(0, $x['gestori_utenti_ids'], $x['permessi_gestori_json']) as $i) {
                $idsG[$i] = true;
            }
        }
        foreach ($this->collegamenti->utentiAbilitati() as $i) {
            $idsG[$i] = true;
        }
        if ($idsG && $usciti) {
            foreach ($this->collegamenti->utentiConPersona(array_keys($idsG)) as $u) {
                if (isset($usciti[(string) $u['persona_id']])) {
                    $out['gestori'][] = $u + ['uscita_il' => $usciti[(string) $u['persona_id']]['uscita_il']];
                }
            }
        }
        // Referenti di eventi e progetti non archiviati scelti dall'anagrafe
        foreach ($this->collegamenti->referentiInCorso() as $x) {
            foreach (json_decode((string) $x['referenti_json'], true) ?: [] as $rf) {
                $pid = (string) ($rf['persona_id'] ?? '');
                if ($pid === '') {
                    continue;
                }
                if (isset($usciti[$pid]) || !$this->persona($pid)) {
                    $out['referenti'][] = ['nome' => $rf['nome'] ?? $pid, 'evento_id' => (int) $x['id'], 'titolo' => $x['titolo'], 'tipo' => $x['tipo'], 'pagina_id' => (int) $x['pagina_id']];
                }
            }
        }

        return $out;
    }
}
