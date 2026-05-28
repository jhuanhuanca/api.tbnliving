<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Prospecto;

class ProspectosController extends Controller
{
   public function index()
    {
        return Prospecto::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required',
            'email' => 'required|email',
            'empresa' => 'required',
            'estado' => 'required'
        ]);

        return Prospecto::create($request->all());
    }

    public function show(Prospecto $prospecto)
    {
        return $prospecto;
    }

    public function update(Request $request, Prospecto $prospecto)
    {
        $request->validate([
            'nombre' => 'required',
            'email' => 'required|email',
            'empresa' => 'required',
            'estado' => 'required'
        ]);

        $prospecto->update($request->all());
        return $prospecto;
    }

    public function destroy(Prospecto $prospecto)
    {
        $prospecto->delete();
        return response()->json(['message' => 'Prospecto eliminado']);
    }
}
