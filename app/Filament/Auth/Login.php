<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Il login dei pannelli: **username OPPURE email**.
 *
 * Filament, di suo, accetta solo l'email. Ma le persone che useranno questo sistema hanno
 * un `username` (`smp-webmaster`, `user`) e lo conoscono a memoria; l'email di servizio con
 * cui l'account e' stato creato, spesso, no. Costringerle a ricordarsela e' un modo di
 * chiudere fuori la gente dal proprio strumento di lavoro.
 *
 * ⚠️ **Come si fa un OR nelle credenziali.** `EloquentUserProvider::retrieveByCredentials()`
 * cicla sulle chiavi e costruisce dei `where` in AND — quindi con un array normale non si
 * puo' esprimere "email **oppure** username". Ma se il *valore* e' una Closure, il provider
 * la chiama passandole la query: e' il gancio previsto, ed e' l'unico modo pulito di farlo
 * senza riscrivere l'autenticazione.
 *
 * ⚠️ La chiave della Closure non deve contenere la parola `password`: il provider salta
 * quelle (sono la cosa che verifica dopo, con l'hash, non un filtro da mettere in una query).
 *
 * ⚠️ **Non cambia nulla della sicurezza**: la password viene comunque verificata con l'hash
 * da `validateCredentials()`, il rate limit resta quello di Filament (5 tentativi), e il
 * messaggio d'errore resta **uno solo** — non diciamo se a non esistere e' l'utente o a
 * essere sbagliata la password.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getLoginFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Username o email')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = (string) $data['login'];

        return [
            // ⚠️ Vedi il commento in testa: il valore e' una Closure, quindi il provider la
            // chiama con la query invece di trasformarla in un `where`. E' cosi' che si
            // ottiene un OR.
            'identificativo' => fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('email', $login)->orWhere('username', $login),
            ),

            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        // ⚠️ Un messaggio solo, uguale in tutti i casi. Dire "questo utente non esiste"
        // significa dire a chi bussa quali username esistono.
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
