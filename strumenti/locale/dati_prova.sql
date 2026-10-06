-- dati_prova.sql - Dati di esempio dell'ambiente locale (strumenti/locale/avvia.sh --nuovo).
-- Persone, scuole e indirizzi inventati (dominio example.org): nessun dato reale.
SET NAMES utf8mb4;

-- Utenti di prova (si entra da /__accesso)
INSERT INTO utenti (id, ruolo_id, ruoli_secondari, nome, cognome, email, codice_fiscale) VALUES
 (1, 1, '', 'Amministratore', 'Prova', 'admin@example.org', 'PRVMMN80A01D086X'),
 (2, 2, '', 'Gestore', 'OpenLab', 'gestore.openlab@example.org', 'PRVGST80A01D086X'),
 (3, 5, '', 'Maria', 'Docente', 'maria.docente@example.org', 'DCNMRA75B41D086X'),
 (4, 5, '', 'Luca', 'Insegnante', 'luca.insegnante@example.org', 'NSGLCU70C01D086X');

UPDATE configurazione_portale SET nome_portale = 'EventiDiBEST (locale)', sottotitolo_portale = 'Ambiente di prova' WHERE id = 1;
UPDATE impostazioni_sistema SET smtp_from_name = 'EventiDiBEST (locale)', smtp_from_email = 'noreply@example.org' WHERE id = 1;

-- Aree: OpenLab (eventi con turni) e Formazione Scuola Lavoro (progetti)
INSERT INTO pagine_eventi (id, titolo, sottotitolo, slug, colore_primario, layout_template, gestori_utenti_ids, mostra_in_home, chiedi_matricola, mostra_sidebar, hero_descrizione) VALUES
 (1, 'OPENLAB', 'Laboratorio scientifico interattivo', 'openlab', '#0E6F7A', 'advanced_list', '2', 1, 0, 0, 'Esperienze di laboratorio per le scuole.'),
 (2, 'FORMAZIONE SCUOLA LAVORO', 'Percorsi per le scuole', 'fsl', '#B30000', 'progetti', '', 1, 0, 0, 'Percorsi di Formazione Scuola Lavoro del Dipartimento.');

-- Campi del modulo: scuola dall'anagrafe e docente di riferimento
INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, obbligatorio, ordine) VALUES
 (1, NULL, 'scuola', 'Scuola', 'scuola', 1, 1),
 (1, NULL, 'docente_riferimento', 'Docente di riferimento', 'text', 1, 2),
 (2, NULL, 'scuola', 'Scuola', 'scuola', 1, 1);

-- Scuole (anagrafe): codici inventati
INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, statale) VALUES
 ('CSPS00001A', 'LICEO SCIENTIFICO GALILEI', 'CSIS00001A', 'IIS GALILEI', 'LICEO SCIENTIFICO', 'COSENZA', 'COSENZA', 'CALABRIA', 1),
 ('CSPS00002B', 'LICEO SCIENTIFICO FERMI', 'CSIS00002B', 'IIS FERMI', 'LICEO SCIENTIFICO', 'RENDE', 'COSENZA', 'CALABRIA', 1),
 ('CSTF00003C', 'ISTITUTO TECNICO VOLTA', 'CSIS00003C', 'IIS VOLTA', 'ISTITUTO TECNICO INDUSTRIALE', 'CASTROVILLARI', 'COSENZA', 'CALABRIA', 1),
 ('CZPC00004D', 'LICEO CLASSICO GALLUPPI', 'CZIS00004D', 'IIS GALLUPPI', 'LICEO CLASSICO', 'CATANZARO', 'CATANZARO', 'CALABRIA', 1);

-- OpenLab: evento FSL dedicato alle scuole con due turni (tra 20 e 90 giorni) e uno non FSL
INSERT INTO eventi (id, pagina_id, titolo, luogo, descrizione, tipo, richiede_prenotazione, abilita_presenze, ordine) VALUES
 (10, 1, 'Modulo di Genetica in presenza', 'OpenLab, Cubo 4C', '<p>Estrazione del DNA e osservazione al microscopio.</p>', 'evento', 1, 1, 1),
 (11, 1, 'UniStem Day', 'Aula Magna', '<p>Giornata sulle cellule staminali.</p>', 'evento', 1, 1, 2);
INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, convenzione, dedicata_scuole) VALUES (10, 1, 1, 1, 1);
INSERT INTO turni (id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, min_partecipanti, max_partecipanti, abilita_lista_attesa) VALUES
 (100, 10, 'Turno A', CURDATE() + INTERVAL 20 DAY, '09:00', '12:00', 1, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 15 DAY, 5, 18, 1),
 (101, 10, 'Turno B', CURDATE() + INTERVAL 90 DAY, '09:00', '12:00', 1, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 80 DAY, 5, 18, 1),
 (110, 11, 'Mattina', CURDATE() + INTERVAL 30 DAY, '09:30', '13:00', 100, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 25 DAY, NULL, NULL, 0);

-- FSL: progetto FSL (da +10 a +60 giorni) con due edizioni
INSERT INTO eventi (id, pagina_id, titolo, luogo, descrizione, tipo, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine) VALUES
 (20, 2, 'Geologia sul campo', 'Dipartimento DiBEST', '<p>Percorso di scienze della Terra.</p>', 'progetto', 1, 1, -1, 1);
INSERT INTO progetti_dettagli (evento_id, struttura, data_inizio, data_fine, destinatari, modalita, ore_totali, min_studenti, max_studenti, per_scuole, attestati, convenzione, referenti_json) VALUES
 (20, 'Corso di laurea in Scienze geologiche', CURDATE() + INTERVAL 10 DAY, CURDATE() + INTERVAL 60 DAY, 'Classi IV e V', 'In presenza', 30, 8, 25, 1, 1, 1,
  '[{"ruolo":"Referente","nome":"Referente Prova","email":"referente@example.org","notifiche":1}]');
INSERT INTO turni (id, evento_id, nome_turno, max_posti, data_apertura, data_chiusura, abilita_lista_attesa) VALUES
 (200, 20, 'Edizione 1', 1, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 8 DAY, 1),
 (201, 20, 'Edizione 2', 1, NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 8 DAY, 1);

-- Convenzioni nel registro: Galilei valida un anno (copre tutto), Fermi scade tra 30 giorni (copre il turno A, non il progetto)
INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo, docenti_json, registrata_da) VALUES
 ('CSPS00001A', CURDATE() - INTERVAL 30 DAY, CURDATE() + INTERVAL 334 DAY, 'prot. 100/2026', '[{"nome":"Maria Docente","email":"maria.docente@example.org"}]', 'dati di prova'),
 ('CSPS00002B', CURDATE() - INTERVAL 335 DAY, CURDATE() + INTERVAL 30 DAY, 'prot. 55/2025', '[{"nome":"Luca Insegnante","email":"luca.insegnante@example.org"}]', 'dati di prova');

-- Una prenotazione già confermata prima del processo delle convenzioni (Volta: nessuna convenzione)
INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, scuola_codice, dati_custom_json) VALUES
 (101, 4, 'OP-PROVA001', 'confermata', 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org', 'CSTF00003C',
  '{"scuola":"ISTITUTO TECNICO VOLTA – Castrovillari","docente_riferimento":"Luca Insegnante","numero_partecipanti":"15"}');
