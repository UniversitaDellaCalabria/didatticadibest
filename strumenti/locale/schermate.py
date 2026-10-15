#!/usr/bin/env python3
"""Schermate del portale locale (per guide e presentazioni), senza librerie esterne: Chrome in modalità headless pilotato dal protocollo DevTools.

Uso:  python strumenti/locale/schermate.py elenco.json cartella_uscita
  elenco.json = lista di {"nome": "file.png", "utente": 1, "url": "/eventi/admin/dashboard.php", "larghezza": 1280, "altezza": 800, "intera": false, "attesa": 1.0, "js": "..."}
  «clip» = [x, y, larghezza, altezza] per ritagliare; «utente» = id dell'utente di prova con cui entrare (come in /__accesso); se manca resta l'ultimo.
Prima si avvia il portale locale: bash strumenti/locale/avvia.sh
"""
import base64, hashlib, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request

CHROME = os.environ.get('CHROME', r'C:\Program Files\Google\Chrome\Application\chrome.exe')
BASE = os.environ.get('PORTALE', 'http://127.0.0.1:8080')
PORTA = 9333


class Cdp:
    def __init__(self, ws_url):
        host, resto = ws_url[5:].split('/', 1)
        h, p = host.split(':')
        self.s = socket.create_connection((h, int(p)), timeout=60)
        chiave = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall(('GET /%s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n' % (resto, host, chiave)).encode())
        buf = b''
        while b'\r\n\r\n' not in buf:
            buf += self.s.recv(4096)
        self.resto = buf.split(b'\r\n\r\n', 1)[1]
        self.n = 0

    def _leggi(self, k):
        while len(self.resto) < k:
            self.resto += self.s.recv(1 << 20)
        out, self.resto = self.resto[:k], self.resto[k:]
        return out

    def _frame(self):
        dati = b''
        while True:
            b1, b2 = self._leggi(2)
            fin, op, lung = b1 & 0x80, b1 & 0x0F, b2 & 0x7F
            if lung == 126:
                lung = struct.unpack('>H', self._leggi(2))[0]
            elif lung == 127:
                lung = struct.unpack('>Q', self._leggi(8))[0]
            dati += self._leggi(lung)
            if fin and op in (0, 1, 2):
                return dati
            if fin:
                dati = b''

    def chiama(self, metodo, **par):
        self.n += 1
        msg = json.dumps({'id': self.n, 'method': metodo, 'params': par}).encode()
        mask = os.urandom(4)
        lung = len(msg)
        intest = b'\x81' + (bytes([0x80 | lung]) if lung < 126 else b'\xfe' + struct.pack('>H', lung) if lung < 65536 else b'\xff' + struct.pack('>Q', lung))
        self.s.sendall(intest + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(msg)))
        while True:
            r = json.loads(self._frame())
            if r.get('id') == self.n:
                if 'error' in r:
                    raise RuntimeError(r['error'])
                return r.get('result', {})


def main():
    elenco = json.load(open(sys.argv[1], encoding='utf8'))
    uscita = sys.argv[2]
    os.makedirs(uscita, exist_ok=True)
    profilo = tempfile.mkdtemp(prefix='schermate_')
    chrome = subprocess.Popen([CHROME, '--headless=new', '--remote-debugging-port=%d' % PORTA, '--user-data-dir=' + profilo, '--hide-scrollbars', '--no-first-run',
                               '--force-device-scale-factor=1', '--lang=it-IT', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                req = urllib.request.Request('http://127.0.0.1:%d/json/new?about:blank' % PORTA, method='PUT')
                bersaglio = json.load(urllib.request.urlopen(req, timeout=2))
                break
            except Exception:
                time.sleep(0.4)
        else:
            raise SystemExit('Chrome non risponde')
        c = Cdp(bersaglio['webSocketDebuggerUrl'])
        c.chiama('Page.enable')
        # Niente banner dei cookie nelle schermate
        c.chiama('Page.addScriptToEvaluateOnNewDocument', source='try { localStorage.setItem("cookie_dibest_accepted", "true"); } catch (e) {}')
        ultimo = None
        for voce in elenco:
            if voce.get('utente') and voce['utente'] != ultimo:
                c.chiama('Page.navigate', url='%s/__accesso?u=%d' % (BASE, voce['utente']))
                time.sleep(1.2)
                ultimo = voce['utente']
            larg, alt = voce.get('larghezza', 1280), voce.get('altezza', 800)
            c.chiama('Emulation.setDeviceMetricsOverride', width=larg, height=alt, deviceScaleFactor=1, mobile=voce.get('mobile', False))
            c.chiama('Page.navigate', url=BASE + voce['url'])
            time.sleep(voce.get('attesa', 1.2))
            if voce.get('js'):
                c.chiama('Runtime.evaluate', expression=voce['js'], awaitPromise=True)
                time.sleep(0.6)
            par = {'format': 'png'}
            if voce.get('clip'):
                x, y, w, hh = voce['clip']
                par.update({'clip': {'x': x, 'y': y, 'width': w, 'height': hh, 'scale': 1}, 'captureBeyondViewport': True})
            if voce.get('intera'):
                h = c.chiama('Runtime.evaluate', expression='Math.min(document.documentElement.scrollHeight, %d)' % voce.get('massimo', 3000), returnByValue=True)['result']['value']
                c.chiama('Emulation.setDeviceMetricsOverride', width=larg, height=int(h), deviceScaleFactor=1, mobile=voce.get('mobile', False))
                time.sleep(0.4)
            img = c.chiama('Page.captureScreenshot', **par)['data']
            open(os.path.join(uscita, voce['nome']), 'wb').write(base64.b64decode(img))
            print('ok', voce['nome'])
    finally:
        chrome.terminate()


if __name__ == '__main__':
    main()
