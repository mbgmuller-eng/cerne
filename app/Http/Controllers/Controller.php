<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // O Laravel 11+ deixou de incluir este trait por padrão. No Cerne toda
    // ação toca dado financeiro de terceiros, então autorizar é a regra e
    // não a exceção — o trait fica na base.
    use AuthorizesRequests;
}
