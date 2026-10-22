# -*- coding: utf-8 -*-
"""Parti in comune delle due mappe del portale (pubblica e tecnica): stile, pagina, figure, lightbox.
Usato da genera_mappe.py. Le schermate sono in documentazione/mappa/img/ (vedi raccogli_img nel README della cartella)."""
import html, os

RADICE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
USCITA = os.path.join(RADICE, 'documentazione', 'mappa')
IMG = os.path.join(USCITA, 'img')
DATA = '22 ottobre 2026'

CSS = """
:root{--c1:#8e0f1f;--c2:#b30000;--ink:#1e293b;--muted:#64748b;--line:#e2e8f0;--bg:#f8fafc;--card:#fff;--ok:#15803d;--blu:#0b5cad;--code:#0f172a}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:70px}
body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg);line-height:1.65;font-size:17px}
a{color:var(--blu)}
header.hero{background:linear-gradient(135deg,#6b0b17,#b30000);color:#fff;padding:56px 20px 44px}
header.hero .in{max-width:1100px;margin:0 auto}
header.hero .kicker{font-size:.85rem;letter-spacing:.12em;text-transform:uppercase;opacity:.85;margin:0 0 8px}
header.hero h1{font-size:clamp(2rem,5vw,3.2rem);line-height:1.1;margin:0 0 12px}
header.hero p.sub{font-size:1.2rem;max-width:760px;margin:0 0 20px;opacity:.95}
header.hero .meta{font-size:.9rem;opacity:.8}
header.hero .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
header.hero .btns a{background:#fff;color:var(--c1);text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600;font-size:.95rem}
header.hero .btns a.sec{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.6)}
nav.toc{position:sticky;top:0;z-index:20;background:#fff;border-bottom:1px solid var(--line);overflow-x:auto;white-space:nowrap;padding:8px 14px}
nav.toc a{display:inline-block;padding:6px 12px;margin-right:4px;border-radius:20px;text-decoration:none;color:var(--ink);font-size:.9rem}
nav.toc a:hover{background:#fdf2f4;color:var(--c1)}
main{max-width:1100px;margin:0 auto;padding:10px 20px 80px}
section{padding-top:38px}
section>h2{font-size:1.9rem;line-height:1.2;margin:0 0 6px;color:var(--c1)}
section>h2 .n{display:inline-block;background:var(--c1);color:#fff;border-radius:8px;font-size:.95rem;padding:2px 10px;margin-right:10px;vertical-align:middle}
section>p.lead{font-size:1.15rem;color:#334155;margin:6px 0 18px}
h3{font-size:1.25rem;margin:28px 0 6px}
h4{font-size:1.05rem;margin:18px 0 4px;color:#334155}
p{margin:0 0 12px}
ul,ol{padding-left:22px;margin:6px 0 14px}
li{margin:3px 0}
.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));margin:14px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px}
.card h4{margin:0 0 6px;font-size:1.05rem;color:var(--c1)}
.card p{font-size:.97rem;margin:0 0 6px;color:#334155}
.card .ic{font-size:1.6rem;display:block;margin-bottom:4px}
.pill{display:inline-block;background:#fdf2f4;color:var(--c1);border-radius:20px;padding:1px 10px;font-size:.8rem;font-weight:600;margin:0 4px 4px 0}
.pill.g{background:#ecfdf5;color:var(--ok)}.pill.b{background:#eff6ff;color:var(--blu)}.pill.a{background:#fffbeb;color:#92400e}
figure{margin:18px 0;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
figure img{display:block;width:100%;height:auto;cursor:zoom-in;background:#f1f5f9}
figcaption{padding:10px 14px;font-size:.92rem;color:var(--muted);border-top:1px solid var(--line)}
figcaption b{color:var(--ink)}
.gal{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin:14px 0}
.gal figure{margin:0}
.duo{display:grid;gap:14px;grid-template-columns:1fr 1fr}
@media(max-width:760px){.duo{grid-template-columns:1fr}.gal{grid-template-columns:1fr}body{font-size:16px}}
.steps{counter-reset:s;list-style:none;padding:0;margin:14px 0}
.steps li{counter-increment:s;position:relative;padding:10px 14px 10px 54px;margin:8px 0;background:#fff;border:1px solid var(--line);border-radius:12px}
.steps li:before{content:counter(s);position:absolute;left:12px;top:10px;width:30px;height:30px;border-radius:50%;background:var(--c1);color:#fff;text-align:center;line-height:30px;font-weight:700}
.steps li b{color:var(--c1)}
.nota{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.97rem}
.info{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.97rem}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:12px 16px;margin:14px 0;font-size:.97rem}
table{border-collapse:collapse;width:100%;margin:12px 0 18px;font-size:.93rem;background:#fff}
th,td{border:1px solid var(--line);padding:7px 10px;text-align:left;vertical-align:top}
th{background:#f1f5f9;font-weight:600}
pre{background:var(--code);color:#e2e8f0;border-radius:10px;padding:14px 16px;overflow:auto;font-size:.86rem;line-height:1.5;margin:12px 0 18px}
code{font-family:Consolas,"SFMono-Regular",Menlo,monospace;font-size:.88em;background:#f1f5f9;border-radius:4px;padding:1px 5px}
pre code{background:none;padding:0;color:inherit;font-size:1em}
.k{color:#7dd3fc}.s{color:#fcd34d}.c{color:#94a3b8}.f{color:#86efac}
svg text{font-family:"Segoe UI",system-ui,Arial,sans-serif}
.kpi{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:14px 0}
.kpi div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;text-align:center}
.kpi b{display:block;font-size:1.35rem;color:var(--c1);line-height:1.2;overflow-wrap:anywhere}
.kpi span{font-size:.85rem;color:var(--muted)}
footer{background:#1e293b;color:#cbd5e1;padding:28px 20px;font-size:.9rem}
footer .in{max-width:1100px;margin:0 auto}footer a{color:#fff}
#lb{position:fixed;inset:0;background:rgba(15,23,42,.92);display:none;z-index:50;align-items:center;justify-content:center;padding:20px;cursor:zoom-out}
#lb.on{display:flex}#lb img{max-width:100%;max-height:100%;border-radius:8px;background:#fff}
#su{position:fixed;right:16px;bottom:16px;background:var(--c1);color:#fff;border:0;border-radius:50%;width:44px;height:44px;font-size:1.2rem;cursor:pointer;opacity:.9}
@media print{nav.toc,#su,#lb{display:none!important}header.hero{padding:20px}figure{break-inside:avoid}body{font-size:12pt}}
"""

JS = """
(function(){
  var lb=document.getElementById('lb'),li=lb.querySelector('img');
  document.querySelectorAll('figure img').forEach(function(i){i.addEventListener('click',function(){li.src=i.src;lb.classList.add('on');});});
  lb.addEventListener('click',function(){lb.classList.remove('on');});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')lb.classList.remove('on');});
  document.getElementById('su').addEventListener('click',function(){window.scrollTo({top:0});});
})();
"""


def esc(t):
    return html.escape(t, quote=True)


def fig(nome, didascalia, alt=None):
    """Figura con schermata (documentazione/mappa/img/<nome>.jpg) e didascalia (può contenere <b>)."""
    if not os.path.isfile(os.path.join(IMG, nome + '.jpg')):
        raise SystemExit('Schermata mancante: ' + nome)
    return ('<figure><img src="img/%s.jpg" loading="lazy" alt="%s"><figcaption>%s</figcaption></figure>' % (nome, esc(alt or re_tag(didascalia)), didascalia))


def re_tag(t):
    import re
    return re.sub(r'<[^>]+>', '', t)


def gal(*figure):
    return '<div class="gal">' + ''.join(figure) + '</div>'


def duo(*figure):
    return '<div class="duo">' + ''.join(figure) + '</div>'


def card(titolo, testo, icona=''):
    return '<div class="card">%s<h4>%s</h4>%s</div>' % ('<span class="ic">%s</span>' % icona if icona else '', titolo, testo)


def griglia(*carte):
    return '<div class="grid">' + ''.join(carte) + '</div>'


def passi(*voci):
    return '<ol class="steps">' + ''.join('<li>%s</li>' % v for v in voci) + '</ol>'


def sezione(id_, numero, titolo, lead, corpo):
    return '<section id="%s"><h2><span class="n">%s</span>%s</h2>%s%s</section>' % (id_, numero, titolo, '<p class="lead">%s</p>' % lead if lead else '', corpo)


def pagina(titolo, kicker, sottotitolo, bottoni, sezioni, altra_versione, descrizione):
    nav = ''.join('<a href="#%s">%s</a>' % (i, t) for i, t in sezioni_nav(sezioni))
    btn = ''.join('<a href="#%s"%s>%s</a>' % (h, ' class="sec"' if k else '', t) for h, t, k in bottoni)
    corpo = ''.join(s for _, _, s in sezioni)
    return """<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="%s"><meta name="robots" content="noindex">
<title>%s</title><style>%s</style></head><body>
<header class="hero"><div class="in"><p class="kicker">%s</p><h1>%s</h1><p class="sub">%s</p>
<div class="meta">Aggiornata al %s · <a href="%s" style="color:#fff">%s</a></div><div class="btns">%s</div></div></header>
<nav class="toc" aria-label="Indice">%s</nav>
<main>%s</main>
<footer><div class="in"><p><b>Didattica DiBEST</b> · Dipartimento di Biologia, Ecologia e Scienze della Terra · Università della Calabria.<br>Software libero (licenza AGPL v3). Questa pagina descrive il portale come è oggi; le schermate vengono da un ambiente di prova con dati inventati.</p></div></footer>
<div id="lb" role="dialog" aria-label="Schermata ingrandita"><img alt=""></div><button id="su" aria-label="Torna su">&#8679;</button>
<script>%s</script></body></html>""" % (esc(descrizione), esc(titolo), CSS, kicker, titolo, sottotitolo, DATA, altra_versione[0], altra_versione[1], btn, nav, corpo, JS)


def sezioni_nav(sezioni):
    return [(i, t) for i, t, _ in sezioni]
