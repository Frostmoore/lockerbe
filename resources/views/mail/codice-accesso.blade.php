<!doctype html>
<html lang="it">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:32px;background:#f4f4f5;font-family:-apple-system,'Segoe UI',system-ui,sans-serif;color:#18181b">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;text-align:center">

    <p style="margin:0;color:#71717a;font-size:14px">Il tuo vano è il</p>
    <p style="margin:4px 0 24px;font-size:56px;font-weight:800;line-height:1">{{ $numeroVano }}</p>

    <p style="margin:0;color:#71717a;font-size:14px">Digita questo codice sul chiosco per riaprirlo o riconsegnarlo</p>

    <p style="margin:8px 0 24px;font-size:40px;font-weight:800;letter-spacing:10px;font-family:ui-monospace,Consolas,monospace">
        {{ $codice }}
    </p>

    {{-- ⚠️ È l'unica copia: nel database c'è solo l'hash. Se questa mail si perde, il codice
         non è recuperabile — nemmeno da noi. --}}
    <p style="margin:0;color:#a1a1aa;font-size:12px;line-height:1.6">
        Tieni da parte questa email: è l'unica copia del codice.<br>
        Vale <strong>solo per questo armadio</strong> e <strong>solo per stasera</strong>.<br>
        Se lo perdi, chiedi allo staff.
    </p>
</div>
</body>
</html>
