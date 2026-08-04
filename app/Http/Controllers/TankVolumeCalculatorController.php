<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TankVolumeCalculatorController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('tools/tank-volume');
    }
}
