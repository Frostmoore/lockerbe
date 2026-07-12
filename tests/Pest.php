<?php

use Tests\Concerns\RefreshDatabaseAsOwner;
use Tests\TestCase;

/*
 * I test Feature girano contro PostgreSQL (mai SQLite: senza RLS il test di
 * isolamento fra tenant — §17.1, il piu' importante del progetto — passerebbe
 * mentendo).
 *
 * Lo schema viene ricreato come locker_owner, ma le query dei test girano come
 * locker_app: vedi RefreshDatabaseAsOwner, dove sta il perche'.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
