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
    <title>Emulatore FCV5003 — {{ $cabinet->code }}</title>
    <script src="/js/mqtt.min.js"></script>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
               background: #0f1115; color: #e6e8eb; display: grid;
               grid-template-columns: 1fr 380px; height: 100vh; }
        @media (max-width: 900px) { body { grid-template-columns: 1fr; height: auto; } }

        /* ── IL CHIOSCO: quello che vede il cliente ── */
        #kiosk { display: flex; flex-direction: column; align-items: center; justify-content: center;
                 padding: 32px; text-align: center; }
        .brand { position: absolute; top: 20px; left: 24px; font-size: 13px; color: #6b7280; }
        h1 { font-size: 32px; margin: 0 0 8px; }
        .sub { color: #9ca3af; margin-bottom: 28px; }
        .big-btn { font-size: 22px; padding: 22px 44px; border: 0; border-radius: 14px;
                   background: #2563eb; color: #fff; cursor: pointer; font-weight: 600; }
        .big-btn:hover { background: #1d4ed8; }
        .big-btn.green { background: #16a34a; } .big-btn.green:hover { background: #15803d; }
        .big-btn.gray  { background: #374151; } .big-btn.gray:hover  { background: #4b5563; }
        .big-btn:disabled { opacity: .4; cursor: not-allowed; }
        .qr { background: #fff; padding: 16px; border-radius: 12px; margin: 20px auto; }
        .locker-num { font-size: 84px; font-weight: 800; color: #22c55e; line-height: 1; margin: 12px 0; }
        .row { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 18px; }
        .free { color: #9ca3af; margin-top: 26px; font-size: 14px; }

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

<div id="kiosk">
    <div class="brand">FCV5003 · {{ $cabinet->name }} ({{ $cabinet->code }}) · <span id="conn"><span class="dot"></span>disconnesso</span></div>
    <div id="screen"></div>
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
        <button class="btn" id="btn-closed">Ho richiuso lo sportello</button>
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
let stato = { schermo: 'home', sessione: null, acceso: true, ackOk: true, ritarda: false };

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
// ⚠️ IL FLUSSO, e il perché è fatto così:
//
//   home → metodo → (QR:  paghi sul telefono, lasci l'email, ricevi un CODICE)
//                 → (NFC: appoggi la carta, il provider restituisce un TOKEN)
//                 → vano
//
// L'IDENTITÀ NASCE DAL PAGAMENTO. Prima non era così: il cliente pagava col QR e non
// riceveva niente con cui riaprire — poi premeva "ho finito" e non succedeva nulla.
//
// E per riaprire o riconsegnare: PRIMA si dichiara l'intento, POI ci si identifica (§7.1).

async function render() {
    const s = $('screen');

    if (stato.schermo === 'home') {
        const st = await api('/state');
        s.innerHTML = `
            <h1>Guardaroba</h1>
            <div class="sub">Deposita il tuo cappotto in sicurezza</div>
            <button class="big-btn" id="btn-request" ${st.free === 0 ? 'disabled' : ''}>
                ${st.free === 0 ? 'Armadio pieno' : 'Prendi un vano'}
            </button>
            <div class="free">${st.free} vani liberi su ${st.total}</div>
            <div class="row" style="margin-top:18px">
                <button class="big-btn gray" id="btn-reopen">🔓 Riapri il mio vano</button>
                <button class="big-btn gray" id="btn-out">🏁 Ho finito</button>
            </div>`;
        $('btn-request')?.addEventListener('click', () => { stato.schermo = 'method'; render(); });

        // ⚠️ L'intento si dichiara PRIMA di identificarsi: è l'unica cosa che distingue
        // "torno a prendere il telefono" da "me ne vado".
        $('btn-reopen').onclick = () => { stato.intento = 'reopen';   stato.schermo = 'identify'; render(); };
        $('btn-out').onclick    = () => { stato.intento = 'checkout'; stato.schermo = 'identify'; render(); };
        return;
    }

    if (stato.schermo === 'method') {
        s.innerHTML = `
            <h1>Come vuoi pagare?</h1>
            <div class="sub">Il modo in cui paghi decide come riaprirai il vano</div>
            <div class="row" style="flex-direction:column;gap:10px">
                <button class="big-btn" id="m-qr">📱 QR — pago col telefono<br>
                    <small style="font-weight:400;opacity:.8">ricevi un codice per email</small></button>
                <button class="big-btn" id="m-nfc">💳 Carta — pago qui<br>
                    <small style="font-weight:400;opacity:.8">la carta stessa riaprirà il vano</small></button>
            </div>`;
        $('m-qr').onclick  = () => chiediVano('qr');
        $('m-nfc').onclick = () => chiediVano('nfc');
        return;
    }

    if (stato.schermo === 'pay') {
        const p = stato.sessione.payment;
        s.innerHTML = `
            <h1>${(p.amount_cents / 100).toFixed(2)} ${p.currency}</h1>
            <div class="sub">Inquadra il QR: si apre una pagina dove paghi e lasci l'email</div>
            <div class="qr"><img src="${p.qr_svg}" width="200" height="200" alt="QR"></div>
            <div class="sub" style="font-size:12px;opacity:.7">
                ⚠️ L'email la chiede la pagina, non il chiosco: digitarla su un touchscreen
                al buio è un modo affidabile di sbagliarla.
            </div>
            <a class="big-btn green" style="display:block;text-decoration:none;text-align:center"
               href="${p.qr_payload}" target="_blank">Apri la pagina di pagamento (simula il telefono)</a>`;
        attendiPagamento();
        return;
    }

    if (stato.schermo === 'nfc') {
        const p = stato.sessione.payment;
        s.innerHTML = `
            <h1>${(p.amount_cents / 100).toFixed(2)} ${p.currency}</h1>
            <div class="sub">Appoggia la carta per pagare.<br>
            Sarà anche il tuo scontrino: la stessa carta riaprirà il vano.</div>
            <div class="qr" style="font-size:64px">💳</div>
            <div class="sub" style="font-size:12px;opacity:.7">
                Usa il tap del pannello hardware, qui a destra.
            </div>`;
        return;
    }

    if (stato.schermo === 'open') {
        const qr = stato.sessione.payment_method === 'qr';
        s.innerHTML = `
            <div class="sub">Il tuo vano è</div>
            <div class="locker-num">${stato.sessione.locker_number}</div>
            <div class="sub">${qr
                ? 'Ti abbiamo mandato per email il <b>codice a 6 cifre</b> per riaprirlo.'
                : 'Riappoggia <b>la stessa carta</b> per riaprirlo o riconsegnarlo.'}</div>
            <button class="big-btn gray" id="home">Fine</button>`;
        $('home').onclick = () => { stato.schermo = 'home'; stato.sessione = null; render(); };
        return;
    }

    if (stato.schermo === 'identify') {
        // ⚠️ Il chiosco non sa (e non deve sapere) se quella stringa è un codice o un token di
        // carta: manda una stringa, e il server sa a chi appartiene.
        s.innerHTML = `
            <h1>${stato.intento === 'checkout' ? 'Riconsegna' : 'Riapertura'}</h1>
            <div class="sub">Appoggia la carta, oppure digita il codice ricevuto per email</div>
            <input id="codice" inputmode="numeric" maxlength="6" placeholder="000000"
                   style="width:220px;padding:14px;font-size:32px;text-align:center;letter-spacing:8px;
                          font-family:ui-monospace,monospace;border-radius:12px;border:1px solid #2f3542;
                          background:#0f1115;color:#e7e9ee;margin:12px 0">
            <div class="row">
                <button class="big-btn green" id="ok-code">Conferma codice</button>
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

async function chiediVano(metodo) {
    const r = await api('/sessions', { method: metodo });
    if (r.error) { alert(r.error.message); return; }
    stato.sessione = r;
    stato.schermo = metodo === 'nfc' ? 'nfc' : 'pay';
    render();
}

/**
 * ⚠️ Il chiosco NON sa quando il cliente ha pagato sul telefono: glielo deve chiedere al
 * server. Il pagamento avviene su un altro dispositivo, in un'altra sessione — l'unica cosa
 * che il chiosco possiede è il token pubblico.
 */
function attendiPagamento() {
    clearInterval(stato.pollPagamento);

    stato.pollPagamento = setInterval(async () => {
        if (!['pay', 'nfc'].includes(stato.schermo) || !stato.sessione) { clearInterval(stato.pollPagamento); return; }

        const r = await fetch('/api/v1/public/sessions/' + stato.sessione.public_token, {
            headers: { 'Accept': 'application/json' },
        }).then(x => x.json()).catch(() => null);

        if (r?.data?.status === 'active') {
            clearInterval(stato.pollPagamento);
            log('pagamento confermato: identita' creata', 'l-in');
            stato.schermo = 'open';
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

$('btn-tap').onclick = appoggiaCarta;

$('btn-closed').onclick  = () => emit({ type: 'locker.closed', locker: stato.sessione?.locker_number ?? 1 });
$('btn-lockerr').onclick = () => emit({ type: 'locker.error', locker: stato.sessione?.locker_number ?? 1, error: 'jammed' });

render();
</script>
</body>
</html>
