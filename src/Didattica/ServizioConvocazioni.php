<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Core\Sito;
use App\Infrastructure\Mail\Mailer;

/**
 * Convocazione della seduta per email (facoltativa): l'operatore o il referente personalizza oggetto e testo, ogni componente riceve il suo
 * link per giustificare l'assenza (giustifica.php), che diventa «assente giustificato» nelle presenze della seduta.
 */
final class ServizioConvocazioni
{
    public function __construct(private Database $db, private ConsiglioRepository $consigli, private SedutaRepository $sedute, private Mailer $mailer, private Sito $sito)
    {
    }

    /**
     * Segnaposti: {NOME} {ORGANO} {DATA} {ORA} {LUOGO} {ODG} {COORDINATORE} {LINK_GIUSTIFICA}.
     *
     * @param array<string, mixed> $s
     */
    public static function testo(string $tpl, array $s, string $nome, string $link): string
    {
        $odg = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$s['odg']) ?: [])));
        $odg = implode("\n", array_map(fn ($i, $t) => ($i + 1) . '. ' . (string) preg_replace('/^\d+[\.\)]\s*/', '', $t), array_keys($odg), $odg));
        $sost = ['{NOME}' => $nome, '{ORGANO}' => (string)$s['organo'], '{DATA}' => $s['data'] ? date('d/m/Y', strtotime($s['data'])) : '____',
                 '{ORA}' => $s['ora_inizio'] ?: '____', '{LUOGO}' => (string)($s['luogo'] ?: '____'), '{ODG}' => $odg, '{COORDINATORE}' => (string)$s['coordinatore'], '{LINK_GIUSTIFICA}' => $link];
        if ($link === '') {
            $tpl = (string) preg_replace('/^.*\{LINK_GIUSTIFICA\}.*\R?/mu', '', $tpl);
        }
        return strtr($tpl, $sost);
    }

    public function invia(array $s, string $oggetto, string $testo, bool $con_link): array
    {
        if (empty($s['consiglio_id'])) {
            return [0, 0, "La convocazione si invia per le sedute di un consiglio con i componenti."];
        }
        if (!$s['data']) {
            return [0, 0, "Indica prima la data della seduta."];
        }
        if ($s['data'] < date('Y-m-d')) {
            return [0, 0, "La seduta è già passata."];
        }
        $oggetto = mb_substr(trim($oggetto), 0, 255);
        $testo = mb_substr(trim($testo), 0, 10000);
        if ($oggetto === '' || $testo === '') {
            return [0, 0, "Scrivi l'oggetto e il testo dell'email."];
        }
        $comp = $this->consigli->persone((int)$s['consiglio_id']);
        if (!$comp) {
            return [0, 0, "Il consiglio non ha componenti."];
        }
        $this->db->esegui("UPDATE didattica_sedute SET convocazione_oggetto = ?, convocazione_testo = ?, convocazione_il = NOW() WHERE id = ?", [$oggetto, $testo, (int)$s['id']]);
        $n = 0;
        $senza = 0;
        $h = fn ($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        foreach ($comp as $c) {
            $email = strtolower(trim((string)$c['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $senza++;
                continue;
            }
            $tok = $this->db->valore("SELECT token FROM didattica_convocazioni WHERE seduta_id = ? AND componente_id = ?", [(int)$s['id'], (int)$c['id']]);
            if (!$tok) {
                $tok = bin2hex(random_bytes(20));
                $this->db->esegui("INSERT INTO didattica_convocazioni (seduta_id, componente_id, email, token) VALUES (?, ?, ?, ?)", [(int)$s['id'], (int)$c['id'], $email, $tok]);
            }
            $this->db->esegui("UPDATE didattica_convocazioni SET email = ?, inviata_il = NOW() WHERE token = ?", [$email, $tok]);
            $link = $con_link ? $this->sito->urlBase() . '/giustifica.php?t=' . $tok : '';
            // Testo in HTML: il link diventa un collegamento
            $corpo = nl2br($h(self::testo($testo, $s, (string)$c['nominativo'], $link !== '' ? '%%LINK%%' : '')));
            $corpo = str_replace('%%LINK%%', "<a href='" . $h($link) . "'>giustifica l'assenza</a>", $corpo);
            $this->mailer->invia($email, self::testo($oggetto, $s, (string)$c['nominativo'], ''), "<div style='font-size:14px;line-height:1.5;'>$corpo</div>", '#0056B3');
            $n++;
        }
        return [$n, $senza, null];
    }

    public function perToken(string $tok): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $tok)) {
            return null;
        }
        return $this->db->riga("SELECT c.*, p.nominativo, p.qualifica FROM didattica_convocazioni c JOIN didattica_consigli_persone p ON p.id = c.componente_id WHERE c.token = ?", [$tok]);
    }

    public function giustifica(string $tok, string $motivo): ?string
    {
        $c = $this->perToken($tok);
        $s = $c ? $this->sedute->perId((int)$c['seduta_id']) : null;
        if (!$c || !$s) {
            return "Il link non è valido.";
        }
        if ($s['data'] && $s['data'] < date('Y-m-d')) {
            return "La seduta si è già svolta: per giustificare scrivi al coordinatore.";
        }
        $motivo = mb_substr(trim($motivo), 0, 500);
        $ordine = (int)$this->db->valore("SELECT COUNT(*) FROM didattica_sedute_presenze WHERE seduta_id = ?", [(int)$s['id']]);
        $this->db->esegui("INSERT INTO didattica_sedute_presenze (seduta_id, componente_id, nominativo, qualifica, ordine, stato) VALUES (?, ?, ?, ?, ?, 'AG')
                          ON DUPLICATE KEY UPDATE stato = 'AG'", [(int)$s['id'], (int)$c['componente_id'], (string)$c['nominativo'], (string)$c['qualifica'], 1000 + $ordine]);
        $this->db->esegui("UPDATE didattica_convocazioni SET giustificata_il = NOW(), motivo = ? WHERE id = ?", [$motivo, (int)$c['id']]);
        return null;
    }

    public function dellaSeduta(int $sid): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT * FROM didattica_convocazioni WHERE seduta_id = ?", [$sid]) as $r) {
            $out[(int)$r['componente_id']] = $r;
        }
        return $out;
    }

    public function conserva(int $mesi): int
    {
        if ($mesi <= 0) {
            return 0;
        }
        return max(0, $this->db->esegui("DELETE c FROM didattica_convocazioni c JOIN didattica_sedute s ON s.id = c.seduta_id WHERE s.data < CURDATE() - INTERVAL ? MONTH", [$mesi]));
    }
}
