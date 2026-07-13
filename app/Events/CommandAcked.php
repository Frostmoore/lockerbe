<?php

namespace App\Events;

use App\Models\Command;
use Illuminate\Foundation\Events\Dispatchable;

final class CommandAcked
{
    use Dispatchable;

    public function __construct(public readonly Command $command) {}
}
