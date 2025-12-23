<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Para rotas autenticadas, usamos o usuário para definir o tenant
        $user = $request->user();

        if ($user) {
            Tenant::set((int) $user->account_id, (int) $user->location_id);
        }

        return $next($request);
    }
}
