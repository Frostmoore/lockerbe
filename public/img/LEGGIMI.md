# Immagini del chiosco

## `nfc.png` — il simbolo contactless della schermata di pagamento

**Mettilo qui: `public/img/nfc.png`**

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

Il codice sta in `resources/views/emulator.blade.php`, funzione `immagineNfc()`.
