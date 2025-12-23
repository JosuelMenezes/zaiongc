<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use App\Support\Tenant;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    public function index()
    {
        return Terminal::where('account_id', Tenant::accountId())
            ->where('location_id', Tenant::locationId())
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:60',
            'code' => 'nullable|string|max:30',
            'device_code' => 'required|string|max:80',
        ]);

        $terminal = Terminal::create([
            'account_id' => Tenant::accountId(),
            'location_id' => Tenant::locationId(),
            'name' => $request->name,
            'code' => $request->code,
            'device_code' => $request->device_code,
            'is_active' => true,
        ]);

        return response()->json($terminal, 201);
    }
}
