<?php

namespace App\Events;

use App\Models\Command;
use Illuminate\Foundation\Events\Dispatchable;

final class CommandExpired
{
    use Dispatchable;

    public function __construct(public readonly Command $command) {}
}
