<?php

declare(strict_types=1);

namespace App\Auth\Vista;

use App\Sistema\Html;

/** Avviso Bootstrap dei messaggi flash (stesso HTML della vecchia flash_html()). */
final class FlashVista
{
    public static function html(mixed $f): string
    {
        if (!$f) {
            return '';
        }
        $type = $f['type'];
        if ($type === 'danger') {
            $cls = 'danger';
            $icon = 'fa-times-circle';
        } elseif ($type === 'warning') {
            $cls = 'warning';
            $icon = 'fa-exclamation-triangle';
        } elseif ($type === 'info') {
            $cls = 'info';
            $icon = 'fa-info-circle';
        } else {
            $cls = 'success';
            $icon = 'fa-check-circle';
        }

        return '<div class="alert alert-' . $cls . ' fw-bold text-center border-' . $cls
             . ' shadow-sm alert-dismissible fade show" role="alert">'
             . '<i class="fa ' . $icon . ' me-1"></i> '
             . Html::h($f['msg'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}
