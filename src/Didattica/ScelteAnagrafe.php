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
