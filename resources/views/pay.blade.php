{{-- La pagina che si apre sul TELEFONO del cliente, inquadrando il QR del chiosco. --}}
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guardaroba — pagamento</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px;
            display: flex; align-items: center; justify-content: center; min-height: 100vh;
            background: #0f1115; color: #e7e9ee;
            font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
        }
        .box { width: 100%; max-width: 380px; background: #171a21; border: 1px solid #262b36; border-radius: 16px; padding: 28px; }
        h1 { margin: 0; font-size: 2.4rem; text-align: center; }
        .sub { margin: 6px 0 24px; text-align: center; color: #8b93a7; font-size: .95rem; }
        label { display: block; font-size: .85rem; color: #8b93a7; margin-bottom: 6px; }
        input {
            width: 100%; padding: .8rem .9rem; font-size: 1rem;
            background: #0f1115; color: #e7e9ee;
            border: 1px solid #2f3542; border-radius: 10px;
        }
        .btn {
            width: 100%; margin-top: 16px; padding: .9rem; font-size: 1rem; font-weight: 600;
            border: 0; border-radius: 10px; cursor: pointer;
            background: #35d07f; color: #06210f;
        }
        /* ⚠️ L'annulla è NEUTRO, non rosso e non verde: non è né l'azione che vogliamo, né un
           errore. Un bottone appariscente accanto a "Paga" è un bottone che qualcuno preme per
           sbaglio — e perde il vano che aveva appena scelto. */
        .btn-annulla {
            width: 100%; margin-top: 10px; padding: .75rem; font-size: .9rem;
            border: 1px solid #2f3542; border-radius: 10px; cursor: pointer;
            background: transparent; color: #8b93a7;
        }
        .btn-annulla:hover { border-color: #4a5163; color: #b7bfcd; }
        .err { margin-top: 8px; color: #ff6b6b; font-size: .85rem; }
        .note { margin-top: 18px; color: #6f7789; font-size: .78rem; line-height: 1.6; text-align: center; }
        .ok { text-align: center; }
        .num { font-size: 4.5rem; font-weight: 800; line-height: 1; margin: 8px 0 16px; }
    </style>
</head>
<body>
<div class="box">
    @if ($session->status === 'created')
        <h1>{{ number_format($session->amount_cents / 100, 2, ',', '.') }} €</h1>
        <div class="sub">Guardaroba — vano {{ $numeroVano }}</div>

        <form method="POST" action="{{ route('pay.pay', ['token' => $token]) }}">
            @csrf

            <label for="email">La tua email</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="email"
                   placeholder="tu@esempio.it" value="{{ old('email') }}" required>

            {{-- ⚠️ L'email si chiede QUI, non al chiosco: digitare un indirizzo su un
                 touchscreen, in un locale affollato e al buio, è un modo affidabile di
                 sbagliarlo — e un'email sbagliata è un cliente che non riceve il codice. --}}
            <p class="note" style="margin-top:8px;text-align:left">
                Ci serve per mandarti il <strong>codice</strong> con cui riaprirai il vano.
            </p>

            @error('email')
                <div class="err">{{ $message }}</div>
            @enderror

            <button class="btn" type="submit">
                {{ $mock ? 'Simula pagamento ✅' : 'Paga' }}
            </button>
        </form>

        {{-- ⚠️ FORM SEPARATO, non un secondo bottone nello stesso.
             Dentro il form del pagamento, "annulla" verrebbe sottoposto alla validazione
             dell'email: chi vuole rinunciare si vedrebbe chiedere l'indirizzo prima di poterlo
             fare. Chi rinuncia non deve lasciarci niente. --}}
        <form method="POST" action="{{ route('pay.cancel', ['token' => $token]) }}">
            @csrf
            <button class="btn-annulla" type="submit">Ho cambiato idea — annulla</button>
        </form>

        <p class="note" style="margin-top:10px">
            Il vano tornerà subito libero per qualcun altro.
        </p>

        @if ($mock)
            {{-- ⚠️ Doppio cancello: in produzione questa pagina è di Nexi, non nostra. --}}
            <p class="note">Pagamento <strong>simulato</strong>: non c'è ancora un provider (D1 aperta).</p>
        @endif

    @elseif ($session->status === 'cancelled')
        {{-- ⚠️ Prima questo caso NON esisteva: qualunque stato diverso da `created` finiva nel
             ramo "Pagato ✅". Una prenotazione ANNULLATA diceva al cliente che aveva pagato —
             e lui sarebbe tornato a cercare un vano che non era suo. --}}
        <div class="ok">
            <div class="sub" style="margin:0">Prenotazione annullata</div>
            <p class="note" style="margin-top:14px">
                Il vano è tornato libero e non ti è stato addebitato niente.<br>
                Se ti serve ancora, prendine uno nuovo dal chiosco.
            </p>
        </div>

    @else
        <div class="ok">
            <div class="sub" style="margin:0">Pagato ✅ — il tuo vano è</div>
            <div class="num">{{ $numeroVano }}</div>

            <p class="note" style="margin:0">
                Ti abbiamo mandato una mail con il <strong>codice a 6 cifre</strong>.<br>
                Digitalo sul chiosco per riaprire il vano o per riconsegnarlo.
            </p>
        </div>
    @endif
</div>
</body>
</html>
