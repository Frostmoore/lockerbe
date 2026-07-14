{{--
    LA HOME PUBBLICA.

    ⚠️ È l'unica pagina di questo sistema che vede **chi non è nessuno**: non un gestore, non un
    tecnico, non un cliente con un token. Un visitatore qualunque.

    ⚠️ Non contiene NIENTE che non sia pubblico: nessun conteggio di armadi, nessun nome di
    locale, nessuno stato di sistema. Sembra ovvio, e infatti è il modo in cui queste pagine
    perdono informazioni: si aggiunge "giusto il numero di locali attivi" per far vedere che il
    prodotto è vivo, e si è appena detto a un estraneo quanti clienti abbiamo.

    Autoconsistente: niente asset esterni, niente build. È una pagina che deve funzionare anche
    il giorno in cui il resto è in manutenzione.
--}}
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LockUpWorld — deposita le tue cose</title>
    <meta name="description" content="Armadietti intelligenti per locali, teatri, palestre e spazi eventi. Il cliente deposita, paga, e riapre con la propria carta.">

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --blu:    #1b2a4a;
            --blu-2:  #26395f;
            --acqua:  #35d0a5;
            --carta:  #ffffff;
            --fondo:  #f4f6f9;
            --testo:  #12151b;
            --tenue:  #5b6472;
            --bordo:  #e3e7ee;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --carta: #171a21;
                --fondo: #0f1115;
                --testo: #e7e9ee;
                --tenue: #8b93a7;
                --bordo: #262b36;
            }
        }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: -apple-system, "Segoe UI", system-ui, Roboto, sans-serif;
            background: var(--fondo);
            color: var(--testo);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .contenitore { width: 100%; max-width: 1040px; margin: 0 auto; padding: 0 1.5rem; }

        /* ── Barra ─────────────────────────────────────────────────────────── */
        header {
            position: sticky; top: 0; z-index: 10;
            background: color-mix(in srgb, var(--fondo) 88%, transparent);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--bordo);
        }
        .barra {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; padding: 0.9rem 0;
        }
        .marchio { display: flex; align-items: center; gap: 0.6rem; font-weight: 700; letter-spacing: -0.01em; }
        .marchio svg { width: 26px; height: 26px; flex: 0 0 auto; }

        .btn {
            display: inline-block;
            padding: 0.62rem 1.15rem;
            border-radius: 10px;
            font-size: 0.92rem; font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
            white-space: nowrap;
            transition: background .12s, border-color .12s, transform .12s;
        }
        .btn--pieno { background: var(--blu); color: #fff; }
        .btn--pieno:hover { background: var(--blu-2); transform: translateY(-1px); }
        .btn--vuoto { border-color: var(--bordo); color: var(--testo); }
        .btn--vuoto:hover { border-color: var(--blu); }

        @media (prefers-color-scheme: dark) {
            .btn--pieno { background: var(--acqua); color: #06140f; }
            .btn--pieno:hover { background: #4ee0b8; }
        }

        /* ── Apertura ──────────────────────────────────────────────────────── */
        .apertura { padding: 5rem 0 4rem; text-align: center; }
        .occhiello {
            display: inline-block;
            padding: 0.3rem 0.8rem; margin-bottom: 1.5rem;
            border: 1px solid var(--bordo); border-radius: 999px;
            background: var(--carta);
            font-size: 0.78rem; font-weight: 600; color: var(--tenue);
            letter-spacing: 0.02em;
        }
        h1 {
            margin: 0 0 1rem;
            font-size: clamp(2.1rem, 6vw, 3.4rem);
            line-height: 1.12;
            letter-spacing: -0.03em;
            font-weight: 800;
        }
        h1 em { font-style: normal; color: var(--blu); }
        @media (prefers-color-scheme: dark) { h1 em { color: var(--acqua); } }

        .sottotitolo {
            max-width: 44ch; margin: 0 auto 2rem;
            font-size: clamp(1rem, 2.2vw, 1.15rem);
            color: var(--tenue);
        }
        .azioni { display: flex; gap: 0.7rem; justify-content: center; flex-wrap: wrap; }

        /* ── Come funziona ─────────────────────────────────────────────────── */
        section { padding: 3.5rem 0; }
        h2 {
            margin: 0 0 0.5rem;
            font-size: clamp(1.4rem, 3.4vw, 1.9rem);
            letter-spacing: -0.02em;
        }
        .intro { margin: 0 0 2.2rem; color: var(--tenue); max-width: 56ch; }

        .passi { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .passo {
            background: var(--carta);
            border: 1px solid var(--bordo);
            border-radius: 14px;
            padding: 1.4rem;
        }
        .numero {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; margin-bottom: 0.8rem;
            border-radius: 9px;
            background: var(--blu); color: #fff;
            font-size: 0.85rem; font-weight: 700;
        }
        @media (prefers-color-scheme: dark) { .numero { background: var(--acqua); color: #06140f; } }
        .passo h3 { margin: 0 0 0.35rem; font-size: 1rem; }
        .passo p { margin: 0; font-size: 0.9rem; color: var(--tenue); }

        /* ── Cosa c'è dentro ───────────────────────────────────────────────── */
        .fatti { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .fatto {
            background: var(--carta);
            border: 1px solid var(--bordo);
            border-left: 3px solid var(--blu);
            border-radius: 12px;
            padding: 1.2rem 1.3rem;
        }
        @media (prefers-color-scheme: dark) { .fatto { border-left-color: var(--acqua); } }
        .fatto h3 { margin: 0 0 0.3rem; font-size: 0.95rem; }
        .fatto p { margin: 0; font-size: 0.88rem; color: var(--tenue); }

        /* ── Chiusura ──────────────────────────────────────────────────────── */
        .chiusura {
            margin: 1rem 0 4rem;
            padding: 2.6rem 1.5rem;
            background: var(--blu);
            border-radius: 18px;
            text-align: center;
            color: #fff;
        }
        .chiusura h2 { margin: 0 0 0.5rem; color: #fff; }
        .chiusura p { margin: 0 0 1.5rem; color: #b9c4d8; }
        .chiusura .btn--pieno { background: #fff; color: var(--blu); }
        .chiusura .btn--pieno:hover { background: #eef1f6; }

        footer {
            border-top: 1px solid var(--bordo);
            padding: 1.6rem 0;
            font-size: 0.85rem; color: var(--tenue);
            display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
        }
        footer a { color: inherit; }
    </style>
</head>
<body>

<header>
    <div class="contenitore barra">
        <span class="marchio">
            {{-- Un lucchetto. Disegnato, non caricato: un'icona che non arriva è un marchio rotto. --}}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            LockUpWorld
        </span>

        {{-- ⚠️ Chi è già entrato va mandato al SUO pannello: i due si respingono a vicenda, e
             offrirgli il link sbagliato vorrebbe dire offrirgli un rimbalzo. --}}
        @auth
            <a class="btn btn--pieno" href="{{ auth()->user()->isPlatformAdmin() ? '/admin' : '/app' }}">
                Vai al pannello
            </a>
        @else
            <a class="btn btn--pieno" href="/app">Accedi</a>
        @endauth
    </div>
</header>

<main>
    <div class="contenitore apertura">
        <span class="occhiello">Armadietti intelligenti</span>

        <h1>Il guardaroba<br>che <em>si gestisce da solo</em></h1>

        <p class="sottotitolo">
            Il cliente sceglie un vano, paga al chiosco o dal telefono, e riapre con la stessa
            carta. Nessuna fila, nessuno scontrino, nessun addetto.
        </p>

        <div class="azioni">
            <a class="btn btn--pieno" href="#come">Come funziona</a>
            <a class="btn btn--vuoto" href="mailto:info@lockupworld.com">Parlane con noi</a>
        </div>
    </div>

    <section id="come">
        <div class="contenitore">
            <h2>Tre gesti, e il cappotto è al sicuro</h2>
            <p class="intro">
                Dal lato del cliente non c'è niente da imparare. Dal lato del locale, non c'è
                niente da presidiare.
            </p>

            <div class="passi">
                <div class="passo">
                    <span class="numero">1</span>
                    <h3>Sceglie</h3>
                    <p>Il chiosco mostra quanti vani sono liberi, in tempo reale. Il cliente ne prende uno.</p>
                </div>
                <div class="passo">
                    <span class="numero">2</span>
                    <h3>Paga</h3>
                    <p>Avvicina la carta al lettore, oppure inquadra un QR e paga dal telefono. Lo sportello si apre.</p>
                </div>
                <div class="passo">
                    <span class="numero">3</span>
                    <h3>Riapre</h3>
                    <p>Quando torna, riavvicina <strong>la stessa carta</strong>. Se ha pagato col QR, gli basta il codice ricevuto via email.</p>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="contenitore">
            <h2>Quello che non si vede</h2>
            <p class="intro">
                Un armadietto che si apre per sbaglio è peggio di un armadietto che non si apre.
                Il sistema è costruito attorno a questa idea.
            </p>

            <div class="fatti">
                <div class="fatto">
                    <h3>Ogni apertura ha un mandante</h3>
                    <p>Chi ha aperto quel vano, e quando. Uno sportello che si apre senza un ordine dietro compare nel registro come <em>aperto a mano</em>.</p>
                </div>
                <div class="fatto">
                    <h3>Un armadio spento non promette niente</h3>
                    <p>Se il chiosco non risponde, l'apertura viene <strong>rifiutata subito</strong>. Nessun comando resta in coda ad aprirsi da solo, tre ore dopo.</p>
                </div>
                <div class="fatto">
                    <h3>Il registro non si riscrive</h3>
                    <p>Le voci sono concatenate e il database stesso rifiuta modifiche e cancellazioni. Nemmeno noi possiamo cambiare quello che è successo.</p>
                </div>
                <div class="fatto">
                    <h3>Ogni locale è solo con i suoi dati</h3>
                    <p>La separazione è imposta dal database, non dal codice. Una query dimenticata non fa trapelare niente: semplicemente, non trova nulla.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="contenitore">
        <div class="chiusura">
            <h2>Un armadio, e si parte</h2>
            <p>Installazione, configurazione e primo armadio. Il resto cresce con te.</p>
            <a class="btn btn--pieno" href="mailto:info@lockupworld.com">Scrivici</a>
        </div>
    </div>
</main>

<footer class="contenitore">
    <span>© {{ now()->year }} LockUpWorld</span>
    <span><a href="/app">Area riservata</a></span>
</footer>

</body>
</html>
