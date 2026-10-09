<?php

declare(strict_types=1);

namespace App\Fsl;

/** Il logo della scuola per i documenti: il PDF accetta solo JPEG, un PNG si converte su fondo bianco con GD. */
final class LogoScuola
{
    /**
     * Il logo come JPEG, in un file temporaneo che viene tolto alla fine della richiesta se è stato convertito. Null senza logo o se non si legge.
     */
    public function jpeg(?string $logo): ?string
    {
        if (!$logo || !is_file($logo) || !($dim = @getimagesize($logo))) {
            return null;
        }
        if ($dim[2] === IMAGETYPE_JPEG) {
            return $logo;
        }
        if ($dim[2] !== IMAGETYPE_PNG || !function_exists('imagecreatefrompng')) {
            return null;
        }
        $png = @imagecreatefrompng($logo);
        if (!$png) {
            return null;
        }
        $w = imagesx($png);
        $h = imagesy($png);
        $tela = imagecreatetruecolor($w, $h);
        imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));
        imagecopy($tela, $png, 0, 0, 0, 0, $w, $h);
        $tmp = tempnam(sys_get_temp_dir(), 'logo') . '.jpg';
        $ok = imagejpeg($tela, $tmp, 90);
        register_shutdown_function(static function () use ($tmp): void {
            @unlink($tmp);
        });

        return $ok ? $tmp : null;
    }
}
