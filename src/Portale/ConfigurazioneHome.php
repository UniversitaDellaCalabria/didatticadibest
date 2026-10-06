<?php

declare(strict_types=1);

namespace App\Portale;

/** Widget della home (normalizzazione del JSON salvato) e cache della configurazione del portale. */
interface ConfigurazioneHome
{
    /**
     * Widget normalizzati (valori non validi → predefiniti) dal JSON di configurazione_portale.widgets_home.
     *
     * @return array<string, mixed>
     */
    public function widgets(?string $json): array;

    /** Da chiamare dopo ogni modifica di configurazione_portale. */
    public function invalidaCache(): void;
}
