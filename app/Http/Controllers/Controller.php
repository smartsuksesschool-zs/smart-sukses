<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 11 tidak lagi memasang trait ini secara bawaan. Controller API
     * memakainya untuk memanggil policy yang sudah ada — kewenangan tidak
     * ditulis ulang di controller (butir 117).
     */
    use AuthorizesRequests;
}
