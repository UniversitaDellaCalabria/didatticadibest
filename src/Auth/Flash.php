<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Vista\FlashVista;

/** Messaggi "flash" mostrati una volta sola dopo un rimando (spostati da flash_set/flash_get/flash_html di inc/base.php). */
final class Flash
{
    private const CHIAVE = '_flash';

    public function __construct(private Sessione $sessione)
    {
    }

    public function imposta(mixed $messaggio, mixed $tipo = 'success'): void
    {
        $this->sessione->scrivi(self::CHIAVE, ['msg' => $messaggio, 'type' => $tipo]);
    }

    /** Il messaggio in attesa (tolto dalla sessione) o null. */
    public function prendi(): mixed
    {
        $f = $this->sessione->leggi(self::CHIAVE);
        if ($f !== null) {
            $this->sessione->togli(self::CHIAVE);

            return $f;
        }

        return null;
    }

    /** Avviso Bootstrap del messaggio in attesa ('' se non ce n'è). */
    public function html(): string
    {
        return FlashVista::html($this->prendi());
    }
}
