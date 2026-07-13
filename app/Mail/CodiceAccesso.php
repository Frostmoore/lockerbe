<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La mail col codice per riaprire il vano.
 *
 * ⚠️ **E' l'unica copia del codice che il cliente avra' mai.** Nel database c'e' solo il suo
 * SHA-256: se questa mail non arriva, il codice non e' recuperabile — nemmeno da noi. Lo
 * staff puo' aprire il vano dal pannello, ma il codice e' perso.
 *
 * ⚠️ Oggi non c'e' un provider di posta: `MAIL_MAILER=log`, e la mail finisce in
 * `storage/logs/laravel.log`. Il codice e' vero. Collegare un provider **non cambia una riga
 * di codice**: cambia una variabile d'ambiente.
 */
class CodiceAccesso extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $codice,
        public readonly int $numeroVano,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Il tuo vano è il {$this->numeroVano} — codice {$this->codice}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.codice-accesso');
    }
}
