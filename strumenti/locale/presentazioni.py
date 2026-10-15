#!/usr/bin/env python3
"""Presentazioni dell'iter (HTML 16:9 da stampare in PDF con strumenti/locale/genera_pdf.sh), nello stesso stile di documentazione/Pratiche_verbali_iter.html:
  - Certificati_attestati_iter.html
  - Risorse_prenotazioni_iter.html
e aggiornamento di Pratiche_verbali_iter.html (percorsi di tutte le pratiche, schermate di esempio).
Le schermate sono in documentazione/schermate/ (strumenti/locale/schermate_documentazione.py).

Uso:  python strumenti/locale/presentazioni.py
"""
import os, re, html

RADICE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DOC = os.path.join(RADICE, 'documentazione')
e = html.escape

CSS_BASE = None


def css_da_pratiche():
    """Il foglio di stile della presentazione esistente, più le classi per le schermate."""
    s = open(os.path.join(DOC, 'Pratiche_verbali_iter.html'), encoding='utf8').read()
    css = s[s.index('<style>') + 7:s.index('</style>')]
    css = css.split('/* schermate */')[0]
    return css + CSS_SCHERMATE


CSS_SCHERMATE = """/* schermate */
.shot { display: grid; grid-template-columns: 1.62fr 1fr; gap: 28px; align-items: start; margin-top: 4px; }
.shot .img { background: #fff; border: 1px solid var(--linea); border-radius: 12px; padding: 8px; box-shadow: 0 3px 14px rgba(15,23,42,.12); }
.shot .img img { width: 100%; display: block; border-radius: 6px; object-fit: cover; object-position: top; }
.shot .img.alto img { max-height: 520px; }
.shot .tag { display: inline-block; background: var(--rosso); color: #fff; font-weight: 700; padding: 6px 16px; border-radius: 999px; font-size: 15px; margin-bottom: 14px; }
.shot ul { margin: 0; padding-left: 22px; } .shot li { font-size: 19px; line-height: 1.42; margin-bottom: 10px; color: #1e293b; }
.shot .nota { margin-top: 14px; font-size: 16px; padding: 12px 16px; }
.titolo-schermata { font-size: 32px; margin: 0 0 14px; }
.m.fitto { font-size: 14px; } .m.fitto th { padding: 7px 12px; } .m.fitto td { padding: 6px 12px; line-height: 1.28; }
.copertina.verde { background: linear-gradient(135deg, #0f3d33 0%, #0f766e 100%); }
.copertina.rosso { background: linear-gradient(135deg, #5b1111 0%, #b91c1c 100%); }
"""


def pagina(titolo, corpo):
    return ('<!doctype html>\n<html lang="it">\n<head>\n<meta charset="utf-8">\n<title>%s</title>\n<style>\n/* Presentazione 16:9 da stampare in PDF. Generata da strumenti/locale/presentazioni.py; PDF con strumenti/locale/genera_pdf.sh */\n%s</style>\n</head>\n<body>\n%s\n</body>\n</html>\n'
            % (e(titolo), css_da_pratiche(), corpo))


class Deck:
    def __init__(self, nome, colore='blu'):
        self.nome = nome
        self.colore = colore
        self.slide = []

    def piede(self):
        return '<div class="piede"><span>%s</span></div>' % e(self.nome)

    def copertina(self, tag, titolo, testo, stile=''):
        self.slide.append('<section class="slide copertina %s">\n  <div class="interno"><span class="tag">%s</span><h1>%s</h1><p>%s</p></div>\n  <div class="piede"><span>Presentazione dell\'iter · ottobre 2026</span></div>\n</section>' % (stile, e(tag), e(titolo), e(testo)))

    def passi(self, num, titolo, sotto, cards, colonne=4):
        """cards: lista di (attore, titolo, testo, colore)."""
        c = ''.join('<div class="card %s"><div class="attore" style="color:var(--%s)">%s</div><h3>%s</h3><p>%s</p></div>' % (col, col, e(a), e(t), x) for a, t, x, col in cards)
        self.slide.append('<section class="slide">\n  <h2><span class="num">%s</span>%s</h2>\n  <p class="sotto">%s</p>\n  <div class="griglia g%d">%s</div>\n  %s\n</section>' % (e(num), e(titolo), e(sotto), colonne, c, self.piede()))

    def shot(self, num, titolo, tag, img, punti, nota=None, alto=False):
        li = ''.join('<li>%s</li>' % p for p in punti)
        nt = '<div class="nota">%s</div>' % nota if nota else ''
        self.slide.append('<section class="slide">\n  <h2><span class="num">%s</span>%s</h2>\n  <div class="shot"><div class="img%s"><img src="schermate/%s" alt=""></div>\n  <div><span class="tag">%s</span><ul>%s</ul>%s</div></div>\n  %s\n</section>'
                          % (e(num), e(titolo), ' alto' if alto else '', img, e(tag), li, nt, self.piede()))

    def tabella(self, num, titolo, sotto, testata, righe):
        th = ''.join('<th>%s</th>' % e(t) for t in testata)
        tr = ''.join('<tr>%s</tr>' % ''.join('<td>%s</td>' % c for c in r) for r in righe)
        self.slide.append('<section class="slide">\n  <h2><span class="num">%s</span>%s</h2>\n  <p class="sotto">%s</p>\n  <table class="m"><tr>%s</tr>%s</table>\n  %s\n</section>' % (e(num), e(titolo), e(sotto), th, tr, self.piede()))

    def libera(self, num, titolo, sotto, corpo):
        self.slide.append('<section class="slide">\n  <h2><span class="num">%s</span>%s</h2>\n  <p class="sotto">%s</p>\n  %s\n  %s\n</section>' % (e(num), e(titolo), e(sotto), corpo, self.piede()))

    def scrivi(self, file):
        open(os.path.join(DOC, file), 'w', encoding='utf8').write(pagina(self.nome, '\n\n'.join(self.slide)))
        print('scritto', file, len(self.slide), 'slide')


# ---------------------------------------------------------------------------------------------------------------
def certificati():
    d = Deck('Certificati e attestati · l\'iter')
    d.copertina('DiBEST · Portale eventi e didattica', 'Certificati e attestati', 'Dalla richiesta dello studente al documento firmato dal Direttore: marche da bollo, conferma dei docenti e protocollo in uscita, tutto dal portale.')
    d.passi('0', 'I documenti e il loro percorso', 'Una sola richiesta per il certificato di iscrizione, di laurea o di rinuncia; il Diploma Supplement e gli attestati hanno percorsi propri. Nessuno passa dal verbale.', [
        ('Certificato', 'Iscrizione, laurea, rinuncia', 'Una tendina sceglie il tipo e compare l’elenco di caselle (con esami, con date, con anni accademici, con piano…). Protocollo → ufficio del corso → pagamento delle 2 marche da bollo → Direttore.', 'blu'),
        ('Diploma Supplement', 'Senza marca da bollo', 'Protocollo → l’ufficio del corso carica il documento da firmare → Direttore firma → lo studente lo scarica.', 'viola'),
        ('Attestato di frequenza', 'Lezioni seguite', 'Lo studente elenca le lezioni; ogni docente conferma le sue righe nel portale; l’Ufficio attestati controlla il PDF/A; il Direttore firma; protocollo in uscita.', 'verde'),
        ('Attestato di esame', 'Esame sostenuto', 'Come la frequenza, con i dati dell’esame (insegnamento, docente titolare, data, ore, aula) confermati dal docente.', 'teal'),
    ])
    d.shot('1', 'Lo studente sceglie e compila', 'Studente · Richiesta Certificato', 'stud_modulo_certificato.png', [
        'Una <b>sola pratica</b> per iscrizione, laurea e rinuncia: scegli il tipo e vedi solo i campi di quel tipo',
        'Le <b>caselle</b> dei certificati cambiano con il tipo (iscrizione con esami, laurea con tesi e relatore…)',
        'Corso di laurea scelto dall’elenco di Ateneo: la pratica va <b>all’ufficio di quel corso</b>',
        '«Salva come bozza»: la richiesta si riprende quando si vuole e si modifica fino alla presa in carico'], alto=True)
    d.shot('2', 'Le marche da bollo', 'Studente · A che punto è', 'stud_pratica_laurea.png', [
        'Dopo il protocollo la pratica passa all’<b>ufficio del corso</b>',
        'L’ufficio carica le <b>2 marche da bollo su Esse3</b> e avvisa lo studente per email',
        'Lo studente paga da Esse3 (Segreteria, Situazione tasse): nel portale non serve fare nulla',
        'Le tappe in alto dicono sempre dove si trova la pratica'], alto=True)
    d.shot('3', 'L’ufficio registra il pagamento', 'Ufficio del corso · Marche da bollo', 'ref_marche.png', [
        '<b>1.</b> Carica le marche su Esse3 e premi «avvisa lo studente»',
        '<b>2.</b> Quando il pagamento risulta, spunta «marca da bollo assolta virtualmente»',
        '<b>3.</b> Carica il certificato generato da Esse3 (PDF): la pratica passa al Direttore',
        'Lo studente vede che la marca è assolta; il certificato da firmare resta interno'], alto=True)
    d.shot('4', 'Il Direttore firma', 'Direttore · Pagina di firma', 'dir_firma.png', [
        'Riceve l’email con il <b>link personale</b>; accede con Unical ID, SPID o CIE',
        'Firma in <b>PAdES</b> con la firma remota, oppure scarica, firma e ricarica',
        'Il portale controlla che il file sia firmato e che il firmatario sia lui',
        'Dopo la firma lo <b>studente scarica e stampa</b> il certificato e l’operatore è avvisato'], alto=True)
    d.shot('5', 'Il cruscotto del Direttore', 'Direttore · Cose da fare', 'dir_cruscotto.png', [
        'In cima ci sono i <b>documenti da firmare</b>, con i giorni di attesa',
        'Sotto, tutto ciò che si è fermato: pratiche, conferme dei docenti, verbali, convenzioni, tutorato, risorse',
        'Premendo su una riga si va diretti alla firma o alla pratica',
        'Non cambia nulla: serve a vedere dove intervenire'], alto=True)
    d.shot('6', 'Attestati: i docenti confermano', 'Docente · Conferma nel portale', 'rel_conferma_attestato.png', [
        'Lo studente elenca le lezioni (o i dati dell’esame) e indica i docenti dall’anagrafe',
        'Ogni docente riceve un link e vede <b>solo le righe che lo riguardano</b>',
        '«Confermo» o «Non confermo» con il motivo: se un docente non conferma la richiesta torna allo studente e poi le conferme ripartono',
        'Resta registrato chi ha confermato, quando e con quale accesso (senza firma digitale)'])
    d.passi('7', 'Dopo le conferme: l’Ufficio attestati', 'Il portale prepara l’attestato in PDF/A: l’ufficio lo controlla, il Direttore lo firma e si registra il protocollo in uscita.', [
        ('Ufficio attestati', 'Controlla il PDF/A', 'Vede l’anteprima dell’attestato preparato dal portale e lo manda al Direttore.', 'ambra'),
        ('Direttore', 'Firma in PAdES', 'Il documento firmato resta interno finché non c’è il protocollo.', 'rosso'),
        ('Ufficio attestati', 'Protocollo in uscita', 'Registra numero, data e PDF protocollato (invio all’azienda per PEC fuori dal portale).', 'viola'),
        ('Studente', 'Scarica il PDF', 'Riceve l’email e scarica l’attestato firmato e protocollato.', 'verde'),
    ])
    d.tabella('8', 'Chi riceve cosa, e quando', 'Ogni passaggio avvisa per email chi deve agire dopo.', ['Passaggio', 'Chi riceve l’email', 'Oggetto'], [
        ['Lo studente invia', 'Ufficio protocollo; lo studente (ricevuta)', 'Nuova pratica da protocollare'],
        ['Protocollo registrato', 'Ufficio del corso; lo studente', 'Pratica presa in carico'],
        ['Marche caricate su Esse3', 'Studente', 'Puoi pagare le marche da bollo'],
        ['Pagamento registrato', 'Direttore', 'Documento da firmare'],
        ['Il Direttore firma', 'Studente; operatore', 'Il tuo documento è pronto · Iter concluso'],
        ['Conferme dei docenti', 'Docenti indicati; promemoria se ritardano', 'Da confermare'],
    ])
    d.scrivi('Certificati_attestati_iter.html')


def risorse():
    d = Deck('Aule, laboratori e mezzi · l\'iter delle prenotazioni')
    d.copertina('DiBEST · Portale eventi e didattica', 'Aule, laboratori e mezzi', 'Prenotazioni, approvazioni, ripetizioni, liste d’attesa, diario di bordo e scadenze: chi fa cosa e che cosa vede ognuno.', 'verde')
    d.passi('0', 'L’iter di una prenotazione', 'Dallo slot scelto dall’utente al cruscotto del responsabile.', [
        ('1 · Utente', 'Sceglie lo slot', 'Dal calendario della risorsa: slot liberi in verde, durata, motivo, eventuali campi e quantità.', 'teal'),
        ('2 · Responsabile', 'Approva (se serve)', 'Le risorse con approvazione tengono lo slot «in sospeso»; il responsabile approva o rifiuta dal portale.', 'viola'),
        ('3 · Portale', 'Conferma e promemoria', 'Email di conferma con il file .ics e promemoria il giorno prima.', 'blu'),
        ('4 · Utente', 'Usa la risorsa', 'Per auto e mezzi compila il diario di bordo (km, rifornimenti, segnalazioni).', 'ambra'),
        ('5 · Responsabile', 'Controlla', 'Cruscotto con numeri del periodo, fasce più richieste, chi la usa di più, scadenze e Excel.', 'verde'),
        ('6 · Direttore', 'Vede cosa è fermo', 'Nel cruscotto: richieste da approvare da troppo tempo e scadenze vicine.', 'notte'),
        ('7 · Lista d’attesa', 'Se lo slot è occupato', 'Chi si mette in coda viene avvisato se la prenotazione è annullata o rifiutata.', 'rosso'),
        ('8 · Ripetizioni', 'Attività periodiche', 'Ogni settimana, ogni due settimane o ogni mese, lo stesso giorno della settimana.', 'ardesia'),
    ])
    d.shot('1', 'Il calendario della risorsa', 'Utente · Prenotazione', 'stud_calendario.png', [
        'Gli slot <b>liberi</b> sono in verde: si sceglie uno slot (o più di seguito, fino al massimo consentito)',
        'Gli slot occupati mostrano <b>«lista d’attesa»</b> e quante persone sono già in coda',
        'In fondo, «Le tue liste d’attesa»: posizione in coda e pulsante per uscire',
        'Chi non è abilitato (per esempio solo docenti) vede il motivo'], alto=True)
    d.shot('2', 'Ripetere una prenotazione', 'Utente · Prenotazione periodica', 'stud_prenota.png', [
        'Con <b>«Ripeti»</b> si sceglie la frequenza: ogni settimana, ogni due settimane o ogni mese (lo stesso giorno della settimana, per esempio il secondo martedì)',
        'Si indica fino a quando: le date non disponibili vengono <b>saltate</b> e indicate nell’email di riepilogo',
        'La prima data deve essere libera; le serie si annullano una per una',
        'Le risorse con approvazione chiedono il via libera per l’intera serie'], alto=True)
    d.libera('3', 'La lista d’attesa', 'Nessuno deve ricontrollare il calendario: se si libera, il portale avvisa.', '''
      <div class="percorso" style="margin-bottom:22px">
        <div class="passo" style="border-top-color:var(--ambra)"><em style="background:var(--ambra)">1 · In coda</em><b>Slot occupato</b><span>L’utente preme «lista d’attesa» sullo slot: massimo 5 code aperte per risorsa.</span></div>
        <div class="passo" style="border-top-color:var(--rosso)"><em style="background:var(--rosso)">2 · Si libera</em><b>Annullata o rifiutata</b><span>Chi aveva prenotato annulla, o il responsabile rifiuta: il primo in coda riceve l’email.</span></div>
        <div class="passo" style="border-top-color:var(--blu)"><em style="background:var(--blu)">3 · 24 ore</em><b>Tempo per prenotare</b><span>Lo slot non è riservato: chi arriva prima lo prende. Il primo ha 24 ore di vantaggio nell’avviso.</span></div>
        <div class="passo" style="border-top-color:var(--verde)"><em style="background:var(--verde)">4 · Chiusa</em><b>Prenotato o scaduto</b><span>Se prenota la coda si chiude; se non fa in tempo l’avviso passa al successivo (cron del portale).</span></div>
      </div>
      <div class="evidenza"><b>Per chi gestisce:</b> non c’è nulla da configurare. L’avviso parte da solo e la lista è visibile all’utente nella pagina della risorsa.</div>''')
    d.shot('4', 'Il responsabile approva', 'Responsabile · Le mie risorse', 'resp_risorse.png', [
        'Dalla propria Area personale, <b>«Le mie risorse»</b>: richieste da approvare e prenotazioni in arrivo',
        'Approva o rifiuta: chi ha prenotato riceve l’email; il rifiuto libera lo slot per la lista d’attesa',
        'Il responsabile è scelto dall’anagrafe del personale: nessun account da creare'], alto=True)
    d.shot('5', 'Il cruscotto della risorsa', 'Responsabile · Numeri e scadenze', 'resp_cruscotto.png', [
        'Prenotazioni, <b>ore prenotate</b> e utilizzo nel periodo scelto',
        'Le <b>fasce più richieste</b>: dicono se serve più disponibilità',
        '«Chi la usa di più» e Excel con tutti i numeri',
        '<b>Scadenze</b> (bollo, assicurazione, taratura): avviso prima della data e il giorno stesso; «Rinnovata» azzera l’avviso'], alto=True)
    d.shot('6', 'Il diario di bordo', 'Chi ha prenotato · Auto e mezzi', 'resp_diario.png', [
        'Dopo l’uso, chi ha prenotato (o un altro conducente indicato) compila il diario: km, rifornimenti, segnalazioni',
        'I <b>km iniziali</b> si propongono dall’ultimo viaggio e non possono essere più bassi',
        'Il responsabile verifica e corregge; ogni correzione azzera la verifica',
        'Promemoria automatico; si può bloccare una nuova prenotazione finché il diario manca'], alto=True)
    d.shot('7', 'Le prenotazioni dell’area', 'Gestore · Pannello', 'adm_prenotazioni.png', [
        'Elenco con stati (confermata, da approvare, rifiutata, annullata) e filtri',
        'Orari, durata dello slot, approvazione e ripetizione si impostano per ogni risorsa',
        'Le richieste ferme da oltre una settimana compaiono nel cruscotto del Direttore'], alto=True)
    d.tabella('8', 'Chi riceve cosa, e quando', 'Le email partono da sole a ogni passaggio.', ['Passaggio', 'Chi riceve l’email', 'Oggetto'], [
        ['Prenotazione registrata', 'Utente; responsabile (se serve l’approvazione)', 'Prenotazione confermata · Da approvare'],
        ['Approvata o rifiutata', 'Utente', 'Prenotazione approvata · non approvata'],
        ['Annullata', 'Responsabile (se annulla l’utente); utente (se annulla il gestore)', 'Annullata'],
        ['Slot liberato', 'Primo in lista d’attesa (poi il successivo dopo 24 ore)', 'Si è liberato uno slot'],
        ['Il giorno prima', 'Utente', 'Promemoria: prenotazione di domani'],
        ['Diario da compilare', 'Utente e altri conducenti', 'Diario di bordo da compilare'],
        ['Scadenza vicina o superata', 'Responsabile', 'In scadenza · Scaduta'],
    ])
    d.scrivi('Risorse_prenotazioni_iter.html')


def aggiorna_pratiche():
    """Aggiorna Pratiche_verbali_iter.html: percorsi di tutte le pratiche, schermate di esempio, correzioni."""
    p = os.path.join(DOC, 'Pratiche_verbali_iter.html')
    s = open(p, encoding='utf8').read()
    if 'schermate/stud_modulo_tesi.png' in s:
        s = s[:s.index('/* schermate */')] + s[s.index('</style>'):] if '/* schermate */' in s else s
    css_extra = CSS_SCHERMATE
    if '/* schermate */' not in s:
        s = s.replace('</style>', css_extra + '</style>', 1)
    # 2b: i percorsi cambiano
    a = s.index('<!-- 4b · Firme e percorsi -->')
    b = s.index('<!-- 5 ', a) if '<!-- 5 ' in s[a:] else None
    nuovo_2b = '''<!-- 4b · Firme e percorsi -->
<section class="slide">
  <h2><span class="num">2b</span>I percorsi: ogni pratica ha il suo</h2>
  <p class="sotto">Il percorso lo decide l'Ufficio didattico con la procedura guidata: non serve toccare il codice. Qui i gruppi di pratiche presenti nel portale.</p>
  <table class="m fitto">
    <tr><th>Gruppo</th><th>Pratiche</th><th>Percorso</th><th>Verbale</th></tr>
    <tr><td>Certificati</td><td>Certificato (iscrizione, laurea, rinuncia) · Diploma Supplement</td><td>Protocollo → ufficio del corso (marche da bollo su Esse3) → Direttore firma</td><td>No</td></tr>
    <tr><td>Attestati</td><td>Frequenza alle lezioni · Esame sostenuto</td><td>Conferma dei docenti → Ufficio attestati → Direttore firma → protocollo in uscita</td><td>No</td></tr>
    <tr><td>Immatricolazioni</td><td>Trasferimento in entrata · Iscrizione per rinuncia o decadenza · Abbreviazione di corso</td><td>Marca da bollo su UniPay (ricevuta allegata) → protocollo → referente del corso → segreteria</td><td>Sì</td></tr>
    <tr><td>Carriera</td><td>Convalide (esami, inglese) · Esonero inglese · Piano di studi · Curriculum · Tempo pieno / part-time · Fuori piano · Anticipo · Titolo estero</td><td>Protocollo (con marca da bollo per la convalida di inglese) → referente del corso → segreteria</td><td>Sì</td></tr>
    <tr><td>Tesi</td><td>Richiesta tesi (anche Conservazione e Restauro) · Variazione</td><td><b>Conferma del relatore</b> → protocollo → referente del corso</td><td>Sì</td></tr>
    <tr><td>Cancellazione ed esoneri</td><td>Cancellazione errata iscrizione · Esonero dalla frequenza</td><td>Protocollo → referente del corso</td><td>Sì</td></tr>
    <tr><td>Mobilità</td><td>Svolgimento · Variazione · Fine attività di studio all'estero</td><td>Protocollo → <b>Internazionalizzazione</b> → referente del corso → segreteria</td><td>Sì</td></tr>
  </table>
  <div class="evidenza" style="margin-top:14px"><b>Tempi per ogni passo:</b> dopo i giorni indicati chi deve agire riceve un promemoria. <b>Cruscotto del Direttore:</b> le pratiche ferme oltre il tempo del loro modulo compaiono nella pagina del Direttore.</div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

<!-- schermate · tesi -->
<section class="slide">
  <h2><span class="num">2c</span>Esempio: la richiesta di tesi</h2>
  <div class="shot"><div class="img alto"><img src="schermate/stud_modulo_tesi.png" alt=""></div>
  <div><span class="tag">Studente · Un solo modulo</span><ul>
    <li>Sceglie il <b>tipo</b>: richiesta di tesi, Conservazione e Restauro o variazione</li>
    <li>Compaiono i campi di quel tipo, divisi in pagine; «Salva come bozza» riprende dopo</li>
    <li>Indica il <b>relatore</b> dall'elenco del personale</li></ul>
    <div class="nota">Non serve più generare e mandare un link: il portale avvisa il relatore.</div></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

<section class="slide">
  <h2><span class="num">2d</span>Il relatore conferma dal portale</h2>
  <div class="shot"><div class="img alto"><img src="schermate/rel_conferma.png" alt=""></div>
  <div><span class="tag">Docente · Link personale</span><ul>
    <li>Email con il link: accede con le credenziali di Ateneo</li>
    <li>Vede i dati della richiesta e preme <b>«Confermo»</b> o «Non confermo» con il motivo</li>
    <li>Con la conferma la richiesta va al protocollo e al referente del corso</li>
    <li>Se non conferma, la richiesta torna allo studente e la conferma riparte dopo la correzione</li></ul></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

<section class="slide">
  <h2><span class="num">2e</span>L'ufficio del protocollo</h2>
  <div class="shot"><div class="img alto"><img src="schermate/proto_scheda.png" alt=""></div>
  <div><span class="tag">Ufficio protocollo</span><ul>
    <li>Scarica la <b>domanda in PDF/A</b> e la protocolla nel sistema di Ateneo</li>
    <li>Scrive numero e data; se serve spunta la verifica della <b>marca da bollo</b></li>
    <li>La pratica passa da sola all'ufficio successivo e lo studente è avvisato</li></ul></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

<section class="slide">
  <h2><span class="num">2f</span>L'ufficio del corso lavora la pratica</h2>
  <div class="shot"><div class="img alto"><img src="schermate/ref_scheda.png" alt=""></div>
  <div><span class="tag">Ufficio del corso · Referente</span><ul>
    <li>In alto l'<b>iter</b> con i passi e chi ha in carico la pratica</li>
    <li>Il riquadro verde dice <b>cosa fare ora</b></li>
    <li>Messaggi allo studente e note interne; richieste di integrazione</li>
    <li>«Passa al passo successivo»: segreteria, mobilità, Direttore…</li></ul></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

<section class="slide">
  <h2><span class="num">2g</span>Mobilità internazionale</h2>
  <div class="shot"><div class="img alto"><img src="schermate/mob_scheda.png" alt=""></div>
  <div><span class="tag">Internazionalizzazione · Referente</span><ul>
    <li>Dopo il protocollo la pratica passa dal <b>referente della mobilità</b></li>
    <li>Poi dal referente del corso, quindi va nel verbale e alla segreteria</li>
    <li>Svolgimento (con il Learning Agreement), variazione e fine attività (con il TOR)</li></ul></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

'''
    # tutto ciò che sta tra il commento 4b e la slide «La lavorazione dell'ufficio» si rifà ogni volta (così lo script si può rilanciare)
    h3 = s.index("La lavorazione dell'ufficio", a)
    z = s.rfind('<!--', a, h3)
    s = s[:a] + nuovo_2b + s[z:]
    # seduta guidata: schermata di esempio dopo la slide delle sette tappe
    if 'schermate/ref_seduta.png' not in s:
        h5 = s.index('La seduta guidata in sette passi')
        i = s.index('</section>', h5) + len('</section>') + 2
        shot_seduta = '''<section class="slide">
  <h2><span class="num">5b</span>Esempio: la seduta guidata</h2>
  <div class="shot"><div class="img alto"><img src="schermate/ref_seduta.png" alt=""></div>
  <div><span class="tag">Referente del consiglio</span><ul>
    <li>Le sette tappe in alto: si vede cosa è fatto e cosa manca</li>
    <li>Il portale propone il passo successivo; si può sempre tornare indietro</li>
    <li>Qui il verbale è in firma: segretario, poi coordinatore; il sollecito parte da solo</li></ul></div></div>
  <div class="piede"><span>Pratiche studenti e verbali · l'iter completo</span></div>
</section>

'''
        s = s[:i] + shot_seduta + s[i:]
    # ultima slide: la configurazione guidata c'è già
    s = s.replace('Prossimo passo di sviluppo: la configurazione guidata delle pratiche.', 'La configurazione guidata delle pratiche (sette passi, con bozza, copia e modelli) è già disponibile.')
    s = s.replace("resta da attivare la pagina pubblica (dopo il parere del DPO) e da semplificare la configurazione delle pratiche.", "resta da attivare la pagina pubblica dei verbali (dopo il parere del DPO).")
    open(p, 'w', encoding='utf8').write(s)
    print('aggiornato Pratiche_verbali_iter.html')


if __name__ == '__main__':
    certificati()
    risorse()
    aggiorna_pratiche()
