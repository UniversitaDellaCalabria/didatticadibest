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
     * Scheda di progetti ed eventi: tendina dei corsi di studio + testo libero (name=struttura). Scegliendo un corso il testo si
     * compila con il nome, che resta modificabile; «Altro» lascia scrivere una struttura qualsiasi.
     *
     * @param array<string, list<array<string, mixed>>> $corsiPerTipo corsi visibili, per tipo
     * @param array<string, mixed>|null $selezionato corso salvato che non è più tra i visibili
     */
    public static function sceltaScheda(array $corsiPerTipo, ?array $selezionato, string $codice, string $testo, string $id): string
    {
        $h = self::h(...);
        $out = '<select name="corso_codice" id="' . $h($id) . '" class="form-select form-select-sm mb-1" onchange="var o=this.options[this.selectedIndex], t=this.nextElementSibling; if (o.dataset.nome) t.value=o.dataset.nome;">'
              . '<option value="">Altro (scrivi sotto la struttura)</option>';
        $trovato = $codice === '';
        foreach ($corsiPerTipo as $tipo => $corsi) {
            $out .= '<optgroup label="' . $h($tipo) . '">';
            foreach ($corsi as $c) {
                $sel = $c['codice'] === $codice;
                if ($sel) {
                    $trovato = true;
                }
                $out .= '<option value="' . $h($c['codice']) . '" data-nome="' . $h(\App\Anagrafi\Testi::nomeSchedaCorso($c)) . '"' . ($sel ? ' selected' : '') . '>' . $h($c['nome']) . '</option>';
            }
            $out .= '</optgroup>';
        }
        if (!$trovato && $selezionato) {
            $out .= '<option value="' . $h($selezionato['codice']) . '" selected>' . $h(\App\Anagrafi\Testi::etichettaCorso($selezionato)) . '</option>';
        }
        $out .= '</select><input type="text" name="struttura" id="' . $h($id) . 'Testo" class="form-control form-control-sm" value="' . $h($testo) . '" placeholder="es. Corso di laurea in Scienze geologiche" maxlength="255" aria-label="Nome del corso o della struttura come appare nella scheda">'
              . '<div class="form-text">Scegliendo un corso il nome nella scheda pubblica porta alla pagina del corso sul portale di Ateneo. Il testo si può modificare.</div>';

        return $out;
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
