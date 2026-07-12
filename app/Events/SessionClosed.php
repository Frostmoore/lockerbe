<?php

namespace App\Events;

use App\Models\Session;
use Illuminate\Foundation\Events\Dispatchable;

final class SessionClosed
{
    use Dispatchable;

    public function __construct(public readonly Session $session) {}
}
