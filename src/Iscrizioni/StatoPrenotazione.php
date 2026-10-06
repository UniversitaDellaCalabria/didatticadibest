<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Stati di una prenotazione (colonna prenotazioni.stato; NULL = vecchie righe, da considerare confermate). */
enum StatoPrenotazione: string
{
    case Confermata = 'confermata';
    case InAttesa = 'in_attesa';
    case DaApprovare = 'da_approvare';
    case RichiestaConferma = 'richiesta_conferma';
    case Annullata = 'annullata';
    case Rifiutata = 'rifiutata';
    case Scaduta = 'scaduta';

    /** Stato di una riga del DB; NULL (righe vecchie) vale confermata; null se il valore non è uno stato noto. */
    public static function daDb(?string $valore): ?self
    {
        return self::tryFrom($valore ?? self::Confermata->value);
    }

    /** @return list<string> stati che tengono un posto del turno (anche «da approvare», che lo libera se rifiutata) */
    public static function chePrendonoPosto(): array
    {
        return [self::Confermata->value, self::RichiestaConferma->value, self::DaApprovare->value];
    }

    /** @return list<string> stati ancora in corso per chi ha prenotato (non chiusi) */
    public static function inCorso(): array
    {
        return [self::Confermata->value, self::RichiestaConferma->value, self::DaApprovare->value, self::InAttesa->value];
    }

    /** @return list<string> stati chiusi: la prenotazione non conta più */
    public static function chiusi(): array
    {
        return [self::Annullata->value, self::Rifiutata->value, self::Scaduta->value];
    }

    public function occupaPosto(): bool
    {
        return in_array($this->value, self::chePrendonoPosto(), true);
    }
}
