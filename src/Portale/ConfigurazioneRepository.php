<?php

declare(strict_types=1);

namespace App\Portale;

use App\Core\Database;

/** Impostazioni di testata, colori e testi del portale (tabella configurazione_portale, riga 1). */
final class ConfigurazioneRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null la riga 1 (null se manca o la tabella non esiste) */
    public function riga(): ?array
    {
        return $this->db->riga('SELECT * FROM configurazione_portale WHERE id = 1');
    }

    /** Widget della home e annuncio (colore: solo lettere minuscole, già filtrato dal chiamante). */
    public function salvaHome(string $widgetsJson, string $annuncio, string $annuncioColore): void
    {
        $this->db->esegui('UPDATE configurazione_portale SET widgets_home = ?, annuncio_home = ?, annuncio_colore = ? WHERE id = 1', [$widgetsJson, $annuncio, $annuncioColore]);
    }

    /**
     * Testata, colori del menu e testi del footer. $immagini: colonne dei file caricati o rimossi
     * (logo_path, logo_mobile_path, favicon_path), solo quelle da cambiare.
     *
     * @param array<string, string> $testi colonna => valore (nome_portale, sottotitolo_portale, descrizione_portale, colore_menu_bg, colore_menu_testo, footer_*)
     * @param array<string, string> $immagini
     */
    public function salvaTestata(array $testi, array $immagini): void
    {
        $tutti = $testi + $immagini;
        $this->db->esegui(
            'UPDATE configurazione_portale SET ' . implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($tutti))) . ' WHERE id = 1',
            array_values($tutti)
        );
    }

    // --- Slide della home (slide_home) ---

    /** @return list<array<string, mixed>> */
    public function slide(): array
    {
        return $this->db->righe('SELECT * FROM slide_home ORDER BY ordine ASC, id ASC');
    }

    public function aggiungiSlide(string $immagine, string $titolo, string $sottotitolo, string $link): void
    {
        $max = (int) ($this->db->valore('SELECT MAX(ordine) as m FROM slide_home') ?? 0);
        $this->db->esegui('INSERT INTO slide_home (immagine_path, titolo, sottotitolo, link, ordine) VALUES (?, ?, ?, ?, ?)', [$immagine, $titolo, $sottotitolo, $link, $max + 1]);
    }

    public function immagineSlide(int $id): ?string
    {
        $p = $this->db->valore('SELECT immagine_path FROM slide_home WHERE id = ?', [$id]);

        return $p === null ? null : (string) $p;
    }

    public function esisteSlide(int $id): bool
    {
        return $this->db->riga('SELECT immagine_path FROM slide_home WHERE id = ?', [$id]) !== null;
    }

    public function eliminaSlide(int $id): void
    {
        $this->db->esegui('DELETE FROM slide_home WHERE id = ?', [$id]);
    }

    public function alternaSlide(int $id): void
    {
        $this->db->esegui('UPDATE slide_home SET attiva = 1 - attiva WHERE id = ?', [$id]);
    }

    public function impostaOrdineSlide(int $id, int $ordine): void
    {
        $this->db->esegui('UPDATE slide_home SET ordine = ? WHERE id = ?', [$ordine, $id]);
    }
}
