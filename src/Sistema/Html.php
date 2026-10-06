<?php

declare(strict_types=1);

namespace App\Sistema;

/** Escaping dell'output HTML (la vecchia h() di inc/base.php, che resta come facciata). */
final class Html
{
    /** Testo sicuro dentro HTML e attributi; null diventa stringa vuota. */
    public static function h(mixed $s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
