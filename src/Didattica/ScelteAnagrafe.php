<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\ServizioCorsi;
use App\Anagrafi\Testi;
use App\Core\Database;
use App\Eventi\Righe;

/** Valori proposti dai campi guidati dalle anagrafi: corsi di studio (per tipo), insegnamenti e docenti. Letti una volta sola per richiesta. */
final class ScelteAnagrafe
{
    /** @var array<string, array<int|string, mixed>> */
    private array $cache = [];

    public function __construct(private Database $db, private ServizioCorsi $corsi)
    {
    }

    /**
     * Il docente titolare di ogni insegnamento proposto (stessa scritta della lista «insegnamento»): «Cognome Nome» come nell'anagrafe del personale,
     * riconosciuto dal nome senza badare all'ordine; vuoto se non si trova con sicurezza.
     *
     * @return array<string, string>
     */
    public function docentiPerInsegnamento(): array
    {
        if (isset($this->cache['_docenti_ins'])) {
            return $this->cache['_docenti_ins'];
        }
        $chiave = static function (string $s): string {
            $t = array_filter(explode(' ', \App\Tutorato\AbbinamentoInsegnamenti::norma($s)));
            sort($t);

            return implode(' ', $t);
        };
        $mappa = [];
        foreach ($this->per('docente') as $d) {
            $k = $chiave((string) $d);
            $mappa[$k] = isset($mappa[$k]) ? '' : (string) $d; // omonimi: nessuna proposta
        }
        $out = [];
        foreach ($this->corsi->insegnamentiPerCorso() as $corso => $ins) {
            foreach ($ins as $i) {
                $nome = $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : '') . ' – ' . $corso;
                foreach (preg_split('/[;\/]/', (string) ($i['docente'] ?? '')) ?: [] as $parte) {
                    if (trim($parte) !== '' && !empty($mappa[$chiave($parte)])) {
                        $out[$nome] = $mappa[$chiave($parte)];
                        break;
                    }
                }
            }
        }

        return $this->cache['_docenti_ins'] = $out;
    }

    /** @return array<int|string, mixed> */
    public function per(string $tipo): array
    {
        if (isset($this->cache[$tipo])) {
            return $this->cache[$tipo];
        }
        $out = [];
        if ($tipo === 'corso_studio') {
            foreach ($this->corsi->visibili() as $gruppo => $corsi) {
                foreach ($corsi as $c) {
                    $out[$gruppo][] = Testi::nomeSchedaCorso($c);
                }
            }
        } elseif ($tipo === 'insegnamento') {
            foreach ($this->corsi->insegnamentiPerCorso() as $corso => $ins) {
                foreach ($ins as $i) {
                    $out[] = $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : '') . ' – ' . $corso;
                }
            }
            $out = array_values(array_unique($out));
        } elseif ($tipo === 'docente') {
            foreach (Righe::testo($this->db->righe('SELECT cognome, nome FROM personale_ateneo WHERE docente = 1 AND attivo = 1 ORDER BY cognome, nome')) as $x) {
                $out[] = trim($x['cognome'] . ' ' . $x['nome']);
            }
            $out = array_values(array_unique($out));
        }

        return $this->cache[$tipo] = $out;
    }
}
