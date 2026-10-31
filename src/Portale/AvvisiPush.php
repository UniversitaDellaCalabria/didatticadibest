<?php

declare(strict_types=1);

namespace App\Portale;

use App\Assistenza\Push\ServizioPush;
use App\Auth\UtenteRepository;
use App\Core\Orologio;
use App\Core\Sito;
use App\Sistema\FileEnv;

/**
 * Avvisi sul dispositivo (notifiche push) per i compiti urgenti di «Da fare per te»: solo alle persone che li hanno attivati dal profilo, solo se hanno
 * qualcosa che scade entro DaFarePersona::GIORNI_SCADENZA_VICINA giorni (o già scaduto), al massimo una volta al giorno. Il testo dice quante cose sono, non quali.
 * AVVISI_PUSH=0 nel .env li spegne.
 */
final class AvvisiPush
{
    public function __construct(private DaFarePersona $daFare, private ServizioPush $push, private UtenteRepository $utenti, private Orologio $orologio, private Sito $sito, private FileEnv $env)
    {
    }

    /** Si manda di giorno (dalle 8 alle 19), dal lunedì al venerdì. */
    public function ora(\DateTimeInterface $adesso): bool
    {
        $h = (int) $adesso->format('G');

        return (int) $adesso->format('N') <= 5 && $h >= 8 && $h < 20;
    }

    /** Quante cose urgenti ci sono nei gruppi (con una scadenza vicina o passata). @param list<array<string, mixed>> $gruppi */
    public static function urgenti(array $gruppi, string $oggi): int
    {
        $n = 0;
        foreach ($gruppi as $g) {
            foreach ($g['righe'] as $r) {
                $s = DaFarePersona::scadenzaDi($r, $oggi);
                $n += $s !== null && $s['urgente'] ? 1 : 0;
            }
        }

        return $n;
    }

    public static function testo(int $n): string
    {
        return $n === 1 ? 'Hai una cosa in scadenza' : "Hai $n cose in scadenza";
    }

    /** @return array{inviati: int, saltati: int} */
    public function invia(): array
    {
        $adesso = $this->orologio->adesso();
        $oggi = $adesso->format('Y-m-d');
        $inviati = $saltati = 0;
        foreach ($this->push->personeIscritte() as $utenteId => $ultimo) {
            if ($ultimo !== '' && substr($ultimo, 0, 10) === $oggi) {
                $saltati++;
                continue;
            }
            $utente = $this->utenti->perId($utenteId);
            $n = $utente ? self::urgenti($this->daFare->gruppi($utente), $oggi) : 0;
            if ($n === 0) {
                $saltati++;
                continue;
            }
            $inviati += $this->push->avvisaPersona($utenteId, self::testo($n), 'Apri «Da fare per te» per vedere cosa e entro quando.', $this->sito->urlBase() . '/area_personale.php') > 0 ? 1 : 0;
        }

        return ['inviati' => $inviati, 'saltati' => $saltati];
    }

    public function cron(): string
    {
        if (!$this->push->attivo() || !$this->ora($this->orologio->adesso()) || trim((string) ($this->env->valore('AVVISI_PUSH') ?? '1')) === '0') {
            return '';
        }
        ['inviati' => $inviati] = $this->invia();

        return $inviati ? "- Avvisi sul dispositivo per i compiti in scadenza: $inviati persone.\n" : '';
    }
}
