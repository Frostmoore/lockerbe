{{--
    La porta dell'emulatore.

    ⚠️ Dietro questa pagina c'e' l'elenco degli armadi di TUTTI i clienti e le credenziali MQTT
    dei chioschi in chiaro. Non e' una formalita' da ambiente di sviluppo: e' l'unica cosa fra
    Internet e la possibilita' di aprire un armadietto altrui.
--}}
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Emulatore — accesso</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
            background: #0f1115; color: #e7e9ee;
        }
        .box {
            width: 100%; max-width: 380px;
            background: #171a21; border: 1px solid #262b36; border-radius: 14px;
            padding: 2rem 1.75rem;
        }
        h1 { margin: 0 0 .4rem; font-size: 1.25rem; }
        .sub { margin: 0 0 1.6rem; color: #8b93a7; font-size: .88rem; line-height: 1.5; }
        label { display: block; font-size: .8rem; color: #8b93a7; margin-bottom: .4rem; }
        input[type="password"] {
            width: 100%; padding: .7rem .85rem;
            background: #0f1115; color: #e7e9ee;
            border: 1px solid #2f3542; border-radius: 8px;
            font-size: .95rem; font-family: inherit;
        }
        input[type="password"]:focus { outline: none; border-color: #4c7fff; }
        button {
            width: 100%; margin-top: 1rem; padding: .75rem;
            background: #4c7fff; color: #fff;
            border: 0; border-radius: 8px;
            font-size: .95rem; font-weight: 600; font-family: inherit;
            cursor: pointer;
        }
        button:hover { background: #3b6ce8; }
        .errore {
            margin: 0 0 1.1rem; padding: .7rem .85rem;
            background: #2a1418; border: 1px solid #5c2029; border-radius: 8px;
            color: #ff8a94; font-size: .85rem;
        }
        .spento {
            margin: 0; padding: .9rem 1rem;
            background: #22190f; border: 1px solid #5c4420; border-radius: 8px;
            color: #f0b866; font-size: .85rem; line-height: 1.6;
        }
        code {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
            font-size: .8rem; color: #e7e9ee;
        }
    </style>
</head>
<body>
<div class="box">
    <h1>Emulatore chiosco</h1>

    @if ($configurata)
        <p class="sub">Dietro questa porta ci sono le credenziali dei chioschi. Serve la password.</p>

        @if ($errore)
            <p class="errore">{{ $errore }}</p>
        @endif

        <form method="POST" action="/emulator/unlock">
            @csrf
            {{-- Si torna dove si stava andando. Il controller accetta solo percorsi /emulator*. --}}
            <input type="hidden" name="destinazione" value="{{ request()->path() === 'emulator/unlock' ? '/emulator' : '/'.request()->path() }}">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autofocus autocomplete="current-password">

            <button type="submit">Entra</button>
        </form>
    @else
        {{-- ⚠️ Fail-closed: senza password configurata la pagina NON si apre. Una configurazione
             dimenticata non deve mai voler dire "entra pure". --}}
        <p class="spento">
            <strong>Emulatore chiuso.</strong><br>
            Nessuna password configurata: <code>LOCKER_EMULATOR_PASSWORD_HASH</code> è vuota,
            e senza password questa pagina resta chiusa.
        </p>
    @endif
</div>
</body>
</html>
