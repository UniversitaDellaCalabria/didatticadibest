<?php

declare(strict_types=1);

namespace App\Iscrizioni;

/** Dati di una prenotazione inviata dal modulo pubblico di un'area, già letti dalla pagina (il servizio non tocca $_POST né $_SESSION). */
final readonly class RichiestaPrenotazione
{
    /**
     * @param array<string, mixed> $pagina riga di pagine_eventi dell'area
     * @param string $slug nome della pagina dell'area (senza .php)
     * @param array<string, mixed> $post campi inviati dal modulo ($_POST)
     * @param array<string, mixed> $files file allegati ($_FILES)
     * @param list<string> $ruoliSecondari ruoli secondari dell'utente connesso
     * @param string $baseLink indirizzo della cartella del portale usato nei link delle email (protocollo, host e cartella della richiesta)
     */
    public function __construct(
        public array $pagina,
        public string $slug,
        public int $turnoId,
        public string $nome,
        public string $cognome,
        public string $email,
        public string $matricola,
        public int $numPosti,
        public ?int $utenteId,
        public int $ruoloUtente,
        public array $ruoliSecondari,
        public array $post,
        public array $files,
        public string $ip,
        public string $baseLink,
    ) {
    }

    public function paginaId(): int
    {
        return (int) ($this->pagina['id'] ?? 1);
    }

    public function connesso(): bool
    {
        return $this->utenteId !== null;
    }
}
