<?php

namespace App\Http\Controllers;

use App\Services\ProductionControlPlaneService;
use Inertia\Inertia;

class ProductionControlPlaneController extends Controller
{
    public function index(ProductionControlPlaneService $controlPlane)
    {
        return Inertia::render('ProductionControlPlane/Index', [
            'control_plane' => $controlPlane->snapshot(),
        ]);
    }
}
