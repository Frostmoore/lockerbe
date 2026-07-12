<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, ma con le migration eseguite dal PROPRIETARIO dello schema.
 *
 * Perche' serve: nei test le query devono girare come `locker_app` — il ruolo di
 * runtime, non superuser e NOBYPASSRLS — perche' e' quello che le policy di Row
 * Level Security devono governare (piano §3.2). Ma `locker_app` non ha CREATE sullo
 * schema, e quindi non puo' costruirlo: le migration devono girare come
 * `locker_owner`.
 *
 * Se invece facessimo migrare e interrogare lo stesso ruolo proprietario, le policy
 * RLS sarebbero inerti e il test di isolamento fra tenant (§17.1) passerebbe sempre,
 * anche con l'isolamento completamente rotto. E' esattamente il tipo di test che da'
 * sicurezza senza darne.
 *
 * Nota di implementazione: non basta sovrascrivere `migrateFreshUsing()` nel TestCase
 * padre. In PHP i metodi che arrivano da un trait hanno precedenza su quelli ereditati
 * dalla classe padre, quindi la versione di RefreshDatabase vincerebbe. Da qui
 * l'aliasing qui sotto.
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return array_merge(
            $this->baseMigrateFreshUsing(),
            ['--database' => 'pgsql_owner'],
        );
    }
}
