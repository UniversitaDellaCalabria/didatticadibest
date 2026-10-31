<?php
// inc/ui.php - Pezzi comuni dell'interfaccia (stile in assets/css/portale-ui.css): stati con i colori fissi, stato vuoto, intestazione delle pagine interne
// e interruttore del tema scuro. Caricato da header.php e da admin/admin_header.php.

if (!function_exists('ui_stato')) {
    /** Etichetta di stato nei cinque colori fissi: ok (fatto) · attesa (in attesa) · azione (serve un'azione) · info · neutro (chiuso, annullato). */
    function ui_stato(string $tipo, string $testo, string $icona = ''): string {
        $tipo = in_array($tipo, ['ok', 'attesa', 'azione', 'info', 'neutro'], true) ? $tipo : 'neutro';
        return '<span class="ui-stato ' . $tipo . '">' . ($icona !== '' ? '<i class="fa ' . htmlspecialchars($icona, ENT_QUOTES) . '" aria-hidden="true"></i>' : '')
            . htmlspecialchars($testo, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('ui_vuoto')) {
    /** Stato vuoto: cerchio leggero con l'icona, titolo, testo e un pulsante utile (facoltativo). */
    function ui_vuoto(string $icona, string $titolo, string $testo = '', string $href = '', string $etichetta = '', string $colore = '#64748b'): string {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<div class="ui-vuoto" style="--tc:' . $h($colore) . ';"><div class="ui-vuoto-figura" aria-hidden="true"><i class="fa ' . $h($icona) . '"></i></div>'
            . '<h2>' . $h($titolo) . '</h2>' . ($testo !== '' ? '<p>' . $h($testo) . '</p>' : '')
            . ($href !== '' && $etichetta !== '' ? '<a href="' . $h($href) . '" class="btn btn-primary fw-bold px-4 rounded-pill">' . $h($etichetta) . '</a>' : '') . '</div>';
    }
}

if (!function_exists('ui_link_non_valido')) {
    /**
     * Pagina di un link personale (firma, conferma, lettera) che non vale più: dice perché e porta a ciò che aspetta la persona. Se ha già fatto l'operazione
     * non serve altro; altrimenti il compito si ritrova in «Da fare per te» dell'area personale.
     */
    function ui_link_non_valido(string $titolo, string $motivo): string {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<h1 class="fw-bold h3">' . $h($titolo) . '</h1><p>' . $h($motivo) . '</p>'
            . '<p class="text-secondary">Se hai già fatto quello che ti era richiesto non serve altro. Se invece ti aspetta ancora qualcosa, la trovi in «Da fare per te» nella tua area personale.</p>'
            . '<a class="btn btn-primary fw-bold" href="area_personale.php#da-fare"><i class="fa fa-list-check me-1" aria-hidden="true"></i>Vai a «Da fare per te»</a>';
    }
}

if (!function_exists('ui_prossima_cosa')) {
    /**
     * «Prossima cosa da fare»: va messa subito dopo flash_html(). Compare solo se il messaggio appena mostrato era un successo (compito chiuso) e alla persona
     * resta qualcosa in «Da fare per te»; altrimenti non scrive niente.
     */
    function ui_prossima_cosa(): string {
        global $conn, $user_info;
        if (($GLOBALS['_flash_mostrato'] ?? '') !== 'success' || empty($user_info['id'])) {
            return '';
        }
        try {
            $p = \App\Core\App::per($conn)->get(\App\Portale\ProssimaCosa::class)->perUtente($user_info, (string) ($_SERVER['REQUEST_URI'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
        if ($p === null) {
            return '';
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<div class="alert alert-info border-0 border-start border-4 border-info d-flex flex-wrap align-items-center gap-2" role="status"><div class="flex-grow-1">'
            . '<strong>Prossima cosa da fare:</strong> ' . $h($p['testo']) . '<span class="d-block small text-secondary">' . $h($p['dettaglio']) . ($p['altre'] > 0 ? ' · e altre ' . (int) $p['altre'] : '') . '</span></div>'
            . '<a class="btn btn-sm btn-primary fw-bold" href="' . $h($p['link']) . '">Vai</a><a class="btn btn-sm btn-outline-secondary" href="area_personale.php">Area personale</a></div>';
    }
}

if (!function_exists('ui_intestazione')) {
    /** Titolo di una pagina interna, con il «torna a…» sopra. */
    function ui_intestazione(string $titolo, string $indietro_href = 'area_personale.php', string $indietro_et = 'Area personale', string $sottotitolo = ''): string {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<header class="ui-pagina-testa">' . ($indietro_href !== '' ? '<a href="' . $h($indietro_href) . '" class="ui-indietro"><i class="fa fa-arrow-left me-1" aria-hidden="true"></i>' . $h($indietro_et) . '</a>' : '')
            . '<h1>' . $h($titolo) . '</h1>' . ($sottotitolo !== '' ? '<p>' . $h($sottotitolo) . '</p>' : '') . '</header>';
    }
}

if (!function_exists('ui_tema_script')) {
    /**
     * Tema chiaro/scuro per le pagine che lo prevedono: parte dalla scelta fatta (localStorage) o da quella del sistema, e se c'è il pulsante
     * (ui_tema_pulsante) lo fa cambiare. Va stampato dentro <body>, prima del contenuto.
     */
    function ui_tema_script(): string {
        return "<script>(function(){var d=document.documentElement,k='tema_dibest',s=null;try{s=localStorage.getItem(k);}catch(e){}"
            . "var scuro=s?s==='scuro':(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);"
            . "document.body.classList.add('ui-scuro-ok');if(scuro)d.setAttribute('data-tema','scuro');"
            . "window.uiTemaAgg=function(){var b=document.getElementById('uiTemaBtn');if(!b)return;var sc=d.getAttribute('data-tema')==='scuro';"
            . "b.setAttribute('aria-pressed',sc?'true':'false');b.querySelector('span').textContent=sc?'Tema chiaro':'Tema scuro';b.querySelector('i').className='fa '+(sc?'fa-sun':'fa-moon');};"
            . "window.uiTemaCambia=function(){var sc=d.getAttribute('data-tema')!=='scuro';if(sc)d.setAttribute('data-tema','scuro');else d.removeAttribute('data-tema');"
            . "try{localStorage.setItem(k,sc?'scuro':'chiaro');}catch(e){}window.uiTemaAgg();};"
            . "document.addEventListener('DOMContentLoaded',window.uiTemaAgg);})();</script>";
    }
}

if (!function_exists('ui_tema_pulsante')) {
    function ui_tema_pulsante(): string {
        return '<button type="button" class="ui-tema-btn" id="uiTemaBtn" onclick="uiTemaCambia()" aria-pressed="false"><i class="fa fa-moon" aria-hidden="true"></i><span>Tema scuro</span></button>';
    }
}
