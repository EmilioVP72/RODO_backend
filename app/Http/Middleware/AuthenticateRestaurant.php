<?php

namespace App\Http\Middleware;

use App\Models\Restaurant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateRestaurant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Session / actingAs authentication (standard web / test guard)
        $restaurant = Auth::guard('restaurant')->user();

        // 2. Sanctum Bearer Token authentication (cross-domain SPA: Vercel -> Render)
        if (!$restaurant && $bearerToken = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearerToken);
            if ($token && $token->tokenable instanceof Restaurant && $token->tokenable->is_active) {
                $restaurant = $token->tokenable;
                $token->forceFill(['last_used_at' => now()])->save();
                Auth::guard('restaurant')->setUser($restaurant);
            }
        }

        // 3. Header fallback: X-Restaurant-Code
        if (!$restaurant && $code = $request->header('X-Restaurant-Code')) {
            $matched = Restaurant::where('code', trim($code))->where('is_active', true)->first();
            if ($matched) {
                $restaurant = $matched;
                Auth::guard('restaurant')->setUser($restaurant);
            }
        }

        // 4. Verify restaurant is valid and active
        if (!$restaurant || !$restaurant->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Sesión no válida.',
            ], 401);
        }

        // Bind user to request so $request->user() returns the restaurant
        $request->setUserResolver(fn () => $restaurant);

        return $next($request);
    }
}
