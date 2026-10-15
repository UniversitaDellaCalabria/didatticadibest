#!/usr/bin/env python3
"""Fa le schermate per le guide e le presentazioni (documentazione/schermate/*.png) dallo scenario di dimostrazione.

Prima: bash strumenti/locale/avvia.sh --nuovo (portale acceso) e php strumenti/locale/scenario_demo.php.
Poi:   python strumenti/locale/schermate_documentazione.py [prefisso]   (con un prefisso, per esempio «stud_», rifà solo quelle)
"""
import json, os, subprocess, sys

radice = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
S = json.load(open(os.path.join(radice, 'cache', 'scenario_demo.json'), encoding='utf8'))
USCITA = os.path.join(radice, 'documentazione', 'schermate')
P = '/eventi/'

CLICCA_SLOT_LIBERO = "document.querySelector('#settimana .slot-libero[data-inizio]').click()"


def pub(nome, utente, url, alt=800, ritaglio=None, js=None, larg=1280):
    """Pagina del sito pubblico/Area personale: si toglie la testata (170 px) e, se serve, si ritaglia l'altezza."""
    voce = {'nome': nome, 'utente': utente, 'url': P + url, 'larghezza': larg, 'altezza': 1000, 'attesa': 1.4}
    voce['clip'] = [0, 175, larg, ritaglio or alt]
    if js:
        voce['js'] = js
    return voce


def pannello(nome, utente, url, alt=760, intera=False, js=None):
    """Pagina del pannello (admin): schermata della finestra, 1280 x alt."""
    voce = {'nome': nome, 'utente': utente, 'url': P + url, 'larghezza': 1280, 'altezza': alt, 'attesa': 1.6}
    if intera:
        voce['intera'] = True
        voce['massimo'] = 2600
    if js:
        voce['js'] = js
    return voce


elenco = [
    # Studente
    pub('stud_area.png', 5, 'area_personale.php', 780),
    pub('stud_moduli.png', 5, 'modulistica.php', 820),
    pub('stud_modulo_certificato.png', 5, 'modulo.php?id=%d' % S['modulo_certificato'], 900),
    pub('stud_modulo_tesi.png', 5, 'modulo.php?id=%d' % S['modulo_tesi'], 900),
    pub('stud_pratiche.png', 5, 'pratiche.php', 760),
    pub('stud_pratica_tesi.png', 5, 'pratiche.php?id=%d' % S['tesi'], 820),
    pub('stud_pratica_laurea.png', 5, 'pratiche.php?id=%d' % S['laurea'], 820),
    pub('stud_calendario.png', 5, 'aule-laboratori.php?risorsa=21&dal=%s' % S['lunedi'], 1010),
    pub('stud_prenota.png', 5, 'aule-laboratori.php?risorsa=21&dal=%s' % S['lunedi'], 1130, js=CLICCA_SLOT_LIBERO),
    # Docente / relatore
    pub('rel_conferma.png', 6, 'conferma_attestato.php?t=%s' % S['conferma_token'], 700),
    pub('rel_area.png', 6, 'area_personale.php', 780),
    pub('rel_conferma_attestato.png', 6, 'conferma_attestato.php?t=%s' % S['conferma_attestato_token'], 760),
    pub('resp_diario.png', 6, 'diario_bordo.php?c=RS-DEMO0', 900),
    # Direttore
    pub('dir_cruscotto.png', 7, 'cruscotto_direttore.php', 1130),
    pub('dir_area.png', 7, 'area_personale.php', 780),
    pub('dir_firma.png', 7, 'firma_pratica.php?t=%s' % S['firma_token'], 780),
    # Ufficio protocollo
    pannello('proto_elenco.png', 8, 'admin/didattica.php?tab=pratiche'),
    pannello('proto_scheda.png', 8, 'admin/didattica.php?tab=pratiche&id=%d' % S['esonero'], 900),
    # Ufficio del corso (referente)
    pannello('ref_elenco.png', 9, 'admin/didattica.php?tab=pratiche'),
    pannello('ref_scheda.png', 9, 'admin/didattica.php?tab=pratiche&id=%d' % S['inglese'], 900),
    pannello('ref_marche.png', 9, 'admin/didattica.php?tab=pratiche&id=%d' % S['laurea'], 1130),
    pannello('ref_sedute.png', 9, 'admin/didattica.php?tab=sedute'),
    pannello('ref_seduta.png', 9, 'admin/didattica.php?tab=sedute&id=%d' % S['seduta'], 900),
    # Internazionalizzazione
    pannello('mob_scheda.png', 11, 'admin/didattica.php?tab=pratiche&id=%d' % S['mobilita'], 900),
    # Responsabile di risorsa
    pub('resp_risorse.png', 10, 'risorse_responsabile.php', 780),
    pub('resp_cruscotto.png', 10, 'risorse_cruscotto.php?r=23&p=90', 1180),
    # Amministratore e ufficio didattico
    pannello('adm_scrivania.png', 1, 'admin/inizio.php'),
    pannello('adm_moduli.png', 1, 'admin/didattica.php?tab=moduli', 900),
    pannello('adm_sistema.png', 1, 'admin/sistema.php', 900),
    pannello('adm_prenotazioni.png', 1, 'admin/prenotazioni_risorse.php?p_id=20', 800),
    pannello('man_scrivania.png', 12, 'admin/inizio.php'),
]

prefisso = sys.argv[1] if len(sys.argv) > 1 else ''
elenco = [v for v in elenco if v['nome'].startswith(prefisso)]
tmp = os.path.join(radice, 'cache', 'schermate_elenco.json')
json.dump(elenco, open(tmp, 'w', encoding='utf8'), indent=1)
subprocess.check_call([sys.executable, os.path.join(radice, 'strumenti', 'locale', 'schermate.py'), tmp, USCITA])
