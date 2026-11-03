<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    /**
     * Generate a signed stateless admin token for cross-domain SPA requests.
     */
    public static function generateToken(): string
    {
        $payload = 'rodo-admin-000|' . now()->timestamp;
        $signature = hash_hmac('sha256', $payload, (string) config('app.key', 'rodo-secret'));

        return 'admin.' . base64_encode($payload) . '.' . $signature;
    }

    /**
     * Verify a signed stateless admin token.
     */
    public static function verifyToken(?string $token): bool
    {
        if (!$token || !str_starts_with($token, 'admin.')) {
            return false;
        }

        $parts = explode('.', $token, 3);
        if (count($parts) !== 3) {
            return false;
        }

        $payload = base64_decode($parts[1], true);
        if (!$payload || !str_starts_with($payload, 'rodo-admin-000|')) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, (string) config('app.key', 'rodo-secret'));

        return hash_equals($expected, $parts[2]);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $adminToken = $request->header('X-Admin-Token') ?: $bearer;

        if (self::verifyToken($adminToken)) {
            return $next($request);
        }

        if (trim((string) $request->header('X-Admin-Code')) === '000') {
            return $next($request);
        }

        if ($request->hasSession() && $request->session()->get('is_rodo_admin') === true) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Acceso no autorizado. Se requiere sesión de administrador (000).',
        ], 401);
    }
}

