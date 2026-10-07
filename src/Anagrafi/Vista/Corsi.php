<?php

declare(strict_types=1);

namespace App\Anagrafi\Vista;

/** HTML dei campi «Corso di studio» e del nome del corso nelle schede pubbliche (stesso output di prima). */
final class Corsi
{
    private static function h(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Scheda di progetti ed eventi: uno o più corsi di studio dell'anagrafe (si sceglie dalla tendina e si preme «Aggiungi corso»; ognuno
     * si può togliere) e, a parte, un testo libero per una struttura o una nota (name=struttura). Nella scheda pubblica ogni corso porta
     * alla sua pagina sul portale di Ateneo.
     *
     * @param array<string, list<array<string, mixed>>> $corsiPerTipo corsi visibili, per tipo
     * @param list<array<string, mixed>> $scelti corsi già scelti (anche se non sono più tra i visibili)
     */
    public static function sceltaScheda(array $corsiPerTipo, array $scelti, string $testo, string $id): string
    {
        $h = self::h(...);
        $out = '<div id="' . $h($id) . 'Box"><ul class="list-unstyled mb-2" id="' . $h($id) . 'Elenco" aria-live="polite">';
        foreach ($scelti as $c) {
            $out .= self::voceCorso((string) $c['codice'], \App\Anagrafi\Testi::nomeSchedaCorso($c));
        }
        $out .= '</ul><div class="d-flex gap-2 mb-1"><select id="' . $h($id) . '" class="form-select form-select-sm" aria-label="Corso di studio da aggiungere">'
              . '<option value="">-- Scegli un corso di studio --</option>';
        foreach ($corsiPerTipo as $tipo => $corsi) {
            $out .= '<optgroup label="' . $h($tipo) . '">';
            foreach ($corsi as $c) {
                $out .= '<option value="' . $h($c['codice']) . '" data-nome="' . $h(\App\Anagrafi\Testi::nomeSchedaCorso($c)) . '">' . $h($c['nome']) . '</option>';
            }
            $out .= '</optgroup>';
        }
        $out .= '</select><button type="button" class="btn btn-sm btn-outline-primary fw-bold text-nowrap" id="' . $h($id) . 'Aggiungi"><i class="fa fa-plus me-1" aria-hidden="true"></i>Aggiungi corso</button></div>'
              . '<label class="form-label small fw-bold mt-2 mb-1" for="' . $h($id) . 'Testo">Altra struttura o nota (facoltativa)</label>'
              . '<input type="text" name="struttura" id="' . $h($id) . 'Testo" class="form-control form-control-sm" value="' . $h($testo) . '" placeholder="es. Dipartimento DiBEST, o un corso non in elenco" maxlength="255">'
              . '<div class="form-text">Un\'attività può riferirsi a più corsi di laurea: aggiungili uno alla volta. Nella scheda pubblica ogni corso porta alla sua pagina sul portale di Ateneo.</div></div>'
              . '<template id="' . $h($id) . 'Modello">' . self::voceCorso('__CODICE__', '__NOME__') . '</template>'
              . '<script>(function(){var s=document.getElementById(' . json_encode($id) . '),b=document.getElementById(' . json_encode($id . 'Aggiungi') . '),l=document.getElementById(' . json_encode($id . 'Elenco') . '),m=document.getElementById(' . json_encode($id . 'Modello') . ');'
              . 'l.addEventListener("click",function(e){var x=e.target.closest(".corso-togli");if(x){x.closest("li").remove();}});'
              . 'b.addEventListener("click",function(){var o=s.options[s.selectedIndex];if(!o.value)return;'
              . 'if(l.querySelector("input[value=\\""+o.value+"\\"]")){s.value="";return;}'
              . 'var h=m.innerHTML.split("__CODICE__").join(o.value).split("__NOME__").join(o.dataset.nome.replace(/&/g,"&amp;").replace(/</g,"&lt;"));l.insertAdjacentHTML("beforeend",h);s.value="";});})();</script>';

        return $out;
    }

    private static function voceCorso(string $codice, string $nome): string
    {
        $h = self::h(...);

        return '<li class="d-flex align-items-center gap-2 border rounded px-2 py-1 mb-1 bg-light"><i class="fa fa-building-columns text-secondary" aria-hidden="true"></i>'
             . '<input type="hidden" name="corsi_codici[]" value="' . $h($codice) . '"><span class="flex-grow-1 small fw-bold">' . $h($nome) . '</span>'
             . '<button type="button" class="btn btn-sm btn-outline-danger corso-togli py-0" aria-label="Togli il corso ' . $h($nome) . '"><i class="fa fa-xmark" aria-hidden="true"></i></button></li>';
    }

    /**
     * Corsi di un'attività nella scheda pubblica: il testo della struttura (se c'è e non è già il nome di un corso) e ogni corso come
     * link alla sua pagina sul portale di Ateneo.
     *
     * @param list<array{0: string, 1: string}> $corsi [nome, indirizzo della pagina del corso o '']
     */
    public static function pubblicoElenco(string $testo, array $corsi, string $stile): string
    {
        $voci = [];
        $nomi = array_map(static fn (array $c): string => mb_strtolower($c[0]), $corsi);
        if ($testo !== '' && !in_array(mb_strtolower($testo), $nomi, true)) {
            $voci[] = htmlspecialchars($testo, ENT_QUOTES, 'UTF-8');
        }
        foreach ($corsi as [$nome, $url]) {
            $voci[] = self::pubblico($nome, $url, $stile);
        }

        return implode(' <span aria-hidden="true">·</span> ', $voci);
    }
    /**
     * Campo «Corso di studio»: tendina con i corsi dei dipartimenti dell'anagrafe. Si salva il nome del corso con il tipo,
     * es. «Scienze geologiche (Laurea)». Un valore salvato che non è più in elenco resta selezionabile.
     *
     * @param array<string, list<array<string, mixed>>> $corsiPerTipo
     */
    public static function campo(array $corsiPerTipo, string $campo, string $valore, string $attr, string $classi, string $id): string
    {
        $h = self::h(...);
        $out = '<select name="custom_' . $h($campo) . '"' . ($id !== '' ? ' id="' . $h($id) . '"' : '') . ' class="' . $h($classi) . '" ' . $attr . '><option value="">-- Scegli il corso --</option>';
        $trovato = $valore === '';
        foreach ($corsiPerTipo as $tipo => $corsi) {
            $out .= '<optgroup label="' . $h($tipo) . '">';
            foreach ($corsi as $c) {
                $et = \App\Anagrafi\Testi::etichettaCorso($c);
                $sel = $et === $valore;
                if ($sel) {
                    $trovato = true;
                }
                $out .= '<option value="' . $h($et) . '"' . ($sel ? ' selected' : '') . '>' . $h($c['nome']) . '</option>';
            }
            $out .= '</optgroup>';
        }
        if (!$trovato) {
            $out .= '<option value="' . $h($valore) . '" selected>' . $h($valore) . '</option>';
        }

        return $out . '</select>';
    }

    /** Nome del corso o della struttura nelle schede pubbliche, con il link alla pagina del corso se scelto dall'anagrafe. */
    public static function pubblico(string $testo, string $url, string $stile): string
    {
        if ($testo === '') {
            return '';
        }
        $h = htmlspecialchars($testo, ENT_QUOTES, 'UTF-8');

        return $url !== '' ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" style="color:inherit;' . $stile . '">' . $h . ' <i class="fa fa-arrow-up-right-from-square small" aria-hidden="true"></i><span class="visually-hidden"> (pagina del corso, nuova scheda)</span></a>' : $h;
    }
}
