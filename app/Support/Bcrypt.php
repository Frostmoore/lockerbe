<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Verifica una password contro un hash bcrypt **coniato altrove**.
 *
 * ⚠️ **PERCHE' NON SI USA Hash::check() DIRETTAMENTE** (trappola 30, ci ha gia' morso).
 *
 * `password_get_info()` di PHP riconosce come bcrypt **solo il prefisso `$2y$`**: su un `$2a$`
 * — quello che emettono quasi tutti i generatori esterni, `htpasswd` compreso — risponde
 * `algoName => 'unknown'`. E `BcryptHasher::check()`, con `hashing.bcrypt.verify` acceso (il
 * default), interroga proprio quella funzione e **solleva una RuntimeException** invece di
 * rispondere `false`.
 *
 * Il sintomo inganna: la password e' **giusta**, e l'utente vede un **500**.
 *
 * `$2a$`, `$2b$` e `$2y$` sono lo **stesso identico digest**: cambia solo l'etichetta (lo si
 * verifica con `password_verify()`, che li accetta tutti e tre). Quindi qui si normalizza
 * l'etichetta e si lascia in piedi `hashing.bcrypt.verify` — che esiste per impedire il
 * **downgrade dell'algoritmo**, e non si spegne una difesa per aggirare un'etichetta.
 *
 * ⚠️ `$2x$` NON si normalizza: e' la variante *bacata* del 2011 (bug del sign-extension sui
 * byte alti). Un `$2x$` e un `$2y$` della stessa password possono avere digest **diversi**:
 * riscriverne il prefisso cambierebbe silenziosamente la password.
 */
final class Bcrypt
{
    /**
     * L'hash e' nella forma che Laravel accetta? Se no, lo riporta a `$2y$`.
     *
     * Restituisce l'hash invariato se non e' un bcrypt riconoscibile: sara' `check()` a dire
     * di no, senza esplodere.
     */
    public static function normalize(string $hash): string
    {
        // Solo le varianti dimostrabilmente equivalenti a $2y$. $2x$ resta fuori, apposta.
        if (str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return '$2y$'.substr($hash, 4);
        }

        return $hash;
    }

    /**
     * Fail-closed: un hash mancante, vuoto o malformato non e' "nessuna password" — e' **no**.
     */
    public static function check(string $plain, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        $hash = self::normalize($hash);

        if (password_get_info($hash)['algoName'] !== 'bcrypt') {
            return false;
        }

        return Hash::check($plain, $hash);
    }
}
