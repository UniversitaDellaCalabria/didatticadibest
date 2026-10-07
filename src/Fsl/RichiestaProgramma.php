<?php

declare(strict_types=1);

namespace App\Fsl;

/** Conferma del programma FSL dal modulo di riepilogo (programma_fsl.php): chi prenota, scuola, convenzione e dati letti dalla pagina. */
final readonly class RichiestaProgramma
{
    /**
     * @param list<string> $ruoliSecondari ruoli secondari dell'utente connesso
     * @param array<string, mixed> $post campi del modulo ($_POST)
     * @param array<string, mixed>|null $fileLogo logo della scuola ($_FILES['logo'])
     * @param string $baseLink indirizzo della cartella del portale usato nei link delle email
     * @param bool $logoObbligatorio il logo della scuola serve per l'Allegato A: senza non si prenota (la pagina lo chiede sempre)
     */
    public function __construct(
        public string $nome,
        public string $cognome,
        public string $email,
        public ?int $utenteId,
        public int $ruoloUtente,
        public array $ruoliSecondari,
        public array $post,
        public ?array $fileLogo,
        public string $ip,
        public string $baseLink,
        public bool $logoObbligatorio = false,
    ) {
    }

    public function connesso(): bool
    {
        return $this->utenteId !== null;
    }
}
