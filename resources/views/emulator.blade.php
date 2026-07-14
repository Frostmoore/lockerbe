{{--
    L'EMULATORE DEL CHIOSCO (piano §21.8, F5.5–F5.9).

    ⚠️ Non e' un finto device: e' UN DEVICE DIVERSO che implementa lo stesso protocollo.
    Stessi topic MQTT, stesso payload, stessa verifica della firma, stesso rifiuto dei comandi
    scaduti. Quando arrivera' il FCV5003, il server non dovra' cambiare una riga.

    ⚠️ Cio' che NON dimostra: lo scriviamo noi, quindi conferma le nostre assunzioni. Non valida
    il protocollo RS-485 (D5), ne' i limiti di dxUi, ne' il client MQTT di DejaOS su rete
    instabile. Elimina il rischio SERVER e il rischio CONTRATTO, non il rischio HARDWARE.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LockUpWorld — chiosco {{ $cabinet->code }}</title>
    <script src="/js/mqtt.min.js"></script>
    <style>
        /*
         * ⚠️ IL CHIOSCO E' UN TABLET 7" IN VERTICALE, avvitato al centro di un armadio di
         * lamiera. Non e' una pagina web su un monitor: e' un pannello alto e stretto, guardato
         * in piedi, spesso al buio di un locale, spesso da qualcuno che ha fretta.
         *
         * Da qui tutte le scelte: **fondo bianco** (uno schermo scuro in un ambiente scuro si
         * legge peggio, e sporca di riflessi), **contrasti forti**, testo grosso, un'azione per
         * schermata. La sidebar a destra e' il "ferro" — non esiste sul device vero.
         */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0f1115;                 /* il banco di lavoro, non il chiosco */
            color: #e6e8eb;
            display: grid;
            grid-template-columns: 1fr 380px;
            height: 100vh;
        }
        @media (max-width: 900px) { body { grid-template-columns: 1fr; height: auto; } }

        /* ── IL CHIOSCO: il tablet vero e proprio ────────────────────────────── */
        #stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 24px;
            overflow-y: auto;
        }

        .brand {
            font-size: 12px;
            color: #6b7280;
            letter-spacing: .04em;
        }

        /* Il "vetro": proporzione di un 7" in verticale (10:16). */
        #kiosk {
            width: min(440px, 100%);
            aspect-ratio: 10 / 16;
            max-height: calc(100vh - 90px);
            background: #fff;
            color: #0b1220;
            border-radius: 22px;
            border: 10px solid #1b2130;          /* la cornice: il tablet e' avvitato dentro */
            box-shadow: 0 24px 60px rgba(0, 0, 0, .55);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            padding: 28px 22px;
            text-align: center;
            overflow-y: auto;
        }

        /* ── Tipografia: grossa, nera, leggibile in piedi ─────────────────────── */
        #kiosk h1 {
            font-size: 34px;
            font-weight: 800;
            margin: 0;
            color: #0b1220;
            line-height: 1.15;
        }

        #kiosk .sub {
            margin: 0;
            color: #475569;
            font-size: 16px;
            line-height: 1.5;
            max-width: 34ch;
        }

        /* ⚠️ IL MARCHIO. Il chiosco e' l'unica cosa che il cliente vede del nostro prodotto:
           il nome sta in alto, grande, e non si muove piu' da li'. */
        .logo {
            font-size: 30px;
            font-weight: 900;
            letter-spacing: -.02em;
            color: #0b1220;
            line-height: 1;
        }
        .logo span { color: #14306b; }
        .logo__claim { margin-top: 6px; color: #64748b; font-size: 15px; }

        /*
         * ⚠️ QUANTI VANI LIBERI. E' l'informazione che il cliente cerca da tre metri di
         * distanza, prima ancora di avvicinarsi: o c'e' posto, o se ne va. Per questo e' grande
         * quanto un numero di vano, e non una riga di testo in fondo.
         *
         * ⚠️ E si aggiorna DA SOLA (ogni 3 secondi): un altro cliente puo' aver preso l'ultimo
         * vano mentre questo leggeva. Un contatore fermo e' un contatore che mente — e la
         * bugia si scopre solo dopo aver premuto il bottone.
         */
        .posti {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 16px;
            border-radius: 16px;
            background: #f0fdf4;
            border: 2px solid #16a34a;
        }
        .posti--pieno { background: #fef2f2; border-color: #dc2626; }

        .posti__n { font-size: 46px; font-weight: 900; line-height: 1; color: #15803d; }
        .posti--pieno .posti__n { color: #b91c1c; }
        .posti__txt { font-size: 15px; font-weight: 700; color: #166534; }
        .posti--pieno .posti__txt { color: #7f1d1d; }
        .posti__tot { font-size: 13px; color: #64748b; font-weight: 500; }

        /* L'immagine del contactless (vedi public/img/nfc.png). */
        .nfc-img { width: 190px; height: 190px; object-fit: contain; }

        /*
         * ⚠️ IL TASTO DEL TECNICO. Piccolo, in un angolo, grigio chiaro: **non deve invitare
         * nessuno**. Un menu di impostazioni con un bottone grosso e colorato e' un menu che il
         * cliente ubriaco preme per curiosita', e a quel punto sta guardando la configurazione
         * WiFi dell'armadio.
         */
        #kiosk { position: relative; }

        #btn-settings {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #cbd5e1;
            font-size: 19px;
            cursor: pointer;
            line-height: 1;
        }
        #btn-settings:hover { background: #f1f5f9; color: #64748b; }

        /* Il menu tecnico: fondo scuro, apposta. Non e' una schermata per i clienti, e si vede. */
        .tec {
            width: 100%;
            text-align: left;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .tec label { display: block; font-size: 12px; color: #64748b; margin-bottom: 4px;
                     text-transform: uppercase; letter-spacing: .06em; font-weight: 700; }
        /*
         * ⚠️ I campi del menu tecnico NON sono quelli del cliente.
         *
         * Il campo del codice a 6 cifre e' largo 220px, con caratteri enormi e spaziati: e'
         * fatto per essere colpito col pollice, in piedi, al buio. Qui invece si scrive un SSID
         * o una password WiFi lunga — roba che va **letta**, non centrata. Larghezza piena,
         * carattere piccolo, allineamento a sinistra.
         *
         * `#kiosk input` ha una specificita' piu' alta di `.tec input` (id + elemento vs classe
         * + elemento), quindi senza `#kiosk` davanti queste regole verrebbero ignorate in
         * silenzio — ed e' esattamente cio' che era successo.
         */
        #kiosk .tec input,
        #kiosk .tec select {
            width: 100%;
            padding: 11px 12px;
            font-size: 14px;
            line-height: 1.3;
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #0b1220;
            letter-spacing: normal;
            text-align: left;
            font-family: inherit;
        }
        #kiosk .tec input:focus,
        #kiosk .tec select:focus { outline: 0; border-color: #14306b; }

        /* Il campo del PIN: pieno anche lui, e monospaziato (una password si conta a occhio). */
        #kiosk #pin {
            width: 100%;
            padding: 12px 14px;
            font-size: 18px;
            letter-spacing: 2px;
            text-align: left;
            font-family: ui-monospace, Consolas, monospace;
        }
        .tec .riga { display: flex; justify-content: space-between; gap: 10px;
                     padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        .tec .riga b { color: #0b1220; }
        .tec .riga span { color: #64748b; font-family: ui-monospace, Consolas, monospace; }

        /* ⚠️ Tasti NEUTRI (quelli che portano avanti): blu scuro, testo bianco. */
        .big-btn {
            width: 100%;
            font-size: 20px;
            font-weight: 700;
            padding: 18px 20px;
            border: 0;
            border-radius: 14px;
            background: #14306b;
            color: #fff;
            cursor: pointer;
            line-height: 1.3;
        }
        .big-btn:hover { background: #0f2354; }
        .big-btn:disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; }

        /* Tasti d'AZIONE / secondari: grigi. */
        .big-btn.gray { background: #e2e8f0; color: #0b1220; }
        .big-btn.gray:hover { background: #cbd5e1; }

        .big-btn.green { background: #15803d; }
        .big-btn.green:hover { background: #166534; }
        .big-btn.warn  { background: #b45309; }
        .big-btn.warn:hover { background: #92400e; }

        .row { display: flex; flex-direction: column; gap: 10px; width: 100%; }

        .qr { background: #fff; padding: 10px; border-radius: 12px; border: 1px solid #e2e8f0; }

        .locker-num {
            font-size: 92px;
            font-weight: 900;
            color: #15803d;
            line-height: 1;
            margin: 4px 0;
        }

        /* ⚠️ Il simbolo contactless: e' il linguaggio che il cliente gia' conosce. */
        .rfid { color: #14306b; }
        .rfid--wait { animation: onda 1.6s ease-in-out infinite; }
        @keyframes onda { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }

        .avviso {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            background: #fef3c7;
            border: 2px solid #f59e0b;
            color: #78350f;
            font-size: 15px;
            font-weight: 700;
            line-height: 1.45;
        }

        .errore {
            width: 100%;
            padding: 16px;
            border-radius: 12px;
            background: #fee2e2;
            border: 2px solid #dc2626;
            color: #7f1d1d;
            font-size: 15px;
            line-height: 1.5;
        }

        #kiosk input {
            width: 220px;
            padding: 14px;
            font-size: 30px;
            text-align: center;
            letter-spacing: 8px;
            font-family: ui-monospace, Consolas, monospace;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
            color: #0b1220;
        }
        #kiosk input:focus { outline: 0; border-color: #14306b; }

        /* ── IL PANNELLO "HARDWARE": quello che nella realta' e' fatto di ferro ── */
        #panel { background: #151922; border-left: 1px solid #232a37; padding: 18px;
                 overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
        .card { background: #1b2130; border: 1px solid #232a37; border-radius: 10px; padding: 12px; }
        .card h3 { margin: 0 0 10px; font-size: 12px; text-transform: uppercase;
                   letter-spacing: .08em; color: #7c8598; }
        .btn { display: block; width: 100%; padding: 10px; margin-bottom: 6px; border: 0;
               border-radius: 8px; background: #2a3244; color: #e6e8eb; cursor: pointer;
               font-size: 14px; text-align: left; }
        .btn:hover { background: #343e54; }
        .btn.on  { background: #16a34a; } .btn.off { background: #7f1d1d; }
        .btn.warn{ background: #b45309; }
        label { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #9ca3af; }
        input[type=text] { width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #2a3244;
                           background: #0f1115; color: #e6e8eb; }
        #log { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px;
               background: #0b0e13; border-radius: 8px; padding: 10px; height: 220px;
               overflow-y: auto; line-height: 1.55; }
        .l-in  { color: #60a5fa; } .l-out { color: #34d399; }
        .l-err { color: #f87171; } .l-warn{ color: #fbbf24; } .l-dim { color: #6b7280; }
        .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%;
               background: #ef4444; margin-right: 6px; }
        .dot.up { background: #22c55e; }
    </style>
</head>
<body>

<div id="stage">
    <div class="brand">
        FCV5003 · 7&Prime; verticale · {{ $cabinet->name }} ({{ $cabinet->code }}) ·
        <span id="conn"><span class="dot"></span>disconnesso</span>
    </div>

    {{-- Il vetro del tablet: tutto quello che il cliente vede sta qui dentro. --}}
    <div id="kiosk">
        {{-- ⚠️ IL TASTO DEL TECNICO. Piccolo, in un angolo, grigio: non deve invitare nessuno.
             Un cliente che ci finisce sopra per sbaglio trova un PIN, non un menu. --}}
        <button id="btn-settings" title="Impostazioni">⚙</button>

        <div id="screen"></div>
    </div>
</div>

<div id="panel">
    <div class="card">
        <h3>💓 Alimentazione &amp; heartbeat</h3>
        <button class="btn on" id="btn-power">Chiosco ACCESO — heartbeat attivo</button>
        <p class="l-dim" style="font-size:11px;margin:6px 0 0">
            ⚠️ Spegnilo e prova ad aprire un vano: risponderà <b>409</b>. Non è un bug —
            è la difesa contro il rischio #1. Un armadio che non risponde non riceve comandi.
        </p>
    </div>

    <div class="card">
        <h3>📟 Come rispondo ai comandi</h3>
        <button class="btn" id="btn-ackmode">ACK automatico: il vano si apre</button>
        <label style="margin-top:8px">
            <input type="checkbox" id="delay"> ⏱️ Ritarda la risposta di 40s
        </label>
        <p class="l-dim" style="font-size:11px;margin:6px 0 0">
            Il ritardo fa <b>scadere</b> il comando: vedrai il rifiuto per TTL, e il server
            non lo accetterà nemmeno se rispondo dopo.
        </p>
    </div>

    <div class="card">
        <h3>💳 Carta (NFC simulata)</h3>
        <input type="text" id="card" value="CARTA-001">
        <button class="btn" id="btn-tap" style="margin-top:8px">Appoggia la carta</button>
        <p class="l-dim" style="font-size:11px;margin:6px 0 0">
            Il tap fa cose diverse a seconda di cosa sta chiedendo il chiosco:
            <b>paga</b> se è la schermata della carta, <b>identifica</b> se il cliente ha già
            dichiarato "riapri" o "ho finito".<br><br>
            ⚠️ È la carta stessa a fare da scontrino: il token lo restituisce il
            <b>provider di pagamento</b>, non il chiosco.
        </p>
    </div>

    <div class="card">
        <h3>🚪 Sportello</h3>

        {{-- ⚠️ QUALE sportello. Prima i bottoni mandavano sempre il vano della sessione in
             corso, e se non ce n'era una ripiegavano sul vano 1: chiudere il vano 2 era
             semplicemente impossibile, e restava occupato per sempre. Nel mondo fisico lo
             sportello che si richiude lo sceglie il cliente, non il software. --}}
        <select id="door" style="width:100%;padding:8px;border-radius:6px;border:1px solid #2a3244;
                                 background:#0f1115;color:#e6e8eb;margin-bottom:8px">
            @foreach ($cabinet->lockers->sortBy('number') as $vano)
                <option value="{{ $vano->number }}">
                    Vano {{ $vano->number }} — {{ ['free' => 'libero', 'reserved' => 'prenotato', 'occupied' => 'occupato', 'checkout' => 'in riconsegna', 'out_of_service' => 'fuori servizio'][$vano->status] ?? $vano->status }}
                </option>
            @endforeach
        </select>

        <button class="btn" id="btn-closed">Ho richiuso questo sportello</button>
        <button class="btn off" id="btn-lockerr">⚠️ Serratura inceppata</button>
        <p class="l-dim" style="font-size:11px;margin:6px 0 0">
            La chiusura è la <b>conferma vera</b> della riconsegna (🔒 D5): senza sensore, il
            vano si libera solo a timer — un ripiego.
        </p>
    </div>

    <div class="card">
        <h3>Seriale del device</h3>
        <div id="log"></div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
//  L'EMULATORE. Parla al server con lo STESSO contratto del FCV5003.
// ═══════════════════════════════════════════════════════════════════════════════
const CFG = {
    ws:       @json($wsUrl),
    clientId: @json($credentials['mqtt_client_id']),
    secret:   @json($credentials['mqtt_secret']),
    apiToken: @json($apiToken),
    topics:   @json($topics),
    cabinet:  @json($cabinet->code),
    grace:    @json($graceSeconds),
};

const $ = (id) => document.getElementById(id);
const logEl = $('log');

function log(msg, cls = 'l-dim') {
    const t = new Date().toTimeString().slice(0, 8);
    logEl.insertAdjacentHTML('beforeend', `<div class="${cls}">[${t}] ${msg}</div>`);
    logEl.scrollTop = logEl.scrollHeight;
}

// ── Stato del chiosco ─────────────────────────────────────────────────────────
let stato = { schermo: 'home', sessione: null, acceso: true, ackOk: true, ritarda: false,
              posti: null, pollPosti: null, pollPagamento: null, intento: 'reopen',
              sbloccato: false };

// ── HMAC: la stessa firma che calcola il server (CommandSigner) ───────────────
async function firmaValida(cmd) {
    const canonical = [cmd.id, cmd.type, cmd.locker_id ?? '', cmd.expires_at].join('|');
    const key = await crypto.subtle.importKey(
        'raw', new TextEncoder().encode(CFG.secret),
        { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
    );
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(canonical));
    const hex = [...new Uint8Array(sig)].map(b => b.toString(16).padStart(2, '0')).join('');
    return hex === cmd.sig;
}

// ── MQTT ──────────────────────────────────────────────────────────────────────
const client = mqtt.connect(CFG.ws, {
    clientId: CFG.clientId,
    username: CFG.clientId,
    password: CFG.secret,

    // ⚠️ CLEAN SESSION. Senza, il broker accumula i comandi mentre il chiosco è spento e
    // glieli riconsegna tutti alla riaccensione: gli armadietti si aprirebbero da soli, a
    // raffica, alle 4 del mattino.
    clean: true,

    // ⚠️ IL TESTAMENTO (LWT). Se il chiosco muore male — corrente staccata, cavo tirato — il
    // broker pubblica questo al posto suo, e il server sa SUBITO che l'armadio non c'è più.
    // Senza, bisognerebbe aspettare che scada l'heartbeat: novanta secondi in cui il server
    // crederebbe di poter aprire dei vani.
    will: { topic: CFG.topics.status, payload: 'offline', qos: 1, retain: true },
});

client.on('connect', () => {
    $('conn').innerHTML = '<span class="dot up"></span>connesso';
    log('connesso al broker come ' + CFG.clientId, 'l-out');

    client.subscribe(CFG.topics.cmd, { qos: 1 }, (err) => {
        if (err) log('SUBSCRIBE NEGATO dal broker: ' + err.message, 'l-err');
        else log('sottoscritto ' + CFG.topics.cmd, 'l-dim');
    });

    client.publish(CFG.topics.status, 'online', { qos: 1, retain: true });
    heartbeat();
});

client.on('error', (e) => log('errore MQTT: ' + e.message, 'l-err'));

// ── L'ARRIVO DI UN COMANDO: qui vivono le due difese lato device ──────────────
client.on('message', async (topic, buf) => {
    let cmd;
    try { cmd = JSON.parse(buf.toString()); }
    catch { log('payload illeggibile, scartato', 'l-err'); return; }

    log(`← comando ${cmd.type}(${cmd.reason}) vano ${cmd.locker?.number ?? '?'}`, 'l-in');

    // ⚠️ DIFESA A — LA SCADENZA.
    // Il comando porta con sé la propria data di morte. Il device la controlla DA SOLO: se
    // ci fidassimo solo del server, un ritardo di rete basterebbe ad aprire un vano fuori
    // tempo — davanti a nessuno, o davanti a chiunque.
    if (new Date(cmd.expires_at) < new Date()) {
        log(`⛔ SCADUTO (${cmd.expires_at}) — RIFIUTATO`, 'l-err');
        return ack(cmd.id, false, 'command_expired');
    }

    // ⚠️ DIFESA B — LA FIRMA.
    // Il TLS protegge il filo, non il messaggio. Chi riuscisse a pubblicare sul broker senza
    // la chiave produrrebbe comandi che il device scarta.
    if (!await firmaValida(cmd)) {
        log('⛔ FIRMA NON VALIDA — RIFIUTATO', 'l-err');
        return ack(cmd.id, false, 'bad_signature');
    }

    if (stato.ritarda) {
        log('⏱️ ritardo 40s (il comando scadrà)...', 'l-warn');
        await new Promise(r => setTimeout(r, 40000));
        if (new Date(cmd.expires_at) < new Date()) {
            log('⛔ nel frattempo è SCADUTO — RIFIUTATO', 'l-err');
            return ack(cmd.id, false, 'command_expired');
        }
    }

    if (!stato.ackOk) {
        log('⚠️ la serratura non risponde', 'l-err');
        return ack(cmd.id, false, 'lock_error');
    }

    log(`🔓 vano ${cmd.locker?.number} APERTO (board ${cmd.locker?.board}, ch ${cmd.locker?.channel})`, 'l-out');
    ack(cmd.id, true);
    emit({ type: 'locker.opened', locker: cmd.locker?.number });
    render();
});

function emit(payload) {
    client.publish(CFG.topics.evt, JSON.stringify(payload), { qos: 1, retain: false });
    log('→ ' + payload.type, 'l-out');
}

function ack(id, ok, error = null) {
    emit({ type: 'cmd.ack', command_id: id, ok, error });
}

// ── Heartbeat: è ciò che rende l'armadio raggiungibile ────────────────────────
function heartbeat() {
    if (!stato.acceso || !client.connected) return;
    emit({ type: 'heartbeat', fw: '1.0.0-emu', ip: '127.0.0.1' });
}
setInterval(heartbeat, 20000);

// ── API del chiosco (autenticato COME DEVICE, non come persona) ───────────────
async function api(path, body = null) {
    const r = await fetch('/api/v1/kiosk' + path, {
        method: body ? 'POST' : 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + CFG.apiToken,
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    return r.json();
}

async function mockPay(paymentId, ok) {
    await fetch(`/api/v1/mock/payments/${paymentId}/` + (ok ? 'confirm' : 'fail'), {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Authorization': 'Bearer ' + CFG.apiToken },
    });
}

// -- Le schermate del chiosco -------------------------------------------------
//
// ⚠️ E' un TABLET 7" IN VERTICALE, avvitato al centro di un armadio di lamiera. Non e' una
// pagina web su un monitor: e' un pannello alto e stretto, guardato in piedi, spesso al buio
// di un locale, spesso da qualcuno che ha fretta. Un'azione per schermata, testo grosso,
// bottoni larghi quanto lo schermo. Se serve spiegare, si spiega — ma in una frase.
//
// ⚠️ IL FLUSSO:
//   home → metodo → (QR:  paghi sul telefono, lasci l'email, ricevi un CODICE)
//                 → (NFC: appoggi la carta, il provider restituisce un TOKEN)
//                 → vano
//
// L'IDENTITÀ NASCE DAL PAGAMENTO. E per riaprire o riconsegnare: PRIMA si dichiara l'intento,
// POI ci si identifica (§7.1).

/**
 * ⚠️ Il simbolo CONTACTLESS: le onde che tutti riconoscono senza leggere niente.
 *
 * E' il linguaggio che il cliente già conosce dal bancomat. Scrivere "NFC" non significa
 * niente per chi non è del mestiere; questo disegno sì.
 */
/**
 * ⚠️ L'IMMAGINE DEL CONTACTLESS — quella vera, non il disegno.
 *
 * Il file va messo in `public/img/nfc.png` (PNG o SVG, sfondo trasparente, quadrata, almeno
 * 400×400 per non sgranare sul tablet).
 *
 * ⚠️ Se il file NON c'è, si ripiega sul simbolo disegnato: una schermata di pagamento con
 * un'icona rotta è peggio di una senza immagine — il cliente non capisce dove appoggiare la
 * carta, e non appoggia niente.
 */
function immagineNfc() {
    return `
        <img src="/img/nfc.png" alt="Appoggia qui la carta" class="nfc-img rfid--wait"
             onerror="this.outerHTML = rfid(130, 'rfid--wait')">`;
}

/**
 * ⚠️ QUANTI VANI LIBERI, adesso.
 *
 * È l'informazione che il cliente cerca da tre metri di distanza, prima ancora di avvicinarsi:
 * o c'è posto, o se ne va.
 */
function disegnaPosti() {
    const box = $('posti');
    if (!box || !stato.posti) return;

    const liberi = stato.posti.free;
    const pieno = liberi === 0;

    box.innerHTML = `
        <div class="posti ${pieno ? 'posti--pieno' : ''}">
            <div class="posti__n">${liberi}</div>
            <div>
                <div class="posti__txt">${pieno ? 'Nessun vano libero' : (liberi === 1 ? 'vano libero' : 'vani liberi')}</div>
                <div class="posti__tot">su ${stato.posti.total} totali</div>
            </div>
        </div>`;

    const btn = $('btn-request');
    if (btn) {
        btn.disabled = pieno;
        btn.textContent = pieno ? 'Armadio pieno' : 'Prendi un vano';
    }
}

/**
 * ⚠️ IL CONTATORE SI AGGIORNA DA SOLO. Un contatore fermo è un contatore che MENTE.
 *
 * Il chiosco sta acceso tutta la sera davanti a un armadio che si riempie e si svuota senza
 * che nessuno tocchi lo schermo: un altro cliente prende l'ultimo vano, lo staff ne mette uno
 * fuori servizio, qualcuno riconsegna. Se il numero restasse quello del caricamento, il
 * cliente scoprirebbe la bugia solo *dopo* aver premuto il bottone.
 */
function vigilaPosti() {
    clearInterval(stato.pollPosti);

    stato.pollPosti = setInterval(async () => {
        if (stato.schermo !== 'home') { clearInterval(stato.pollPosti); return; }

        const st = await api('/state');
        if (!st || st.free === undefined) return;

        stato.posti = st;
        disegnaPosti();
    }, 3000);
}

function rfid(size = 110, classe = '') {
    return `
        <svg class="rfid ${classe}" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
            <path d="M6.5 5.5a9 9 0 0 1 0 13"/>
            <path d="M10.5 7.5a5.5 5.5 0 0 1 0 9"/>
            <path d="M14.5 9.6a2.4 2.4 0 0 1 0 4.8"/>
            <rect x="2.6" y="3" width="3" height="18" rx="1.2"/>
        </svg>`;
}

async function render() {
    const s = $('screen');

    // ⚠️ Il tasto ⚙ si vede solo quando NON c'è un cliente a metà operazione: interrompere una
    // sessione di pagamento per aprire il menu WiFi non ha senso, e il tasto in un angolo di
    // una schermata di pagamento è un invito a premerlo.
    $('btn-settings').style.display = stato.schermo === 'home' ? 'block' : 'none';

    if (['pin', 'settings'].includes(stato.schermo)) {
        await renderImpostazioni(s);
        return;
    }

    if (stato.schermo === 'home') {
        const st = await api('/state');
        stato.posti = st;

        s.innerHTML = `
            <div>
                <div class="logo">Lock<span>Up</span>World</div>
                <div class="logo__claim">Deposita le tue cose</div>
            </div>

            <div id="posti"></div>

            <div class="row">
                <button class="big-btn" id="btn-request">Prendi un vano</button>
            </div>

            <div class="row" style="margin-top:20px">
                <button class="big-btn gray" id="btn-reopen">🔓 Riapri il mio vano</button>
                <button class="big-btn gray" id="btn-out">🏁 Ho finito</button>
            </div>`;

        disegnaPosti();

        $('btn-request').onclick = () => {
            // ⚠️ Il contatore puo' essere vecchio di un istante: un altro cliente puo' aver preso
            // l'ultimo vano mentre questo leggeva. Il "no" vero lo dice comunque il server, che
            // non ha vani da assegnare — qui si evita solo di far premere un bottone inutile.
            if ((stato.posti?.free ?? 0) === 0) return;
            stato.schermo = 'method';
            render();
        };

        // ⚠️ L'intento si dichiara PRIMA di identificarsi: è l'unica cosa che distingue
        // "torno a prendere il telefono" da "me ne vado".
        $('btn-reopen').onclick = () => { stato.intento = 'reopen';   stato.schermo = 'identify'; render(); };
        $('btn-out').onclick    = () => { stato.intento = 'checkout'; stato.schermo = 'identify'; render(); };

        vigilaPosti();
        return;
    }

    if (stato.schermo === 'method') {
        s.innerHTML = `
            <h1>Come vuoi pagare?</h1>
            <p class="sub">Il modo in cui paghi decide come riaprirai il vano</p>

            <div class="row">
                <button class="big-btn" id="m-qr">
                    📱 Col telefono
                    <div style="font-size:14px;font-weight:500;opacity:.85;margin-top:4px">
                        inquadri un QR · ricevi un codice per email
                    </div>
                </button>

                <button class="big-btn" id="m-nfc">
                    ${rfid(28)} Carta o telefono NFC
                    <div style="font-size:14px;font-weight:500;opacity:.85;margin-top:4px">
                        appoggi qui · la stessa carta riaprirà il vano
                    </div>
                </button>

                <button class="big-btn gray" id="m-back">Indietro</button>
            </div>`;

        $('m-qr').onclick   = () => chiediVano('qr');
        $('m-nfc').onclick  = () => chiediVano('nfc');
        $('m-back').onclick = () => { stato.schermo = 'home'; render(); };
        return;
    }

    if (stato.schermo === 'pay') {
        const p = stato.sessione.payment;
        s.innerHTML = `
            <h1>${(p.amount_cents / 100).toFixed(2).replace('.', ',')} ${p.currency}</h1>
            <p class="sub">Inquadra il QR col telefono: paghi e lasci la tua email</p>

            <div class="qr"><img src="${p.qr_svg}" width="190" height="190" alt="QR"></div>

            <p class="sub" style="font-size:14px">
                Ti manderemo per email il <b>codice</b> con cui riaprire il vano.
            </p>

            <div class="row">
                <a class="big-btn gray" style="text-decoration:none;text-align:center;display:block"
                   href="${p.qr_payload}" target="_blank">Apri la pagina (simula il telefono)</a>

                <button class="big-btn gray" id="annulla-pay">Annulla</button>
            </div>`;

        $('annulla-pay').onclick = annullaPrenotazione;
        attendiPagamento();
        return;
    }

    if (stato.schermo === 'nfc') {
        const p = stato.sessione.payment;
        s.innerHTML = `
            <h1>${(p.amount_cents / 100).toFixed(2).replace('.', ',')} ${p.currency}</h1>
            <p class="sub">Appoggia qui la carta o il telefono</p>

            ${immagineNfc()}

            <div class="avviso">
                ⚠️ Per riaprire il vano dovrai usare
                <u>LA STESSA CARTA O LO STESSO DISPOSITIVO NFC</u>.
                <div style="font-weight:500;margin-top:6px">
                    È il tuo scontrino: senza, non potrai riprenderti la roba da solo.
                </div>
            </div>

            <div class="row">
                <button class="big-btn gray" id="annulla-nfc">Annulla</button>
            </div>`;

        $('annulla-nfc').onclick = annullaPrenotazione;
        return;
    }

    if (stato.schermo === 'open') {
        const qr = stato.sessione.payment_method === 'qr';
        s.innerHTML = `
            <p class="sub" style="margin:0">Il tuo vano è</p>
            <div class="locker-num">${stato.sessione.locker_number}</div>

            ${qr ? '' : rfid(64)}

            <div class="avviso">
                ${qr
                    ? 'Ti abbiamo mandato per email il <u>CODICE A 6 CIFRE</u> per riaprirlo.'
                    : 'Per riaprirlo usa <u>LA STESSA CARTA O LO STESSO DISPOSITIVO NFC</u>.'}
            </div>

            <div class="row">
                <button class="big-btn gray" id="home">Fine</button>
            </div>`;
        $('home').onclick = () => { stato.schermo = 'home'; stato.sessione = null; render(); };
        return;
    }

    if (stato.schermo === 'rifiutata') {
        // ⚠️ Una schermata, non un alert: l'alert lo si chiude senza leggerlo, e il cliente resta
        // convinto che la macchina sia rotta. Qui gli si dice cosa è successo E cosa deve fare.
        s.innerHTML = `
            <h1 style="color:#b91c1c">Carta già in uso</h1>

            <div class="errore">
                <b style="font-size:17px">Con una carta si prende un vano solo.</b><br><br>
                Questa carta sta già tenendo un vano: riconsegnalo prima di prenderne un altro.<br><br>
                <b>Non ti abbiamo addebitato nulla.</b>
            </div>

            <div class="row">
                <button class="big-btn warn" id="r-out">🏁 Riconsegna quel vano</button>
                <button class="big-btn gray" id="r-home">Indietro</button>
            </div>`;
        $('r-out').onclick  = () => { stato.intento = 'checkout'; stato.schermo = 'identify'; stato.sessione = null; render(); };
        $('r-home').onclick = () => { stato.schermo = 'home'; stato.sessione = null; render(); };
        return;
    }

    if (stato.schermo === 'identify') {
        // ⚠️ Il chiosco non sa (e non deve sapere) se quella stringa è un codice o un token di
        // carta: manda una stringa, e il server sa a chi appartiene.
        s.innerHTML = `
            <h1>${stato.intento === 'checkout' ? 'Riconsegna' : 'Riapertura'}</h1>

            ${rfid(84, 'rfid--wait')}

            <p class="sub">
                Appoggia <b>la stessa carta o lo stesso dispositivo NFC</b><br>
                oppure digita il codice ricevuto per email
            </p>

            <input id="codice" inputmode="numeric" maxlength="6" placeholder="000000">

            <div class="row">
                <button class="big-btn" id="ok-code">Conferma codice</button>
                <button class="big-btn gray" id="annulla">Annulla</button>
            </div>`;

        $('ok-code').onclick = () => {
            const c = $('codice').value.trim();
            if (c.length !== 6) { alert('Il codice è di 6 cifre.'); return; }
            emit({ type: 'identity.presented', token: c, intent: stato.intento });
            stato.schermo = 'home';
            render();
        };
        $('annulla').onclick = () => { stato.schermo = 'home'; render(); };
        return;
    }
}


/* ═══════════════════════════════════════════════════════════════════════════════
 *  IL MENU DEL TECNICO
 *
 * ⚠️ QUESTA PASSWORD NON DIFENDE DA UN ATTACCANTE, E NON DEVE FINGERE DI FARLO.
 *
 * Sta nel `localStorage` del device, e chi apre gli strumenti di sviluppo — o smonta il
 * FCV5003 — la aggira in dieci secondi. Il modello di minaccia qui è un altro, ed è quello
 * vero: **il cliente ubriaco che smanetta sul touchscreen** mentre aspetta, e il curioso in
 * fila. Da quelli difende benissimo.
 *
 * Ciò che protegge davvero l'armadio — le credenziali MQTT, la firma dei comandi, la revoca
 * di un chiosco rubato — vive sul SERVER, e da qui non si tocca. Se un giorno da questo menu
 * si potesse cambiare l'identità del device, allora sì che questa password diventerebbe una
 * bugia pericolosa: perché sembrerebbe proteggere qualcosa che invece è a portata di
 * chiunque abbia un cacciavite.
 *
 * ⚠️ Nel localStorage finisce lo SHA-256, non il PIN. Non è "sicurezza" (vedi sopra): è che
 * un PIN in chiaro lo legge anche chi dà un'occhiata distratta allo schermo di un tecnico che
 * ha gli strumenti aperti. Costa zero, evita una figuraccia.
 * ═══════════════════════════════════════════════════════════════════════════════ */

const PIN_KEY = 'lockerfe.pin';
const CONF_KEY = 'lockerfe.conf';

async function sha256(txt) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(txt));
    return [...new Uint8Array(buf)].map(b => b.toString(16).padStart(2, '0')).join('');
}

/** La configurazione locale del device. Nel lockerfe vero: WiFi, luminosità, volume… */
function conf() {
    try { return JSON.parse(localStorage.getItem(CONF_KEY)) ?? {}; } catch { return {}; }
}

function salvaConf(c) {
    localStorage.setItem(CONF_KEY, JSON.stringify(c));
}

/**
 * ⚠️ Uscire dal menu RICHIUDE la porta.
 *
 * Un tecnico che se ne va lasciando il menu aperto è la norma, non l'eccezione: ha finito,
 * gli squilla il telefono, e va via. Se il menu restasse sbloccato, il primo cliente della
 * sera si troverebbe davanti la configurazione WiFi dell'armadio.
 */
function chiudiImpostazioni() {
    stato.sbloccato = false;
    stato.schermo = 'home';
    render();
}

async function apriImpostazioni() {
    stato.schermo = 'pin';
    render();
}

async function renderImpostazioni(s) {
    // ── 1. la porta: il PIN ───────────────────────────────────────────────────
    if (stato.schermo === 'pin') {
        const impostato = localStorage.getItem(PIN_KEY) !== null;

        s.innerHTML = `
            <h1>${impostato ? 'Impostazioni' : 'Primo avvio'}</h1>
            <p class="sub">
                ${impostato
                    ? 'Riservato al tecnico. Inserisci il PIN.'
                    : 'Non c\'è ancora un PIN su questo chiosco: scegline uno adesso.'}
            </p>

            <input id="pin" type="password" maxlength="64" autocomplete="off"
                   placeholder="almeno 12 caratteri">

            <div class="row">
                <button class="big-btn" id="pin-ok">${impostato ? 'Entra' : 'Imposta la password'}</button>
                <button class="big-btn gray" id="pin-annulla">Annulla</button>
            </div>`;

        $('pin-annulla').onclick = () => { stato.schermo = 'home'; render(); };

        $('pin-ok').onclick = async () => {
            const pin = $('pin').value.trim();

            /*
             * ⚠️ ALMENO 12 CARATTERI, e non e' pignoleria.
             *
             * Un PIN di 4 cifre sono DIECIMILA combinazioni: davanti a quello schermo, senza
             * nessun limite di tentativi, si provano tutte in un pomeriggio. E qui non c'e' un
             * rate limit del server a proteggerci — il controllo avviene sul device, in locale:
             * chi ci prova non parla con nessuno, prova e basta.
             *
             * Lunghezza, quindi. E' l'unica difesa che questo posto puo' avere davvero.
             */
            if (pin.length < 12) { alert('Servono almeno 12 caratteri.'); return; }

            // ⚠️ Primo avvio: il PIN lo sceglie il tecnico che monta l'armadio. Un PIN di
            // fabbrica uguale per tutti i chioschi sarebbe un PIN che, il giorno che trapela,
            // apre il menu di TUTTI gli armadi installati.
            if (!impostato) {
                localStorage.setItem(PIN_KEY, await sha256(pin));
                log('password del chiosco impostata', 'l-warn');
                stato.sbloccato = true;
                stato.schermo = 'settings';
                render();
                return;
            }

            if (await sha256(pin) !== localStorage.getItem(PIN_KEY)) {
                log('password sbagliata', 'l-err');
                alert('Password sbagliata.');
                return;
            }

            stato.sbloccato = true;
            stato.schermo = 'settings';
            render();
        };
        return;
    }

    // ── 2. il menu vero ───────────────────────────────────────────────────────
    if (stato.schermo === 'settings') {
        // ⚠️ Non ci si arriva senza essere passati dal PIN, nemmeno cambiando `stato` a mano
        // dalla console: se qualcuno lo fa, non e' piu' un problema di interfaccia.
        if (!stato.sbloccato) { stato.schermo = 'pin'; render(); return; }

        const c = conf();

        s.innerHTML = `
            <h1>Impostazioni</h1>
            <p class="sub" style="font-size:14px">
                Configurazione locale del chiosco. Nel <b>lockerfe</b> vero questi campi
                pilotano il modulo WiFi e il display.
            </p>

            <div class="tec">
                <div>
                    <label for="ssid">Rete WiFi (SSID)</label>
                    <input id="ssid" type="text" value="${c.ssid ?? ''}" placeholder="LOCALE-WIFI">
                </div>

                <div>
                    <label for="wpa">Password WiFi</label>
                    <input id="wpa" type="password" value="${c.wpa ?? ''}" placeholder="••••••••">
                </div>

                <div>
                    <label for="srv">Indirizzo del server</label>
                    <input id="srv" type="text" value="${c.srv ?? location.origin}">
                </div>

                <div>
                    <label for="lum">Luminosità</label>
                    <select id="lum">
                        <option value="40"  ${c.lum == 40  ? 'selected' : ''}>40% — locale buio</option>
                        <option value="70"  ${c.lum == 70  ? 'selected' : ''}>70%</option>
                        <option value="100" ${(c.lum ?? 100) == 100 ? 'selected' : ''}>100% — pieno sole</option>
                    </select>
                </div>

                {{-- ⚠️ SOLA LETTURA, e non e' una dimenticanza. L'identita' del chiosco e le sue
                     credenziali MQTT arrivano DAL SERVER e non si toccano da qui: un menu che
                     potesse cambiarle sarebbe un menu che, con un cacciavite, si prende
                     l'armadio di qualcun altro. Se un chiosco va rifatto, il tecnico preme
                     "Attiva" sul pannello — un gesto solo, e tracciato. --}}
                <div style="margin-top:6px">
                    <label>Identità (dal server — non modificabile)</label>
                    <div class="riga"><b>Armadio</b><span>{{ $cabinet->code }}</span></div>
                    <div class="riga"><b>Seriale</b><span>{{ $device->serial }}</span></div>
                    <div class="riga"><b>Client MQTT</b><span>${CFG.clientId}</span></div>
                </div>
            </div>

            <div class="row">
                <button class="big-btn" id="set-salva">Salva</button>
                <button class="big-btn gray" id="set-pin">Cambia password</button>
                <button class="big-btn gray" id="set-esci">Esci</button>
            </div>`;

        $('set-salva').onclick = () => {
            salvaConf({
                ssid: $('ssid').value.trim(),
                wpa: $('wpa').value,
                srv: $('srv').value.trim(),
                lum: $('lum').value,
            });
            log('impostazioni salvate nel device', 'l-out');
            chiudiImpostazioni();
        };

        $('set-pin').onclick = () => {
            localStorage.removeItem(PIN_KEY);
            stato.sbloccato = false;
            stato.schermo = 'pin';
            render();
        };

        // ⚠️ Uscire richiude la porta: vedi chiudiImpostazioni().
        $('set-esci').onclick = chiudiImpostazioni;
        return;
    }
}

/**
 * ⚠️ IL CLIENTE HA CAMBIATO IDEA. Il vano torna libero SUBITO.
 *
 * Non è cortesia: è INVENTARIO. Senza questo bottone, chi si ferma davanti alla schermata di
 * pagamento e ci ripensa lascia il vano bloccato per tutta la durata della prenotazione — e in
 * una serata di punta bastano pochi ripensamenti per far risultare pieno un armadio mezzo
 * vuoto. Il cliente dopo se ne va, e nessuno sa perché.
 */
async function annullaPrenotazione() {
    clearInterval(stato.pollPagamento);

    if (stato.sessione) {
        const r = await api('/sessions/' + stato.sessione.session_id + '/cancel', {});
        if (r?.status === 'cancelled') log('prenotazione annullata: il vano è di nuovo libero', 'l-warn');
    }

    stato.sessione = null;
    stato.schermo = 'home';
    render();
}

async function chiediVano(metodo) {
    const r = await api('/sessions', { method: metodo });
    if (r.error) { alert(r.error.message); return; }
    stato.sessione = r;
    stato.schermo = metodo === 'nfc' ? 'nfc' : 'pay';
    render();
}

/**
 * ⚠️ IL CHIOSCO CHIEDE AL SERVER COM'È FINITA. Non lo sa da solo.
 *
 * Il pagamento QR avviene su un ALTRO dispositivo (il telefono del cliente); il rifiuto della
 * carta lo decide il server. In entrambi i casi il chiosco deve chiedere.
 *
 * ⚠️ Chiede alla rotta del CHIOSCO (`/kiosk/sessions/{id}`), non a quella pubblica del cliente.
 * Prima usava `/public/sessions/{token}`, che ha un rate limit STRETTO — 10 al minuto, perché
 * quel token è l'unica cosa che separa un estraneo dal cappotto di qualcun altro. Il chiosco ne
 * faceva 30 al minuto: dopo venti secondi scattava il 429, il fetch riceveva un corpo d'errore
 * invece dello stato, e il chiosco RESTAVA MUTO PER SEMPRE. Il cliente vedeva la schermata di
 * pagamento e nient'altro — che è esattamente il bug segnalato.
 */
function attendiPagamento() {
    clearInterval(stato.pollPagamento);

    stato.pollPagamento = setInterval(async () => {
        if (!['pay', 'nfc'].includes(stato.schermo) || !stato.sessione) {
            clearInterval(stato.pollPagamento);
            return;
        }

        const r = await api('/sessions/' + stato.sessione.session_id);

        // ⚠️ Se la chiamata fallisce non si resta muti: lo si dice, almeno al log seriale.
        if (!r || !r.status) { log('non riesco a chiedere lo stato al server', 'l-err'); return; }

        if (r.status === 'active') {
            clearInterval(stato.pollPagamento);
            log('pagamento confermato: identità creata', 'l-in');
            stato.schermo = 'open';
            render();
            return;
        }

        // ⚠️ Il server ha RIFIUTATO, e non ha incassato. Il caso vero: questa carta tiene già un
        // vano. Il cliente deve capirlo — restare sulla schermata di pagamento è il modo più
        // veloce di farlo sentire davanti a una macchina rotta.
        if (r.status === 'cancelled') {
            clearInterval(stato.pollPagamento);
            log('pagamento RIFIUTATO: questa carta tiene già un vano', 'l-warn');
            stato.schermo = 'rifiutata';
            render();
        }
    }, 2000);
}

// ── I bottoni del pannello hardware ───────────────────────────────────────────
$('btn-power').onclick = (e) => {
    stato.acceso = !stato.acceso;
    e.target.className = 'btn ' + (stato.acceso ? 'on' : 'off');
    e.target.textContent = stato.acceso ? 'Chiosco ACCESO — heartbeat attivo' : 'Chiosco SPENTO — nessun heartbeat';
    if (stato.acceso) { client.publish(CFG.topics.status, 'online', { qos: 1, retain: true }); heartbeat(); }
    else { client.publish(CFG.topics.status, 'offline', { qos: 1, retain: true }); log('spento: il server lo saprà subito (LWT)', 'l-warn'); }
};

$('btn-ackmode').onclick = (e) => {
    stato.ackOk = !stato.ackOk;
    e.target.className = 'btn ' + (stato.ackOk ? '' : 'off');
    e.target.textContent = stato.ackOk ? 'ACK automatico: il vano si apre' : 'NACK: la serratura non risponde';
};

$('delay').onchange = (e) => { stato.ritarda = e.target.checked; };

/*
 * ⚠️ IL TAP DELLA CARTA fa due cose diverse, e dipende da cosa sta chiedendo il chiosco.
 *
 *   schermata "carta"  → è un PAGAMENTO. Il chiosco presenta la carta; il token e l'esito li
 *                        dà il PROVIDER, non il chiosco. È così che un chiosco compromesso non
 *                        può dichiarare "questa carta ha pagato" e regalarsi i vani.
 *
 *   dopo "riapri"/"ho finito" → è un'IDENTIFICAZIONE. La stessa carta produce lo stesso token,
 *                        e quel token è già legato alla sessione: nasce dal pagamento.
 */
function appoggiaCarta() {
    const carta = $('card').value;

    if (stato.schermo === 'nfc') {
        emit({
            type: 'payment.card',
            session_id: stato.sessione.session_id,
            card_token: carta,
        });

        log('carta presentata: aspetto la conferma del provider', 'l-out');

        // Il chiosco non decide da solo che il pagamento è andato: aspetta il server.
        attendiPagamento();
        return;
    }

    if (stato.schermo === 'identify') {
        emit({ type: 'identity.presented', token: carta, intent: stato.intento });
        stato.schermo = 'home';
        render();
        return;
    }

    log('nessuno sta chiedendo la carta adesso', 'l-warn');
}

$('btn-settings').onclick = apriImpostazioni;

$('btn-tap').onclick = appoggiaCarta;

// ⚠️ Lo sportello lo sceglie chi lo richiude, non il software. Prima questi due bottoni
// mandavano il vano della sessione in corso, e senza sessione ripiegavano sul vano 1:
// chiudere il vano 2 era impossibile, e restava occupato per sempre.
const sportello = () => parseInt($('door').value, 10);

$('btn-closed').onclick  = () => emit({ type: 'locker.closed', locker: sportello() });
$('btn-lockerr').onclick = () => emit({ type: 'locker.error', locker: sportello(), error: 'jammed' });

render();
</script>
</body>
</html>
