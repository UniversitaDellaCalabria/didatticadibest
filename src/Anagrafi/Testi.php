<?php

declare(strict_types=1);

namespace App\Anagrafi;

/**
 * Nomi ed etichette delle anagrafi (scuole, persone, corsi, insegnamenti): funzioni pure, senza database.
 * Spostate da inc/anagrafi.php e inc/catalogo_ateneo.php, dove restano le funzioni di prima come facciate.
 */
final class Testi
{
    /** Sigle delle scuole, sempre in maiuscolo */
    private const SIGLE_SCUOLE = ['Iis', 'Iiss', 'Is', 'Isis', 'Iti', 'Itis', 'Itc', 'Itcg', 'Itg', 'Ite', 'Ita', 'Itt', 'Ipsia', 'Ipsar', 'Ipssar', 'Ipsseoa', 'Ipc', 'Ips', 'Ic', 'Cpia', 'Sm', 'Smd', 'Ss', 'Ls', 'Lc', 'Ee', 'Ctp', 'Ipseoa', 'Ssig'];

    /** Solo preposizioni: gli articoli ("I Girasoli", "La Salle") fanno spesso parte del nome */
    private const PREPOSIZIONI = ['Di', 'Del', 'Della', 'Delle', 'Dei', 'Degli', 'Dello', 'Da', 'Dal', 'Dalla', 'In', 'E', 'Ed', 'Per', 'Con', 'Su', 'Sul', 'Sulla', 'A', 'Al', 'Alla', 'Allo', 'Ai', 'Agli'];

    /** "LICEO SCIENTIFICO E. FERMI" -> "Liceo Scientifico E. Fermi" (l'anagrafe del Ministero usa il maiuscolo) */
    public static function maiuscoleScuola(string $s): string
    {
        $s = mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
        $s = (string) preg_replace_callback("/(?<=['’])\p{Ll}/u", static fn (array $m): string => mb_strtoupper($m[0]), $s);
        // Sigle delle scuole in maiuscolo, preposizioni in minuscolo (non all'inizio)
        $parole = preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parole as $i => $p) {
            $nudo = trim($p, '"«».,()');
            if (in_array($nudo, self::SIGLE_SCUOLE, true)) {
                $parole[$i] = str_replace($nudo, mb_strtoupper($nudo), $p);
            } elseif ($i > 0 && in_array($nudo, self::PREPOSIZIONI, true) && $nudo === $p) {
                $parole[$i] = mb_strtolower($p);
            } elseif ($i > 0 && preg_match("/^(Dell|Dall|All|Nell|Sull|Dagl|Degl)(['’])/u", $p)) {
                $parole[$i] = mb_strtolower(mb_substr($p, 0, 1)) . mb_substr($p, 1);
            }
        }

        return implode('', $parole);
    }

    /**
     * Nome della scuola da mostrare: denominazione e comune, es. "Liceo Scientifico E. Fermi – Cosenza"
     *
     * @param array<string, mixed> $s riga della tabella scuole
     */
    public static function etichettaScuola(array $s): string
    {
        $nome = self::maiuscoleScuola((string) ($s['denominazione'] ?? ''));
        $comune = self::maiuscoleScuola((string) ($s['comune'] ?? ''));

        return $comune !== '' && mb_stripos($nome, $comune) === false ? "$nome – $comune" : $nome;
    }

    /** "D'AMICO MARIA" -> "D'Amico Maria" */
    public static function maiuscoleNome(string $s): string
    {
        $s = mb_convert_case(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s))), MB_CASE_TITLE, 'UTF-8');

        return (string) preg_replace_callback("/(?<=['’-])\p{Ll}/u", static fn (array $m): string => mb_strtoupper($m[0]), $s);
    }

    /**
     * L'elenco del portale dà "COGNOME NOME" e l'ID "nome.cognome": il nome è la parte finale che, senza
     * spazi e accenti, coincide con la prima parte dell'ID. Altrimenti: prima parola = cognome.
     *
     * @return array{0: string, 1: string} [cognome, nome]
     */
    public static function separaCognomeNome(string $nominativo, string $id): array
    {
        $parole = array_values(array_filter(explode(' ', trim((string) preg_replace('/\s+/u', ' ', $nominativo))), static fn (string $p): bool => $p !== ''));
        $pulisci = static function (string $s): string {
            $a = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);

            return (string) preg_replace('/[^a-z]/', '', strtolower($a !== false ? $a : $s));
        };
        $idNome = $pulisci(explode('.', $id)[0]);
        for ($k = 1; $k < count($parole); $k++) {
            if ($idNome !== '' && $pulisci(implode('', array_slice($parole, $k))) === $idNome) {
                return [self::maiuscoleNome(implode(' ', array_slice($parole, 0, $k))), self::maiuscoleNome(implode(' ', array_slice($parole, $k)))];
            }
        }

        return [self::maiuscoleNome($parole[0] ?? ''), self::maiuscoleNome(implode(' ', array_slice($parole, 1)))];
    }

    /** Gruppo automatico (docenti, pta, altro) dal codice ruolo; $docente: nell'elenco dei docenti del dipartimento */
    public static function gruppoPersonale(string $ruoloCod, bool $docente = false): string
    {
        if (in_array($ruoloCod, Anagrafe::RUOLI_DOCENTI, true)) {
            return 'docenti';
        }
        if (in_array($ruoloCod, Anagrafe::RUOLI_PTA, true)) {
            return 'pta';
        }
        // Borsisti, dottorandi, assegnisti e contratti di ricerca restano in "Altro" anche se tengono lezioni
        return $docente && in_array($ruoloCod, Anagrafe::RUOLI_CONTRATTI, true) ? 'docenti' : 'altro';
    }

    /** "SCIENZE GEOLOGICHE" -> "Scienze geologiche"; i nomi già scritti in minuscolo restano come sono */
    public static function maiuscoleCorso(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '' || mb_strtoupper($s) !== $s) {
            return $s;
        }
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));

        // Codici delle classi di concorso (A028, A050…) e numeri romani ("II grado") in maiuscolo
        return (string) preg_replace_callback('/\b([a-z]\d{2,3}|ii|iii|iv|vi|vii)\b/u', static fn (array $m): string => mb_strtoupper($m[0]), $s);
    }

    /** @param array<string, mixed> $p */
    public static function nomePersona(array $p): string
    {
        return trim(($p['nome'] ?? '') . ' ' . ($p['cognome'] ?? ''));
    }

    /**
     * Pagina della persona sul portale di Ateneo (scheda docente o rubrica)
     *
     * @param array<string, mixed> $p
     */
    public static function urlPortalePersona(array $p): string
    {
        return 'https://www.unical.it/storage/' . (!empty($p['docente']) ? 'teachers' : 'addressbook') . '/' . rawurlencode((string) $p['id']) . '/';
    }

    /** Testi HTML delle API (biografia, orari di ricevimento): solo testo, con gli a capo */
    public static function testoDaHtmlApi(?string $html): string
    {
        $t = (string) preg_replace('#<\s*(br|/p|/li|/div|/h\d)\s*/?>#i', "\n", (string) $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);

        return trim((string) preg_replace("/\n\s*\n+/", "\n", (string) preg_replace('/[ \t]+/', ' ', $t)));
    }

    /**
     * "Fondamenti di informatica · 1° anno · Primo Semestre · Masciari Elio"
     *
     * @param array<string, mixed> $i riga della tabella insegnamenti
     */
    public static function etichettaInsegnamento(array $i, bool $conCorso = false): string
    {
        return implode(' · ', array_filter([
            $i['nome'] . ($i['partizione'] !== '' ? ' (' . $i['partizione'] . ')' : ''),
            $conCorso ? $i['cds_nome'] : '',
            !empty($i['anno_corso']) ? (int) $i['anno_corso'] . '° anno' : '',
            $i['semestre'], $i['docente'],
        ]));
    }

    /**
     * Insegnamento nelle decisioni in seduta: "Nome – Corso · 6 CFU · BIO/01"
     *
     * @param array{nome: string, corso: string, cfu: ?float, ssd: string} $i
     */
    public static function etichettaInsegnamentoScelta(array $i): string
    {
        return $i['nome'] . ' – ' . $i['corso'] . ($i['cfu'] !== null ? ' · ' . rtrim(rtrim(number_format($i['cfu'], 1, ',', ''), '0'), ',') . ' CFU' : '') . ($i['ssd'] !== '' ? ' · ' . $i['ssd'] : '');
    }

    /**
     * Pagina del corso sul portale di Ateneo (serve l'ID del regolamento didattico)
     *
     * @param array<string, mixed>|null $c
     */
    public static function urlCorsoStudio(?array $c): string
    {
        return !empty($c['regdid_id']) ? 'https://www.unical.it/storage/cds/' . (int) $c['regdid_id'] . '/' : '';
    }

    /**
     * Nome proposto nella scheda: "Corso di laurea in Biologia", "Corso di laurea magistrale in …", altrimenti il nome del corso
     *
     * @param array<string, mixed> $c
     */
    public static function nomeSchedaCorso(array $c): string
    {
        $pref = ['L' => 'Corso di laurea in ', 'LM' => 'Corso di laurea magistrale in ', 'LM5' => 'Corso di laurea magistrale a ciclo unico in ', 'LM6' => 'Corso di laurea magistrale a ciclo unico in '][$c['tipo'] ?? ''] ?? '';

        return $pref . $c['nome'];
    }

    /**
     * "Scienze geologiche (Laurea)"
     *
     * @param array<string, mixed> $c
     */
    public static function etichettaCorso(array $c): string
    {
        return $c['nome'] . (!empty($c['tipo_descrizione']) ? ' (' . $c['tipo_descrizione'] . ')' : '');
    }

    /**
     * Crediti di un'attività delle API (il nome del campo cambia tra le versioni delle API)
     *
     * @param array<string, mixed> $r
     */
    public static function cfuAttivitaApi(array $r): ?float
    {
        foreach (['StudyActivityCFU', 'StudyActivityECTS', 'StudyActivityCredits', 'CFU', 'ECTS', 'Credits'] as $k) {
            if (isset($r[$k]) && is_numeric(str_replace(',', '.', (string) $r[$k]))) {
                return (float) str_replace(',', '.', (string) $r[$k]);
            }
        }

        return null;
    }
}
