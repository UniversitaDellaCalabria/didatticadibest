<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;
use App\Eventi\Righe;

/** Lettere di incarico (tabella tutorato_incarichi) e il loro storico (tutorato_eventi). */
final class IncaricoRepository
{
    /** Colonne del bando che accompagnano ogni lettera (decreti, direttore, operatore, ufficio che paga). */
    private const DATI_BANDO = 'b.titolo AS bando_titolo, b.anno_accademico, b.decreto_bando, b.decreto_bando_data, b.decreto_commissione, b.decreto_commissione_data,
                    b.decreto_atti, b.decreto_atti_data, b.decreto_bando_file, b.decreto_commissione_file, b.decreto_atti_file, b.ufficio_ateneo_id,
                    b.direttore_persona_id, b.direttore_nome, b.direttore_email, b.direttore_cf, b.operatore_id, b.luogo';

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
            'SELECT i.*, ' . self::DATI_BANDO . '
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
     * Id delle lettere da anonimizzare: annullate o protocollate (con la fine attività firmata, se c'è, e il pagamento fatto o mai iniziato) ferme da più di $mesi mesi.
     *
     * @return list<int>
     */
    public function idDaConservare(int $mesi): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            "SELECT id FROM tutorato_incarichi WHERE anonimizzata = 0 AND COALESCE(aggiornata_il, creata_il) < NOW() - INTERVAL ? MONTH
             AND (stato = 'annullata' OR (stato = 'protocollata' AND fine_stato IN ('', 'firmata', 'protocollata') AND pagamento_stato IN ('', 'pagata'))) LIMIT 500",
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
        $id = $this->db->inserisci(
            'INSERT INTO tutorato_incarichi (bando_id, codice, genere, cognome, nome, luogo_nascita, data_nascita, comune_residenza, indirizzo, civico, codice_fiscale, email, telefono, attivita, ore, periodo, compenso,
                                  docente_persona_id, docente_nome, docente_cognome, docente_email, docente_cf, data_lettera, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            $v
        );
        if ($id) {
            $this->db->esegui('UPDATE tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id SET i.iter = b.iter WHERE i.id = ?', [$id]);
        }

        return $id;
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

    /** Dati che arrivano dall'import della graduatoria: insegnamento dell'anagrafe (id, codice, nome), corso di laurea, ambito e fine del periodo. */
    public function impostaGraduatoria(int $id, ?int $insegnamentoId, string $codice, string $insegnamento, string $corso, string $ambito, ?string $dataFine): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET insegnamento_id = ?, insegnamento_codice = ?, insegnamento_docente = ?, corso_laurea = ?, ambito = ?, data_fine = ? WHERE id = ?',
            [$insegnamentoId, mb_substr($codice, 0, 30), mb_substr($insegnamento, 0, 255), mb_substr($corso, 0, 255), mb_substr($ambito, 0, 150), $dataFine, $id]);
    }

    /** Esiste già una lettera (non annullata) del bando con lo stesso nome e insegnamento (per non importare due volte). */
    public function esisteNelBando(int $bandoId, string $cognome, string $nome, string $insegnamento): bool
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_incarichi WHERE bando_id = ? AND stato <> 'annullata' AND LOWER(cognome) = ? AND LOWER(nome) = ? AND LOWER(insegnamento_docente) = ?",
            [$bandoId, mb_strtolower($cognome), mb_strtolower($nome), mb_strtolower($insegnamento)]) > 0;
    }
    /** UNILAV del tutor: file e ore a cui si riferisce. */
    public function impostaUnilav(int $id, string $percorso, float $ore): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET unilav_file = ?, unilav_il = NOW(), unilav_ore = ?, aggiornata_il = NOW() WHERE id = ?', [$percorso, $ore, $id]);
    }

    /** Il referente ha validato la fine attività e passa la pratica all'ufficio pagamenti, con il compenso finale. */
    public function passaAiPagamenti(int $id, string $da, float $compensoFinale): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_validata_il = NOW(), fine_validata_da = ?, compenso_finale = ?, pagamento_stato = 'in_ufficio', pagamento_passato_il = NOW(),
            pagamento_passato_da = ?, pagamento_nota = '', aggiornata_il = NOW() WHERE id = ?", [mb_substr($da, 0, 255), $compensoFinale, mb_substr($da, 0, 255), $id]);
    }

    /** L'ufficio pagamenti rimanda la pratica al referente con una nota. */
    public function rimandaAlReferente(int $id, string $nota): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET pagamento_stato = 'rimandata', pagamento_nota = ?, fine_validata_il = NULL, aggiornata_il = NOW() WHERE id = ?", [mb_substr($nota, 0, 500), $id]);
    }

    /** Pagamento disposto. */
    public function segnaPagata(int $id, string $data, string $riferimento, string $da): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET pagamento_stato = 'pagata', pagamento_data = ?, pagamento_riferimento = ?, pagato_il = NOW(), pagato_da = ?, aggiornata_il = NOW() WHERE id = ?",
            [$data, mb_substr($riferimento, 0, 100), mb_substr($da, 0, 255), $id]);
    }

    /** Il referente rimanda la fine attività al docente: deve rivederla e firmarla di nuovo. */
    public function riapriFine(int $id): void
    {
        $this->db->esegui("UPDATE tutorato_incarichi SET fine_stato = 'richiesta', fine_firmata_il = NULL, token_fine = NULL, aggiornata_il = NOW() WHERE id = ?", [$id]);
    }

    /**
     * Lettere protocollate con la fine attività firmata dal docente, non ancora all'ufficio pagamenti (o rimandate dall'ufficio): da validare.
     *
     * @return list<array<string, string|null>>
     */
    public function daValidare(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT i.*, " . self::DATI_BANDO . " FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id
             WHERE i.stato = 'protocollata' AND i.iter = 'portale' AND i.fine_stato IN ('firmata', 'protocollata') AND i.pagamento_stato IN ('', 'rimandata') ORDER BY i.pagamento_stato = 'rimandata' DESC, i.fine_firmata_il, i.cognome"
        ));
    }

    /**
     * Pratiche passate a un ufficio di Ateneo (di cui si pagano i tutor dei suoi bandi), per stato del pagamento.
     *
     * @param list<string> $stati in_ufficio | pagata
     * @return list<array<string, string|null>>
     */
    public function perUfficio(int $ufficioId, array $stati): array
    {
        $stati = array_values(array_intersect($stati, ['in_ufficio', 'pagata']));
        if (!$stati) {
            return [];
        }

        return Righe::testo($this->db->righe(
            "SELECT i.*, " . self::DATI_BANDO . " FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id
             WHERE b.ufficio_ateneo_id = ? AND i.pagamento_stato IN ('" . implode("','", $stati) . "') ORDER BY i.pagamento_stato = 'pagata', i.pagamento_passato_il, i.cognome",
            [$ufficioId]
        ));
    }

    /**
     * Le lettere di un tutor (dal codice fiscale o dall'email), non annullate, dalla più recente.
     *
     * @return list<array<string, string|null>>
     */
    public function delTutor(string $codiceFiscale, string $email): array
    {
        return Righe::testo($this->db->righe(
            "SELECT i.*, " . self::DATI_BANDO . " FROM tutorato_incarichi i JOIN tutorato_bandi b ON b.id = i.bando_id
             WHERE i.stato <> 'annullata' AND i.stato <> 'bozza' AND ((? <> '' AND i.codice_fiscale = ?) OR (? <> '' AND LOWER(i.email) = ?)) ORDER BY i.creata_il DESC",
            [strtoupper($codiceFiscale), strtoupper($codiceFiscale), strtolower($email), strtolower($email)]
        ));
    }

    /** Cancella la dichiarazione individuale (dati bancari cifrati) e il registro di chi l'ha aperta: conservazione dei dati personali. */
    public function eliminaDichiarazione(int $id): void
    {
        $this->db->esegui('DELETE FROM tutorato_dichiarazioni WHERE incarico_id = ?', [$id]);
        $this->db->esegui('DELETE FROM tutorato_accessi_dati WHERE incarico_id = ?', [$id]);
    }

    /** Iter della lettera (portale | uniticket). */
    public function impostaIter(int $id, string $iter): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET iter = ? WHERE id = ?', [Costanti::iterValido($iter), $id]);
    }

    /** L'ufficio ha verificato su Uniticket che il vincitore ha preso servizio. */
    public function segnaPresaServizio(int $id, string $da): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET presa_servizio_il = NOW(), presa_servizio_da = ?, aggiornata_il = NOW() WHERE id = ?', [mb_substr($da, 0, 150), $id]);
    }

    /** PDF della dichiarazione individuale (SCAFIS) firmata con i dati SPID/CIE. */
    public function impostaScafis(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE tutorato_incarichi SET scafis_pdf = ? WHERE id = ?', [$percorso, $id]);
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
