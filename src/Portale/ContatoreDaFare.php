<?php

declare(strict_types=1);

namespace App\Portale;

use App\Auth\Sessione;
use App\Auth\UtenteRepository;

/**
 * Il numero di «Da fare per te» mostrato nella barra di ogni pagina. Il calcolo interroga molti moduli: si fa una volta ogni pochi minuti per sessione
 * (e per persona: con «Entra come» la sessione cambia utente) e si azzera appena una persona chiude un compito.
 */
final class ContatoreDaFare
{
    /** Secondi per cui il numero in sessione resta valido. */
    public const DURATA = 300;

    private const CHIAVE = 'da_fare_contatore';

    public function __construct(private Sessione $sessione, private UtenteRepository $utenti, private DaFarePersona $daFare)
    {
    }

    public function numero(int $utenteId, int $ora): int
    {
        if ($utenteId <= 0) {
            return 0;
        }
        $c = $this->sessione->leggi(self::CHIAVE);
        if (is_array($c) && ($c['utente'] ?? 0) === $utenteId && $ora - (int) ($c['ora'] ?? 0) < self::DURATA && $ora >= (int) ($c['ora'] ?? 0)) {
            return (int) $c['n'];
        }
        try {
            $n = DaFarePersona::totale($this->daFare->gruppi($this->utenti->perId($utenteId)));
        } catch (\Throwable $e) {
            error_log('Numero «Da fare»: ' . $e->getMessage());
            $n = 0;
        }
        $this->sessione->scrivi(self::CHIAVE, ['utente' => $utenteId, 'ora' => $ora, 'n' => $n]);

        return $n;
    }

    /** Da chiamare quando una persona chiude un compito: il prossimo numero si ricalcola subito. */
    public function azzera(): void
    {
        $this->sessione->togli(self::CHIAVE);
    }
}
