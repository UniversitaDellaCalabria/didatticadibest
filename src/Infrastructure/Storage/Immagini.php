<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Alleggerisce le foto caricate (slide della home, copertine): le riduce a una misura adatta allo schermo e le ricomprime.
 * Serve l'estensione GD: senza, non si cambia niente (si salva il file com'è). Mai ingrandire; il file nuovo sostituisce il vecchio solo se è più leggero.
 */
final class Immagini
{
    public const LATO_MAX = 1600;
    public const QUALITA = 82;
    /** Sotto questa dimensione (e misura) un file è già abbastanza leggero e non si tocca */
    public const PESO_OK = 614400;

    public function disponibile(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg');
    }

    /** @return array{0: int, 1: int}|null larghezza e altezza, null se non è un'immagine */
    public function misure(string $percorso): ?array
    {
        $i = is_file($percorso) ? @getimagesize($percorso) : false;

        return $i !== false && $i[0] > 0 && $i[1] > 0 ? [(int) $i[0], (int) $i[1]] : null;
    }

    /**
     * Riduce l'immagine al suo posto (JPEG, PNG, WebP).
     *
     * @return int byte risparmiati (0 = non toccata)
     */
    public function riduci(string $percorso, int $latoMax = self::LATO_MAX, int $qualita = self::QUALITA): int
    {
        $m = $this->misure($percorso);
        if (!$this->disponibile() || $m === null) {
            return 0;
        }
        $prima = (int) filesize($percorso);
        if ($prima <= self::PESO_OK && max($m) <= $latoMax) {
            return 0;
        }
        $tipo = (int) (@getimagesize($percorso)[2] ?? 0);
        $img = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($percorso),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($percorso) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($percorso) : false,
            default => false,
        };
        if ($img === false) {
            return 0;
        }
        if ($tipo === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $img = $this->raddrizza($img, $percorso);
        }
        [$l, $a] = [imagesx($img), imagesy($img)];
        $scala = min(1.0, $latoMax / max($l, $a));
        if ($scala < 1.0) {
            $nuova = imagescale($img, max(1, (int) round($l * $scala)), max(1, (int) round($a * $scala)));
            if ($nuova !== false) {
                $img = $nuova;
            }
        }
        $tmp = $percorso . '.tmp' . bin2hex(random_bytes(3));
        $ok = match ($tipo) {
            IMAGETYPE_PNG => $this->salvaPng($img, $tmp),
            IMAGETYPE_WEBP => function_exists('imagewebp') && @imagewebp($img, $tmp, $qualita),
            default => @imagejpeg($img, $tmp, $qualita),
        };
        imagedestroy($img);
        if (!$ok || !is_file($tmp) || (int) filesize($tmp) >= $prima) {
            @unlink($tmp);

            return 0;
        }
        $dopo = (int) filesize($tmp);

        if (!@rename($tmp, $percorso)) {
            @unlink($tmp);

            return 0;
        }

        return $prima - $dopo;
    }

    /**
     * Riduce tutte le immagini di una cartella.
     *
     * @return array{viste: int, ridotte: int, byte: int}
     */
    public function riduciCartella(string $cartella): array
    {
        $r = ['viste' => 0, 'ridotte' => 0, 'byte' => 0];
        foreach (glob(rtrim($cartella, '/') . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
            $r['viste']++;
            $b = $this->riduci($f);
            if ($b > 0) {
                $r['ridotte']++;
                $r['byte'] += $b;
            }
        }

        return $r;
    }

    private function salvaPng(\GdImage $img, string $dove): bool
    {
        imagesavealpha($img, true);

        return @imagepng($img, $dove, 9);
    }

    private function raddrizza(\GdImage $img, string $percorso): \GdImage
    {
        $o = (int) (@exif_read_data($percorso)['Orientation'] ?? 1);
        $gradi = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($gradi !== 0) {
            $r = imagerotate($img, $gradi, 0);
            if ($r !== false) {
                return $r;
            }
        }

        return $img;
    }
}
