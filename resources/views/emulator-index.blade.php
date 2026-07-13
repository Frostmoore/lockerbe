{{--
    L'elenco degli armadi accesi.

    ⚠️ Doppio cancello (MockPanel): questa pagina non esiste in produzione. Elenca gli armadi di
    TUTTI i clienti, cosa che nessuna pagina vera deve poter fare mai.
--}}
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Emulatore — armadi</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 3rem 1.5rem;
            font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
            background: #0f1115; color: #e7e9ee;
        }
        .wrap { max-width: 900px; margin: 0 auto; }
        h1 { margin: 0 0 .25rem; font-size: 1.6rem; }
        .sub { color: #8b93a7; margin: 0 0 2rem; font-size: .95rem; line-height: 1.5; }
        .card {
            display: flex; align-items: center; gap: 1.25rem;
            padding: 1.1rem 1.25rem; margin-bottom: .75rem;
            background: #171a21; border: 1px solid #262b36; border-radius: 12px;
            color: inherit; text-decoration: none;
            transition: border-color .12s, transform .12s;
        }
        .card:hover { border-color: #4c7fff; transform: translateY(-1px); }
        .dot { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 auto; }
        .on  { background: #35d07f; box-shadow: 0 0 10px #35d07f88; }
        .off { background: #4a5163; }
        .main { flex: 1; min-width: 0; }
        .code { font-weight: 600; font-size: 1.05rem; }
        .meta { color: #8b93a7; font-size: .85rem; margin-top: .2rem; }
        .mono { font-family: ui-monospace, "Cascadia Code", Consolas, monospace; font-size: .78rem; color: #6f7789; }
        .go { color: #4c7fff; font-size: .9rem; white-space: nowrap; }
        .empty {
            padding: 2rem; text-align: center; color: #8b93a7;
            background: #171a21; border: 1px dashed #2f3542; border-radius: 12px; line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>🖥️ Emulatore del chiosco</h1>
    <p class="sub">
        Ogni armadio qui sotto ha un FCV5003 avvitato al centro. Aprine uno: la pagina
        <strong>è</strong> quel chiosco — si connette al broker con le sue credenziali, verifica la
        firma dei comandi e rifiuta quelli scaduti, esattamente come farà il device vero.
    </p>

    @forelse ($cabinets as $cabinet)
        @php $online = $cabinet->status === 'online'; @endphp
        <a class="card" href="/emulator/{{ $cabinet->id }}">
            <span class="dot {{ $online ? 'on' : 'off' }}" title="{{ $cabinet->status }}"></span>
            <span class="main">
                <span class="code">{{ $cabinet->code }}</span>
                <span class="meta">
                    {{ $cabinet->tenant?->name ?? '—' }} ·
                    {{ $cabinet->lockers->count() }} vani ·
                    chiosco <span class="mono">{{ $cabinet->device?->serial }}</span>
                    ({{ $cabinet->device?->status }})
                </span>
            </span>
            <span class="go">apri →</span>
        </a>
    @empty
        <div class="empty">
            <strong>Nessun armadio con un chiosco.</strong><br>
            Registra un dispositivo, crea l'armadio, associali e premi <em>Attiva</em>.
        </div>
    @endforelse
</div>
</body>
</html>
