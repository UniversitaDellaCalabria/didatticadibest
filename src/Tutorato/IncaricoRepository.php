<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;
use App\Eventi\Righe;

/** Lettere di incarico (tabella tutorato_incarichi) e il loro storico (tutorato_eventi). */
final class IncaricoRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Lettera con i dati del bando (decreti, direttore, operatore); valori come testo, come li dava $conn->query().
     *
     * @return array<string, string|null>|null
     */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT i.*, b.titolo AS bando_titolo, b.anno_accademico, b.decreto_bando, b.decreto_bando_data, b.decreto_commissione, b.decreto_commissione_data,
                    b.direttore_persona_id, b.direttore_nome, b.direttore_email, b.direttore_cf, b.operatore_id, b.luogo
             FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id WHERE i.id = ?',
            [$id]
        ));
    }

    /** Id della lettera con quel link personale ($ruolo: studente | docente | direttore | fine), 0 se non c'è. */
    public function idPerToken(string $ruolo, string $token): int
    {
        if (!in_array($ruolo, Costanti::RUOLI_TOKEN, true)) {
            return 0;
        }

        return (int) $this->db->valore("SELECT id FROM tutorato_incarichi WHERE token_$ruolo = ? LIMIT 1", [$token]);
    }

    /**
     * Le lettere di un bando (annullate in fondo).
     *
     * @return list<array<string, mixed>>
     */
    public function delBando(int $bandoId): array
    {
        return $this->db->righe("SELECT * FROM tutorato_incarichi WHERE bando_id = ? ORDER BY stato = 'annullata', cognome, nome", [$bandoId]);
    }

    /**
     * Id delle lettere firmate o protocollate, dalla più recente (le visibili nel registro).
     *
     * @return list<int>
     */
    public function idFirmate(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe("SELECT id FROM tutorato_incarichi WHERE stato IN ('firmata', 'protocollata') ORDER BY creata_il DESC"));
    }

    /**
     * Id delle lettere da seguire con i promemoria e i solleciti (non ancora anonimizzate).
     *
     * @return list<int>
     */
    public function idDaSeguire(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe("SELECT id FROM tutorato_incarichi WHERE stato IN ('confermata', 'firmata_docente', 'firmata', 'protocollata') AND anonimizzata = 0"));
    }

    /**
     * Id delle lettere da anonimizzare: annullate o protocollate (con la fine attività protocollata, se c'è) ferme da più di $mesi mesi.
     *
     * @return list<int>
     */
    public function idDaConservare(int $mesi): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            "SELECT id FROM tutorato_incarichi WHERE anonimizzata = 0 AND COALESCE(aggiornata_il, creata_il) < NOW() - INTERVAL ? MONTH
             AND (stato = 'annullata' OR (stato = 'protocollata' AND fine_stato IN ('', 'protocollata'))) LIMIT 500",
            [$mesi]
        ));
    }

    /**
     * Storico della lettera, dal più vecchio.
     *
     * @return list<array<string, mixed>>
     */
    public function eventi(int $id): array
    {
        return $this->db->righe('SELECT * FROM tutorato_eventi WHERE incarico_id = ? ORDER BY creato_il, id', [$id]);
    }

    /** Storico della lettera (chi, cosa, quando, da quale indirizzo) e data dell'ultimo aggiornamento. */
    public function aggiungiEvento(int $id, string $tipo, string $testo, string $autore, string $ip): void
    {
        $this->db->esegui('INSERT INTO tutorato_eventi (incarico_id, tipo, testo, autore, ip) VALUES (?, ?, ?, ?, ?)', [$id, $tipo, $testo, $autore, $ip]);
        $this->db->esegui('UPDATE tutorato_incarichi SET aggiornata_il = NOW() WHERE id = ?', [$id]);
    }

    /** Cancella gli indirizzi IP dallo storico (conservazione dei dati). */
    public function cancellaIpEventi(int $id): void
    {
        $this->db->esegui("UPDATE tutorato_eventi SET ip = '' WHERE incarico_id = ?", [$id]);
    }

    /**
     * Lettera nuova in bozza.
     *
     * @param list<mixed> $v bando, codice, genere, cognome, nome, luogo e data di nascita, residenza (comune, indirizzo, civico), codice fiscale, email, telefono, attività, ore, periodo, compenso, docente (persona, nome, cognome, email, cf), data lettera
     */
    public function inserisci(array $v): int
    {
        return $this->db->inserisci(
            'INSERT INTO tutorato_incarichi (bando_id, codice, genere, cognome, nome, luogo_nascita, data_nascita, comune_residenza, indirizzo, civico, codice_fiscale, email, telefono, attivita, ore, periodo, compenso,
                                  docente_persona_id, docente_nome, docente_cognome, docente_email, docente_cf, data_lettera, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            $v
        );
    }

    /** @param list<mixed> $v come inserisci() senza bando e codice */
    public function aggiornaBozza(int $id, array $v): void
    {
        $this->db->esegui(
            'UPDATE tutorato_incarichi SET genere=?, cognome=?, nome=?, luogo_nascita=?, data_nascita=?, comune_residenza=?, indirizzo=?, civico=?, codice_fiscale=?, email=?, telefono=?, attivita=?, ore=?, periodo=?, compenso=?,
                                  docente_persona_id=?, docente_nome=?, docente_cognome=?, docente_email=?, docente_cf=?, data_lettera=?, aggiornata_il=NOW() WHERE id=?',
            [...$v, $id]
        );
    }

    public function impostaFilePdf(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET file_pdf = ?, aggiornata_il = NOW() WHERE id = ?', [$percorso, $id]);
    }

    public function segnaInviata(int $id, string $tokenStudente, string $dataLettera): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'inviata', token_studente = ?, inviata_il = NOW(), data_lettera = ? WHERE id = ?", [$tokenStudente, $dataLettera, $id]);
    }

    public function segnaConfermata(int $id, string $firmaJson, string $tokenDocente): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'confermata', studente_firma_json = ?, confermata_il = NOW(), token_docente = ? WHERE id = ?", [$firmaJson, $tokenDocente, $id]);
    }

    public function impostaNotaStudente(int $id, string $testo): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET nota_studente = ? WHERE id = ?', [$testo, $id]);
    }

    public function segnaFirmataDocente(int $id, string $tokenDirettore): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'firmata_docente', firmata_docente_il = NOW(), token_direttore = ?, solleciti = 0, sollecito_il = NULL WHERE id = ?", [$tokenDirettore, $id]);
    }

    public function segnaFirmataDirettore(int $id): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'firmata', firmata_direttore_il = NOW(), solleciti = 0, sollecito_il = NULL WHERE id = ?", [$id]);
    }

    public function segnaProtocollata(int $id, string $protocollo, string $data): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'protocollata', protocollo = ?, protocollo_data = ?, protocollata_il = NOW() WHERE id = ?", [$protocollo, $data, $id]);
    }

    /** Annulla la lettera e i link inviati. */
    public function annulla(int $id): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET stato = 'annullata', token_studente = NULL, token_docente = NULL, token_direttore = NULL, token_fine = NULL WHERE id = ?", [$id]);
    }

    /** Insegnamento, corso, titolo e periodo del docente (servono alla fine attività e ai promemoria). */
    public function salvaDatiFine(int $id, string $insegnamento, string $corso, string $titolo, ?string $inizio, ?string $fine): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET insegnamento_docente = ?, corso_laurea = ?, docente_titolo = ?, data_inizio = ?, data_fine = ?, aggiornata_il = NOW() WHERE id = ?', [$insegnamento, $corso, $titolo, $inizio, $fine, $id]);
    }

    public function segnaFineRichiesta(int $id): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_stato = 'richiesta', fine_richiesta_il = NOW(), aggiornata_il = NOW() WHERE id = ?", [$id]);
    }

    public function segnaFineDaFirmare(int $id, string $percorsoPdf, string $tokenFine, float $oreApprovate): void
    {
        $this->db->esegui(
            "UPDATE tutorato_incarichi SET fine_stato = 'da_firmare', fine_pdf = ?, token_fine = ?, ore_approvate = ?, fine_richiesta_il = COALESCE(fine_richiesta_il, NOW()), solleciti = 0, sollecito_il = NULL, aggiornata_il = NOW() WHERE id = ?",
            [$percorsoPdf, $tokenFine, $oreApprovate, $id]
        );
    }

    public function segnaFineFirmata(int $id, string $percorsoPdf): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_stato = 'firmata', fine_pdf = ?, fine_firmata_il = NOW(), solleciti = 0, sollecito_il = NULL, aggiornata_il = NOW() WHERE id = ?", [$percorsoPdf, $id]);
    }

    public function segnaFineProtocollata(int $id, string $protocollo): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_stato = 'protocollata', fine_protocollo = ?, aggiornata_il = NOW() WHERE id = ?", [$protocollo, $id]);
    }

    public function segnaPromemoriaTutor(int $id): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET promemoria_tutor_il = NOW() WHERE id = ?', [$id]);
    }

    public function segnaPromemoriaDocente(int $id): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET promemoria_docente_il = NOW() WHERE id = ?', [$id]);
    }

    public function segnaSollecito(int $id): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET sollecito_il = NOW(), solleciti = solleciti + 1 WHERE id = ?', [$id]);
    }

    /** Toglie i dati personali della lettera (conservazione): restano codice, nome e cognome, bando, ore, compenso, protocolli e storico. */
    public function anonimizza(int $id): void
    {
        $this->db->esegui(
            "UPDATE tutorato_incarichi SET luogo_nascita = '', data_nascita = NULL, comune_residenza = '', indirizzo = '', civico = '', codice_fiscale = '', email = '', telefono = '',
                              studente_firma_json = NULL, nota_studente = NULL, file_pdf = NULL, fine_pdf = NULL, token_studente = NULL, token_docente = NULL, token_direttore = NULL, token_fine = NULL, anonimizzata = 1 WHERE id = ?",
            [$id]
        );
    }
}
