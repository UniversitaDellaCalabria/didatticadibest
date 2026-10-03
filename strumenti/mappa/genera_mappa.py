# Genera la mappa del portale: strumenti/mappa/mappa_portale.pdf (A4 orizzontale, 2 pagine) e mappa_portale.html
# dallo stesso contenuto (DATI qui sotto). Uso: cd strumenti/mappa && python3 genera_mappa.py   (serve reportlab)
# Pagina 1: il sito pubblico (menu per pubblico) e il pannello di gestione (moduli). Pagina 2: i flussi principali,
# gli automatismi del cron, firme e sicurezza, conservazione e strumenti. «*» in fondo a una voce = novità (in giallo).
import os, re, html as H
from reportlab.lib.pagesizes import A4, landscape
from reportlab.platypus import BaseDocTemplate, PageTemplate, Frame, Paragraph, Table, TableStyle, Spacer, PageBreak
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib import colors
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

AGGIORNATA = '3 ottobre 2026'
SCHEMA = 'v41'

SITO = [  # (titolo, colore, indirizzo, [voci])
    ('Home', '#B30000', 'index.php', [
        'Carosello e bacheca annunci', '«Cosa cerchi?»: 4 percorsi per pubblico*', 'Agenda dei prossimi appuntamenti con gli ambiti*',
        'Scadenze della modulistica*', 'Card delle aree per macroarea', 'La mia prossima prenotazione (con QR)']),
    ('Orientamento', '#0056B3', 'orientamento.php', [
        'Vetrina per futuri studenti e scuole*', 'Aree di orientamento: Openlab, Welcome Week', 'Per le scuole: Formazione Scuola Lavoro (il docente prenota per la classe)',
        'Convenzione online e valutazione: <code>convenzione_online.php</code>, <code>valutazione_fsl.php</code>', 'Attestati con codice di verifica']),
    ('Studenti', '#047857', 'modulistica.php', [
        'Modulistica e moduli online: <code>modulo.php</code>', 'Le mie pratiche: integrazioni, autodichiarazioni, estratto del verbale in PDF',
        'Ricevimento dei docenti e sportelli a slot', 'Tutorato: lettera con SPID/CIE <code>incarico.php</code>, registro <code>registro_tutorato.php</code>']),
    ('Eventi e seminari', '#7c3aed', 'agenda.php', [
        'Agenda unica per mese con filtri per ambito: Orientamento, Ricerca, Public engagement, Didattica*', '«Per le scuole» e ricerca*',
        'Calendario <code>.ics</code> per Google, Outlook, telefono*', 'Seminari: relatore, abstract, diretta, registrazione, slide*',
        'Avvisi per email dei nuovi eventi <code>avvisi.php</code>*', 'Archivio di ogni area']),
    ('Area riservata e link personali', '#334155', 'area_personale.php', [
        'Prenotazioni, QR, attestati, pratiche, registro del tutor', 'Firme PAdES: <code>firma_incarico.php</code>, <code>firma_verbale.php</code>',
        'Assenza giustificata dalle sedute: <code>giustifica.php</code>', 'Verifica degli attestati: <code>verifica_attestato.php</code>', 'Accesso: SSO Unical, SPID, CIE']),
]

PANNELLO = [
    ('Eventi e seminari', '#0056B3', 'ex modulo Orientamento*', [
        'Aree degli eventi: eventi e turni, iscritti, check-in e scanner, badge', 'Moduli di iscrizione, sondaggi, attestati, statistiche',
        'Ambiti per evento e predefinito dell\'area*', 'Riquadro «Seminario» nell\'evento*', '<b>Report per ambito</b> (Terza missione) ed Excel <code>report_ambiti.php</code>*']),
    ('Formazione Scuola Lavoro', '#B30000', 'modulo a sé, accessi di prima conservati*', [
        'Progetti con edizioni <code>progetti.php</code>', 'Convenzioni: registro, validità, docenti', 'Convenzione e Allegato A solo PAdES (no .p7m)',
        'Verifica delle iscrizioni, riepilogo per anno scolastico, valutazioni', 'Anagrafe delle scuole']),
    ('Prenotazioni e risorse', '#7c3aed', '', [
        'Aule, laboratori e sportelli a slot <code>risorse.php</code>', 'Orari settimanali e chiusure', 'Agenda, approvazioni, CSV <code>prenotazioni_risorse.php</code>',
        'Gruppi delle attività degli insegnamenti', 'Aule collegate ai turni degli eventi']),
    ('Didattica', '#047857', 'admin/didattica.php: un file per scheda', [
        '<b>Pratiche</b>: iter per uffici, istruttoria, protocollo, Excel e Word', '<b>Sedute e verbali</b>: 5 consigli, referenti, componenti, convocazione, presenze, convalide e piani, estratti, verbale PAdES',
        '<b>Moduli e documenti</b>: costruttore con tabelle tipizzate, logica condizionale, catalogo di Ateneo', '<b>Ufficio e ricevimento</b> · <b>Statistiche</b>',
        '<b>Tutorato</b>: bandi, lettere di incarico, registro, fine attività']),
    ('Gestione del portale', '#334155', '', [
        'Anagrafi di Ateneo: docenti, PTA, insegnamenti, corsi, strutture; catalogo dei corsi', 'Testata e home: widget, <b>organizzazione proposta</b> con ripristino*',
        'Menu del sito (3 livelli)', 'Utenti e abilitazioni: per area, per modulo, per la FSL', 'Sistema, registri, backup, controllo notturno']),
]

FLUSSI = [  # (titolo, colore, [passi], nota)
    ('Pratica dello studente', '#047857', ['Modulo online', 'Smistamento', 'Uffici del modulo (iter)', 'Integrazione: documenti o autodichiarazione', 'Seduta del consiglio', 'Esito ed estratto del verbale in PDF', 'Email allo studente'],
     'Chi ha avuto la pratica la vede e la integra; email solo ai passaggi.'),
    ('Seduta del consiglio', '#0056B3', ['Convocazione per email (facoltativa)', 'Giustificazioni dal link', 'Presenze', 'Decisioni: convalide, piano, delibera', 'Applica gli esiti', 'Verbale Word → PDF', 'Firma PAdES segretario → coordinatore', 'PDF firmato ai referenti'],
     'I referenti vedono solo le sedute del proprio consiglio.'),
    ('Lettera di incarico di tutorato', '#b45309', ['Bando e vincitore', 'Studente: conferma con SPID/CIE', 'Docente: firma PAdES', 'Direttore: firma PAdES', 'Protocollo', 'Copia allo studente'],
     'Metadati dell\'accesso SPID/CIE e impronta SHA-256 nel PDF.'),
    ('Registro e fine attività', '#0f766e', ['Tutor: giorno, ore, attività', 'Docente: approva o respinge', 'Tutor: «Ho concluso»', 'Docente: conferma', 'Dichiarazione compilata da sola', 'Firma PAdES del docente', 'Avviso «attività completate»', 'Protocollo'],
     'Promemoria al tutor verso la fine del periodo e al docente per le ore da approvare.'),
    ('Formazione Scuola Lavoro', '#B30000', ['Docente: prenota per la classe', 'Convenzione online in PAdES', 'Approvazione', 'Check-in con QR', 'Attestati della classe', 'Valutazione della scuola'],
     'Senza convenzione valida la prenotazione resta da approvare.'),
    ('Evento, agenda e avvisi', '#7c3aed', ['Evento con ambiti', 'Agenda e calendario .ics', 'Avviso agli iscritti (una volta)', 'Prenotazione', 'Check-in con QR', 'Attestato e sondaggio', 'Report per ambito'],
     'Nuovi in questo aggiornamento: ambiti, avvisi per email, report.*'),
]

BOX = [
    ('Automatismi (cron)', '#334155', [
        'Promemoria degli eventi e delle prenotazioni di aule e sportelli', 'Pratiche ferme: promemoria a chi le ha in carico',
        'Registro del tutor: promemoria al tutor e al docente', 'Solleciti delle firme ferme (lettere, fine attività, verbali): ogni <code>FIRME_GIORNI_SOLLECITO</code> giorni, max 3',
        'Avvisi dei nuovi eventi agli iscritti*', 'Anagrafe di Ateneo settimanale, backup, controllo notturno']),
    ('Firme e sicurezza', '#B30000', [
        'Solo PAdES: integrità, revisione incrementale, codice fiscale del firmatario; .p7m rifiutati', 'Firma remota Aruba (ARSS, se configurata) o scarica, firma e ricarica',
        'Conferma con SPID/CIE con metadati dell\'accesso', 'Query preparate; cartelle riservate: <code>uploads/incarichi</code>, <code>pratiche</code>, <code>verbali</code>, <code>convenzioni</code>',
        'CSRF, limiti alle richieste, intestazioni di sicurezza']),
    ('Conservazione e strumenti', '#0f766e', [
        '<code>CONSERVAZIONE_*</code> nel .env: registri, prenotazioni, account, lettere di incarico, convocazioni (descritte in <code>privacy.php</code>)',
        'Avvisi: iscrizioni non confermate cancellate dopo 30 giorni*', 'Prove automatiche <code>strumenti/prove/esegui.php</code> (418 prove)',
        'Sul server: <code>verifica_sito.sh</code> e <code>prove_server.sh</code>', 'Ambiente locale <code>strumenti/locale</code>']),
]

NOVITA = [
    'Sito organizzato per pubblico: home con «Cosa cerchi?», agenda e scadenze; menu Orientamento · Studenti · Eventi e seminari · Area riservata (si applica da Testata e home, con ripristino)',
    'Ambiti degli eventi (Orientamento, Ricerca, Public engagement, Didattica) e agenda unica con calendario .ics',
    'Pagina Orientamento per futuri studenti e scuole',
    'Formazione Scuola Lavoro modulo a sé; il modulo Orientamento diventa «Eventi e seminari»',
    'Seminari: relatore, abstract, diretta, registrazione e slide',
    'Avvisi per email dei nuovi eventi per ambito, con conferma e cancellazione',
    'Report per ambito per la Terza missione, in Excel',
    'Posti «senza limite» mostrati come «Posti disponibili»; caselle del sito pubblico di nuovo visibili',
]

ACCESSI = 'Accessi: amministratori · abilitati per area, per modulo o per la FSL · operatori dell\'Ufficio didattico (compiti: pratiche, sedute, ricevimento, bandi) · referenti dei consigli (solo le sedute del proprio consiglio) · studenti, docenti e personale con SSO Unical, SPID o CIE.'

# ── PDF ───────────────────────────────────────────────────────────────────────
DEJAVU = '/usr/share/fonts/truetype/dejavu/'
if os.path.exists(DEJAVU + 'DejaVuSans.ttf'):
    pdfmetrics.registerFont(TTFont('Testo', DEJAVU + 'DejaVuSans.ttf')); pdfmetrics.registerFont(TTFont('Testo-B', DEJAVU + 'DejaVuSans-Bold.ttf'))
    from reportlab.lib.fonts import addMapping
    addMapping('Testo', 0, 0, 'Testo'); addMapping('Testo', 1, 0, 'Testo-B'); addMapping('Testo', 0, 1, 'Testo'); addMapping('Testo', 1, 1, 'Testo-B')
    F, FB = 'Testo', 'Testo-B'
else:
    F, FB = 'Helvetica', 'Helvetica-Bold'

GIALLO = '#fef3c7'
def tinta(c, quota=0.12):
    # colore mescolato col bianco (sfondo chiaro dei passi dei flussi)
    r, g, b = int(c[1:3], 16), int(c[3:5], 16), int(c[5:7], 16)
    return colors.Color((255 - (255 - r) * quota) / 255, (255 - (255 - g) * quota) / 255, (255 - (255 - b) * quota) / 255)
def testo(v):
    nuovo = v.endswith('*')
    v = v[:-1] if nuovo else v
    v = re.sub(r'<code>(.*?)</code>', r'<font color="#475569" size="6.6">\1</font>', v)
    return v, nuovo

st = ParagraphStyle('t', fontName=F, fontSize=8.1, leading=10.2)
st_li = ParagraphStyle('li', parent=st, leftIndent=8, bulletIndent=0, spaceAfter=2.2)
st_li_n = ParagraphStyle('lin', parent=st_li, backColor=colors.HexColor(GIALLO))
st_sub = ParagraphStyle('sub', parent=st, fontSize=7.2, leading=9, textColor=colors.HexColor('#64748b'), spaceAfter=3)
def st_h(c): return ParagraphStyle('h', fontName=FB, fontSize=10.4, leading=12.5, textColor=colors.HexColor(c), spaceAfter=1)
st_sez = ParagraphStyle('sez', fontName=FB, fontSize=11, leading=13, textColor=colors.HexColor('#0f172a'), spaceBefore=2, spaceAfter=4)

def colonna(titolo, colore, sotto, voci):
    t, n = testo(titolo)
    out = [Paragraph(t, st_h(colore))]
    if sotto:
        s, ns = testo(sotto)
        out.append(Paragraph(('<font backColor="%s">%s</font>' % (GIALLO, s)) if ns else s, st_sub))
    for v in voci:
        t, n = testo(v)
        out.append(Paragraph(t, st_li_n if n else st_li, bulletText='•'))
    return out

def riga(blocchi, larghezze, W):
    tab = Table([[b[1] for b in blocchi]], colWidths=[W * l for l in larghezze])
    s = [('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 5), ('RIGHTPADDING', (0, 0), (-1, -1), 5),
         ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4)]
    for i, b in enumerate(blocchi):
        s += [('LINEABOVE', (i, 0), (i, 0), 3, colors.HexColor(b[0])), ('BOX', (i, 0), (i, 0), 0.6, colors.HexColor('#cbd5e1'))]
    tab.setStyle(TableStyle(s)); return tab

def flusso(titolo, colore, passi, nota, W):
    t, n = testo(titolo); nt, nn = testo(nota)
    chip = ParagraphStyle('chip', parent=st, fontSize=7.5, leading=9, alignment=1)
    celle = []; larg = []
    for i, p in enumerate(passi):
        celle.append(Paragraph(p, chip)); larg.append(1)
        if i < len(passi) - 1: celle.append(Paragraph('<font color="%s"><b>›</b></font>' % colore, ParagraphStyle('fr', parent=st, fontSize=10, alignment=1))); larg.append(0.16)
    tot = sum(larg); w = W - 150
    ch = Table([celle], colWidths=[w * l / tot for l in larg])
    s = [('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('LEFTPADDING', (0, 0), (-1, -1), 3), ('RIGHTPADDING', (0, 0), (-1, -1), 3),
         ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6)]
    for i in range(0, len(celle), 2): s += [('BACKGROUND', (i, 0), (i, 0), tinta(colore)),
                                             ('BOX', (i, 0), (i, 0), 0.6, colors.HexColor(colore))]
    ch.setStyle(TableStyle(s))
    sx = [Paragraph(t, st_h(colore)), Paragraph(('<font backColor="%s">%s</font>' % (GIALLO, nt)) if nn else nt, st_sub)]
    tab = Table([[sx, ch]], colWidths=[150, w])
    tab.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('LINEBEFORE', (0, 0), (0, 0), 3, colors.HexColor(colore)),
                             ('LEFTPADDING', (0, 0), (0, 0), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 7), ('TOPPADDING', (0, 0), (-1, -1), 7),
                             ('LINEBELOW', (0, 0), (-1, -1), 0.4, colors.HexColor('#e2e8f0'))]))
    return tab

def piede(c, doc):
    c.saveState(); c.setFont(F, 6.5); c.setFillColor(colors.HexColor('#64748b'))
    c.drawString(28, 14, 'Didattica DiBEST – mappa del portale · schema %s · %s' % (SCHEMA, AGGIORNATA)); c.drawRightString(landscape(A4)[0] - 28, 14, 'pagina %d di 2' % doc.page)
    c.restoreState()

def pdf(nome='mappa_portale.pdf'):
    PW, PH = landscape(A4); W = PW - 56
    doc = BaseDocTemplate(nome, pagesize=landscape(A4), leftMargin=28, rightMargin=28, topMargin=22, bottomMargin=26,
                          title='Mappa del portale Didattica DiBEST', author='Dipartimento DiBEST – Università della Calabria')
    doc.addPageTemplates([PageTemplate(id='p', frames=[Frame(28, 26, W, PH - 48, id='f', leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)], onPage=piede)])
    el = [Paragraph('Didattica DiBEST – mappa del portale', ParagraphStyle('T', fontName=FB, fontSize=17, leading=20, textColor=colors.HexColor('#9b0000'))),
          Paragraph('dibest2.unical.it/didattica · schema del database %s · aggiornata il %s · <font backColor="%s">in giallo</font> le novità dell\'ultimo aggiornamento' % (SCHEMA, AGGIORNATA, GIALLO), st_sub),
          Spacer(1, 4), Paragraph('Il sito pubblico <font size="7.5" color="#64748b">— menu per pubblico: Home · Orientamento · Studenti · Eventi e seminari · Area riservata</font>', st_sez)]
    el.append(riga([(c, colonna(t, c, i, v)) for t, c, i, v in SITO], [0.18, 0.2, 0.2, 0.22, 0.2], W))
    el += [Spacer(1, 12), Paragraph('Il pannello di gestione <font size="7.5" color="#64748b">— cinque moduli, ognuno con il suo menu</font>', st_sez)]
    el.append(riga([(c, colonna(t, c, s, v)) for t, c, s, v in PANNELLO], [0.2, 0.19, 0.17, 0.25, 0.19], W))
    el += [Spacer(1, 8), Paragraph(ACCESSI, ParagraphStyle('acc', parent=st_sub, fontSize=7.4)), Spacer(1, 10)]
    meta = (len(NOVITA) + 1) // 2
    st_nv = ParagraphStyle('nv', parent=st_li, backColor=None)
    nov = Table([[[Paragraph(v, st_nv, bulletText='•') for v in NOVITA[:meta]], [Paragraph(v, st_nv, bulletText='•') for v in NOVITA[meta:]]]], colWidths=[(W - 150) / 2] * 2)
    nov.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('LEFTPADDING', (0, 0), (-1, -1), 4)]))
    box_nv = Table([[Paragraph('Novità di questo aggiornamento', st_h('#9b0000')), nov]], colWidths=[150, W - 150])
    box_nv.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'TOP'), ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor(GIALLO)), ('LEFTPADDING', (0, 0), (-1, -1), 8),
                                ('TOPPADDING', (0, 0), (-1, -1), 7), ('BOTTOMPADDING', (0, 0), (-1, -1), 7), ('BOX', (0, 0), (-1, -1), 0.6, colors.HexColor('#f59e0b'))]))
    el.append(box_nv)
    el += [PageBreak(), Paragraph('I flussi principali', st_sez)]
    for t, c, p, n in FLUSSI: el.append(flusso(t, c, p, n, W))
    el += [Spacer(1, 14), riga([(c, colonna(t, c, '', v)) for t, c, v in BOX], [0.34, 0.33, 0.33], W)]
    doc.build(el)

# ── HTML (stesso contenuto, per la consultazione a schermo) ──────────────────
def html(nome='mappa_portale.html'):
    def li(v):
        t, n = (v[:-1], True) if v.endswith('*') else (v, False)
        return '<li%s>%s</li>' % (' class="nuovo"' if n else '', t)
    def col(t, c, s, voci):
        tt, n = (t[:-1], True) if t.endswith('*') else (t, False)
        ss = (s[:-1], True) if s.endswith('*') else (s, False)
        return ('<div class="mod" style="--c:%s"><h3%s>%s</h3>' % (c, ' class="nuovo"' if n else '', tt)
                + ('<div class="sub%s">%s</div>' % (' nuovo' if ss[1] else '', ss[0]) if s else '') + '<ul>' + ''.join(li(v) for v in voci) + '</ul></div>')
    o = ['<!doctype html>\n<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mappa del portale Didattica DiBEST</title>',
         '<style>body{font-family:"DejaVu Sans",system-ui,sans-serif;font-size:14px;color:#1e293b;margin:24px;background:#fff}h1{color:#9b0000;margin:0}h2{margin:22px 0 8px}',
         '.sub{color:#64748b;font-size:12px;margin-bottom:4px}.griglia{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}',
         '.mod{border:1px solid #cbd5e1;border-top:4px solid var(--c);border-radius:8px;padding:8px 10px}.mod h3{color:var(--c);margin:0 0 4px;font-size:15px}',
         'ul{margin:0;padding-left:16px}li{margin:2px 0}code{color:#475569;font-size:12px}.nuovo{background:#fef3c7;border-radius:3px}',
         '.flusso{display:flex;gap:10px;align-items:center;border-left:4px solid var(--c);padding:6px 10px;margin:6px 0;flex-wrap:wrap}.flusso h3{color:var(--c);margin:0;font-size:15px;min-width:180px}',
         '.passo{border:1px solid var(--c);border-radius:6px;padding:3px 7px;font-size:12px}.fr{color:var(--c);font-weight:bold}</style></head><body>',
         '<h1>Didattica DiBEST – mappa del portale</h1><div class="sub">dibest2.unical.it/didattica · schema del database %s · aggiornata il %s · <span class="nuovo">in giallo</span> le novità dell\'ultimo aggiornamento</div>' % (SCHEMA, AGGIORNATA),
         '<h2>Il sito pubblico</h2><div class="griglia">' + ''.join(col(t, c, '<code>%s</code>' % i, v) for t, c, i, v in SITO) + '</div>',
         '<h2>Il pannello di gestione</h2><div class="griglia">' + ''.join(col(t, c, s, v) for t, c, s, v in PANNELLO) + '</div>',
         '<p class="sub">%s</p>' % ACCESSI,
         '<div class="nuovo" style="padding:10px 14px;border:1px solid #f59e0b;border-radius:8px"><strong style="color:#9b0000">Novità di questo aggiornamento</strong><ul>' + ''.join('<li>%s</li>' % v for v in NOVITA) + '</ul></div>',
         '<h2>I flussi principali</h2>']
    for t, c, p, n in FLUSSI:
        nn = (n[:-1], True) if n.endswith('*') else (n, False)
        o.append('<div class="flusso" style="--c:%s"><h3>%s</h3>%s<div class="sub%s" style="width:100%%">%s</div></div>' % (c, t, '<span class="fr">›</span>'.join('<span class="passo">%s</span>' % x for x in p), ' nuovo' if nn[1] else '', nn[0]))
    o.append('<h2>Automatismi, sicurezza, conservazione</h2><div class="griglia">' + ''.join(col(t, c, '', v) for t, c, v in BOX) + '</div></body></html>\n')
    open(nome, 'w', encoding='utf-8').write('\n'.join(o))

if __name__ == '__main__':
    pdf(); html()
    print('mappa_portale.pdf e mappa_portale.html aggiornati')
