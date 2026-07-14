# Immagini del chiosco

## `nfc.png` — il simbolo contactless della schermata di pagamento

**Mettilo qui: `public/img/nfc.png`** — ✅ **c'è** (il simbolo contactless standard, scuro su trasparente).

| | |
|---|---|
| **Formato** | PNG con **sfondo trasparente** (va bene anche `.svg`, cambiando il nome nel codice) |
| **Forma** | quadrata |
| **Dimensione** | almeno **400 × 400 px** — sul tablet viene mostrata a 190 px, ma su uno schermo a densità doppia una 190×190 sgrana |
| **Colore** | scuro (il fondo del chiosco è **bianco**). Il blu del marchio è `#14306b` |

⚠️ **Se il file non c'è, non si rompe niente**: il chiosco ripiega sul simbolo disegnato a
mano (l'onda contactless in SVG). Un'icona rotta su una schermata di pagamento sarebbe peggio
di nessuna icona — il cliente non capirebbe dove appoggiare la carta, e non appoggerebbe
niente.

⚠️ **Il logo è SCURO**, e va benissimo sulle schermate a fondo bianco. Ma **dentro un bottone blu
scuro sparirebbe**: lì il chiosco usa il simbolo **disegnato**, in bianco (`rfid(size,
'rfid--bianco')`). È anche il motivo per cui il disegno SVG resta nel codice invece di essere
buttato via ora che c'è il logo vero.

Il codice sta in `resources/views/emulator.blade.php`, funzione `immagineNfc(size, pulsa)`.
