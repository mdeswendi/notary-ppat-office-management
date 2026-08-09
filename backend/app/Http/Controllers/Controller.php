<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Gives every controller `$this->authorize(...)`.
     *
     * Laravel 11 removed this from the generated base class; it is added back
     * here rather than repeated per controller, because authorization is
     * mandatory on business endpoints (`docs/06_API_CONVENTIONS.md` section 27)
     * and a controller that cannot reach `authorize()` is a controller that
     * silently ships without it.
     */
    use AuthorizesRequests;
}
