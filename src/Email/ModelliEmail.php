<?php

declare(strict_types=1);

namespace App\Email;

use App\Core\Database;

/**
 * Oggetto e testo delle email del portale, modificabili dalla pagina «Email» del pannello.
 *
 * Il catalogo (CatalogoEmail) contiene i testi originali; la tabella email_modelli solo le modifiche: senza riga vale il testo originale.
 * Il testo si scrive in un formato semplice, non in HTML: righe vuote = paragrafi, «- » a inizio riga = elenco, «> » = citazione,
 * **così** = grassetto. I dati si inseriscono con i segnaposto {{nome}} (sempre «ripuliti»: non possono introdurre HTML); i segnaposto
 * {{!nome}} sono pezzi di HTML preparati dal portale (tabelle, elenchi) e si usano da soli su una riga.
 */
final class ModelliEmail
{
    public const OGGETTO_MAX = 250;
    public const CORPO_MAX = 8000;
    public const BOTTONE_MAX = 80;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $modifiche = null;

    public function __construct(private Database $db)
    {
    }

    /** @return array<string, array<string, mixed>> chiave => definizione originale, nell'ordine del catalogo */
    public function catalogo(): array
    {
        return CatalogoEmail::modelli();
    }

    /** @return array<string, mixed>|null */
    public function originale(string $chiave): ?array
    {
        return CatalogoEmail::modelli()[$chiave] ?? null;
    }

    /**
     * Il modello com'è ora (originale con le eventuali modifiche sopra).
     *
     * @return array<string, mixed>|null definizione + 'modificato' (bool), 'aggiornato_il', 'aggiornato_da'
     */
    public function modello(string $chiave): ?array
    {
        $m = $this->originale($chiave);
        if ($m === null) {
            return null;
        }
        $r = $this->tutteLeModifiche()[$chiave] ?? null;
        $m['modificato'] = $r !== null;
        $m['aggiornato_il'] = $r['aggiornato_il'] ?? null;
        $m['aggiornato_da'] = (string) ($r['aggiornato_da'] ?? '');
        if ($r !== null) {
            $m['oggetto'] = (string) $r['oggetto'];
            $m['corpo'] = (string) $r['corpo'];
            $m['bottone'] = $m['bottone'] === null ? null : (string) $r['bottone'];
        }

        return $m;
    }

    /**
     * Prepara l'email con i dati: oggetto in testo semplice, corpo in HTML, testo del pulsante (null se l'email non ha pulsante).
     *
     * @param array<string, scalar|null> $dati valori dei segnaposto
     * @return array{oggetto: string, corpo: string, bottone: ?string}
     */
    public function render(string $chiave, array $dati): array
    {
        $m = $this->modello($chiave);
        if ($m === null) {
            throw new \InvalidArgumentException("Modello di email sconosciuto: $chiave");
        }

        return $this->componi((string) $m['oggetto'], (string) $m['corpo'], $m['bottone'], $dati);
    }

    /**
     * Come render() ma con testi dati (per l'anteprima nella pagina Email, prima di salvare).
     *
     * @param array<string, scalar|null> $dati
     * @return array{oggetto: string, corpo: string, bottone: ?string}
     */
    public function componi(string $oggetto, string $corpo, ?string $bottone, array $dati): array
    {
        return [
            'oggetto' => $this->sostituisci($oggetto, $dati, false),
            'corpo' => $this->inHtml($corpo, $dati),
            'bottone' => $bottone === null ? null : $this->sostituisci($bottone, $dati, false),
        ];
    }

    /**
     * Controlla i testi prima di salvarli.
     *
     * @return list<string> problemi (vuoto = va bene)
     */
    public function controlla(string $chiave, string $oggetto, string $corpo, string $bottone): array
    {
        $m = $this->originale($chiave);
        if ($m === null) {
            return ['Email sconosciuta.'];
        }
        $err = [];
        $oggetto = trim($oggetto);
        if ($oggetto === '') {
            $err[] = "L'oggetto non può essere vuoto.";
        } elseif (mb_strlen($oggetto) > self::OGGETTO_MAX) {
            $err[] = "L'oggetto è troppo lungo (massimo " . self::OGGETTO_MAX . ' caratteri).';
        }
        if (trim($corpo) === '') {
            $err[] = 'Il testo non può essere vuoto.';
        } elseif (mb_strlen($corpo) > self::CORPO_MAX) {
            $err[] = 'Il testo è troppo lungo (massimo ' . self::CORPO_MAX . ' caratteri).';
        }
        if ($m['bottone'] !== null) {
            if (trim($bottone) === '') {
                $err[] = 'Il pulsante deve avere un testo.';
            } elseif (mb_strlen($bottone) > self::BOTTONE_MAX) {
                $err[] = 'Il testo del pulsante è troppo lungo.';
            }
        }
        $ammessi = array_keys($m['segnaposto']);
        foreach ([$oggetto, $corpo, $bottone] as $testo) {
            if (preg_match_all('/\{\{(!?)([a-z0-9_]*)\}\}/i', $testo, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    if (!in_array($x[1] . $x[2], $ammessi, true)) {
                        $err[] = 'Il segnaposto {{' . $x[1] . $x[2] . '}} non esiste per questa email.';
                    }
                }
            }
        }
        foreach ($m['segnaposto'] as $nome => $_) {
            if ($nome[0] === '!') {
                // un pezzo di HTML preparato dal portale deve stare da solo su una riga, altrimenti salterebbe l'impaginazione
                foreach (preg_split('/\R/', $corpo) ?: [] as $riga) {
                    if (str_contains($riga, '{{' . $nome . '}}') && trim($riga) !== '{{' . $nome . '}}') {
                        $err[] = 'Il segnaposto {{' . $nome . '}} va scritto da solo su una riga.';
                        break;
                    }
                }
            }
        }
        if (preg_match('/\{\{|\}\}/', (string) preg_replace('/\{\{!?[a-z0-9_]*\}\}/i', '', $oggetto . "\n" . $corpo . "\n" . $bottone))) {
            $err[] = 'C\'è un segnaposto scritto male: deve essere {{nome}}, con due graffe per parte.';
        }

        return array_values(array_unique($err));
    }

    /** Salva il testo modificato. Ritorna i problemi (vuoto = salvato). @return list<string> */
    public function salva(string $chiave, string $oggetto, string $corpo, string $bottone, string $autore): array
    {
        $oggetto = trim(preg_replace('/\s+/', ' ', $oggetto) ?? $oggetto);
        $corpo = trim(str_replace("\r\n", "\n", $corpo));
        $bottone = trim($bottone);
        if ($err = $this->controlla($chiave, $oggetto, $corpo, $bottone)) {
            return $err;
        }
        $m = $this->originale($chiave);
        if ($m['bottone'] === null) {
            $bottone = '';
        }
        $this->db->esegui(
            'INSERT INTO email_modelli (chiave, oggetto, corpo, bottone, aggiornato_il, aggiornato_da) VALUES (?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE oggetto = VALUES(oggetto), corpo = VALUES(corpo), bottone = VALUES(bottone), aggiornato_il = NOW(), aggiornato_da = VALUES(aggiornato_da)',
            [$chiave, $oggetto, $corpo, $bottone, mb_substr($autore, 0, 150)]
        );
        $this->modifiche = null;

        return [];
    }

    /** Torna al testo originale (cancella la modifica). */
    public function ripristina(string $chiave): void
    {
        $this->db->esegui('DELETE FROM email_modelli WHERE chiave = ?', [$chiave]);
        $this->modifiche = null;
    }

    /** Dati d'esempio per l'anteprima e per la prova: un valore per ogni segnaposto del modello. @return array<string, string> */
    public function datiDEsempio(string $chiave): array
    {
        $m = $this->originale($chiave);
        $d = [];
        foreach (($m['segnaposto'] ?? []) as $nome => $info) {
            $d[ltrim($nome, '!')] = (string) ($info['esempio'] ?? '…');
        }

        return $d;
    }

    /** @return array<string, array<string, mixed>> */
    private function tutteLeModifiche(): array
    {
        if ($this->modifiche === null) {
            $this->modifiche = [];
            try {
                foreach ($this->db->righe('SELECT chiave, oggetto, corpo, bottone, aggiornato_il, aggiornato_da FROM email_modelli') as $r) {
                    $this->modifiche[(string) $r['chiave']] = $r;
                }
            } catch (\Throwable) {
                // tabella non ancora creata: valgono i testi originali
            }
        }

        return $this->modifiche;
    }

    /** @param array<string, scalar|null> $dati */
    private function sostituisci(string $testo, array $dati, bool $perHtml): string
    {
        return (string) preg_replace_callback('/\{\{(!?)([a-z0-9_]+)\}\}/i', function (array $x) use ($dati, $perHtml): string {
            $v = (string) ($dati[$x[2]] ?? '');
            if ($x[1] === '!') {
                return $perHtml ? $v : '';
            }
            if (!$perHtml) {
                return trim(preg_replace('/\s+/', ' ', $v) ?? $v);
            }

            return str_replace(["\r\n", "\n", "\r"], '<br>', htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        }, $testo);
    }

    /**
     * Dal formato semplice all'HTML dell'email. Prima si ripulisce il testo scritto nel modello, poi si inseriscono i segnaposto
     * (così un dato con degli asterischi o dei simboli non cambia la formattazione).
     *
     * @param array<string, scalar|null> $dati
     */
    private function inHtml(string $corpo, array $dati): string
    {
        $stile = "margin:12px 0;padding:8px 14px;border-left:4px solid #b45309;background:#fffbeb;";
        $out = '';
        foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $corpo))) ?: [] as $blocco) {
            $blocco = trim($blocco);
            if ($blocco === '') {
                continue;
            }
            if (preg_match('/^\{\{!([a-z0-9_]+)\}\}$/i', $blocco, $m)) {
                $out .= $this->sostituisci($blocco, $dati, true);
                continue;
            }
            $righe = preg_split('/\n/', $blocco) ?: [];
            $conTrattino = array_filter($righe, static fn (string $r): bool => str_starts_with(ltrim($r), '- '));
            $conCitazione = array_filter($righe, static fn (string $r): bool => str_starts_with(ltrim($r), '> '));
            if (count($conTrattino) === count($righe)) {
                $out .= '<ul>' . implode('', array_map(fn (string $r): string => '<li>' . $this->riga(substr(ltrim($r), 2), $dati) . '</li>', $righe)) . '</ul>';
            } elseif (count($conCitazione) === count($righe)) {
                $out .= "<blockquote style='$stile'>" . implode('<br>', array_map(fn (string $r): string => $this->riga(substr(ltrim($r), 2), $dati), $righe)) . '</blockquote>';
            } else {
                $out .= '<p>' . implode('<br>', array_map(fn (string $r): string => $this->riga($r, $dati), $righe)) . '</p>';
            }
        }

        return $out;
    }

    /** @param array<string, scalar|null> $dati */
    private function riga(string $r, array $dati): string
    {
        $segnaposto = [];
        // i segnaposto si mettono da parte con un segno che il testo non può contenere, si formatta, poi si rimettono
        $r = (string) preg_replace_callback('/\{\{(!?[a-z0-9_]+)\}\}/i', static function (array $m) use (&$segnaposto): string {
            $segnaposto[] = $m[0];

            return "\x01" . (count($segnaposto) - 1) . "\x02";
        }, $r);
        $r = htmlspecialchars($r, ENT_NOQUOTES, 'UTF-8'); // il testo del modello sta tra i tag, gli apici restano come scritti
        $r = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $r);

        return (string) preg_replace_callback('/\x01(\d+)\x02/', fn (array $m): string => $this->sostituisci($segnaposto[(int) $m[1]], $dati, true), $r);
    }
}
