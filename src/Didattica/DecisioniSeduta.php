<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\ServizioCorsi;
use App\Anagrafi\Testi;
use App\Core\Database;

/**
 * Le decisioni del consiglio sulle pratiche portate in seduta: esito e delibera, convalide degli esami (insegnamento del Dipartimento
 * dall'anagrafe, totale o parziale, CFU riconosciuti e da integrare) o insegnamenti in piano / fuori piano; e gli effetti a seduta conclusa.
 */
final class DecisioniSeduta
{
    public function __construct(
        private Database $db,
        private ServizioCorsi $corsi,
        private ServizioPratiche $pratiche,
        private EstrattoVerbale $estratto
    ) {
    }

    /**
     * Righe delle decisioni dal POST (array paralleli d_<campo>[]); l'insegnamento convalidato si riconosce nell'anagrafe
     * del Dipartimento (id e CFU); CFU riconosciuti e da integrare si completano se mancano.
     *
     * @param array<string, mixed> $post
     * @return array{tipo: string, righe: list<array<string, mixed>>}
     */
    public function leggiDalPost(string $tipo, array $post): array
    {
        $num = fn ($v) => ($v = str_replace(',', '.', trim((string) $v))) !== '' && is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.') : '';
        $anag = [];
        foreach ($this->corsi->insegnamentiDipartimentoScelta() as $i) {
            $anag[mb_strtolower(Testi::etichettaInsegnamentoScelta($i))] = $i;
        }
        $righe = [];
        foreach ((array) ($post['d_richiesto'] ?? []) as $k => $ric) {
            $ric = mb_substr(trim((string) $ric), 0, 300);
            $r = ['richiesto' => $ric, 'cfu' => $num($post['d_cfu'][$k] ?? ''), 'voto' => mb_substr(trim((string) ($post['d_voto'][$k] ?? '')), 0, 20), 'ssd' => mb_substr(trim((string) ($post['d_ssd'][$k] ?? '')), 0, 20), 'data' => mb_substr(trim((string) ($post['d_data'][$k] ?? '')), 0, 20)];
            if ($tipo === 'piano') {
                if ($ric === '') {
                    continue;
                }
                $r['esito'] = isset(Costanti::ESITI_PIANO[$post['d_esito'][$k] ?? '']) ? $post['d_esito'][$k] : 'in_piano';
            } else {
                $ins = mb_substr(trim((string) ($post['d_ins'][$k] ?? '')), 0, 300);
                if ($ric === '' && $ins === '') {
                    continue;
                }
                $a = $anag[mb_strtolower($ins)] ?? null;
                $r += ['ins' => $a ? $a['nome'] . ' – ' . $a['corso'] : $ins, 'ins_id' => $a ? $a['id'] : 0, 'ins_cfu' => $a && $a['cfu'] !== null ? $num($a['cfu']) : $num($post['d_ins_cfu'][$k] ?? ''),
                       'esito' => isset(Costanti::ESITI_CONVALIDA[$post['d_esito'][$k] ?? '']) ? $post['d_esito'][$k] : 'totale',
                       'cfu_ric' => $num($post['d_cfu_ric'][$k] ?? ''), 'cfu_int' => $num($post['d_cfu_int'][$k] ?? '')];
                // Richiesta dello studente: inserire l'insegnamento convalidato nel piano come a scelta (ed eliminarne uno)
                if (!empty($post['d_piano'][$k])) {
                    $r += ['piano' => 1, 'elimina' => mb_substr(trim((string) ($post['d_elimina'][$k] ?? '')), 0, 250)];
                }
                if ($r['esito'] === 'totale') {
                    if ($r['cfu_ric'] === '') {
                        $r['cfu_ric'] = $r['ins_cfu'] !== '' ? $r['ins_cfu'] : $r['cfu'];
                    }
                    $r['cfu_int'] = $r['cfu_int'] === '' ? '0' : $r['cfu_int'];
                } elseif ($r['esito'] === 'parziale') {
                    if ($r['cfu_ric'] === '') {
                        $r['cfu_ric'] = $r['cfu'];
                    }
                    if ($r['cfu_int'] === '' && $r['ins_cfu'] !== '' && $r['cfu_ric'] !== '') {
                        $r['cfu_int'] = $num(max(0, (float) $r['ins_cfu'] - (float) $r['cfu_ric']));
                    }
                } else {
                    $r['cfu_ric'] = '0';
                    $r['cfu_int'] = '';
                }
            }
            $righe[] = $r;
            if (count($righe) >= 80) {
                break;
            }
        }

        return ['tipo' => $tipo, 'righe' => $righe];
    }

    /**
     * Esito, decisioni e delibera di una pratica in seduta (delibera null: resta quella che c'è).
     *
     * @param array<string, mixed>|null $decisioni
     */
    public function salva(int $id, string $esito, ?array $decisioni, ?string $delibera): bool
    {
        $esito = isset(Costanti::ESITI_SEDUTA[$esito]) ? $esito : '';
        $json = $decisioni && !empty($decisioni['righe']) ? json_encode(['tipo' => $decisioni['tipo'], 'righe' => $decisioni['righe']], JSON_UNESCAPED_UNICODE) : null;
        if ($delibera === null) {
            return $this->db->esegui('UPDATE pratiche SET esito_seduta = ?, decisioni_json = ?, aggiornata_il = NOW() WHERE id = ?', [$esito, $json, $id]) >= 0;
        }

        return $this->db->esegui('UPDATE pratiche SET esito_seduta = ?, decisioni_json = ?, delibera = ?, aggiornata_il = NOW() WHERE id = ?', [$esito, $json, mb_substr(trim($delibera), 0, 5000), $id]) >= 0;
    }

    /**
     * A seduta conclusa: approvate → accolte, respinte → respinte (lo studente riceve l'email), rinviate → tolte dalla seduta
     * (restano aperte per la prossima). Ritorna [accolte, respinte, rinviate].
     *
     * @param array<string, mixed> $s
     * @return array{0: int, 1: int, 2: int}
     */
    public function applicaEsiti(array $s, int $uid, string $autoreNome = ''): array
    {
        $n = [0, 0, 0];
        $quando = ($s['data'] ? ' del ' . date('d/m/Y', strtotime($s['data'])) : '');
        foreach ($this->db->righe("SELECT id, stato, esito_seduta FROM pratiche WHERE seduta_id = ? AND esito_seduta <> ''", [(int) $s['id']]) as $x) {
            $id = (int) $x['id'];
            // L'estratto del verbale (delibera e quadro delle decisioni) va nella pratica prima dell'email dell'esito
            $estratto = fn () => $this->estratto->allega($s, $id, $uid, $autoreNome) ? " Nella pratica trovi l'estratto del verbale in PDF." : '';
            if (in_array($x['esito_seduta'], ['approvata', 'approvata_mod'], true) && !in_array($x['stato'], ['accolta', 'chiusa'], true)) {
                $this->pratiche->cambiaStato($id, 'accolta', 'Approvata dal Consiglio nella seduta' . $quando . '.' . $estratto(), $uid, $autoreNome);
                $n[0]++;
            } elseif ($x['esito_seduta'] === 'respinta' && $x['stato'] !== 'respinta') {
                $this->pratiche->cambiaStato($id, 'respinta', 'Non approvata dal Consiglio nella seduta' . $quando . '.' . $estratto(), $uid, $autoreNome);
                $n[1]++;
            } elseif ($x['esito_seduta'] === 'rinviata') {
                $this->db->esegui("UPDATE pratiche SET seduta_id = NULL, esito_seduta = '' WHERE id = ?", [$id]);
                $this->pratiche->evento($id, 'messaggio', 'ufficio', $uid, null, 'Rinviata dal Consiglio nella seduta' . $quando . ': sarà esaminata nella prossima seduta.', null, null, false, $autoreNome);
                $n[2]++;
            }
        }

        return $n;
    }
}
