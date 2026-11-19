<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the interface language the SPA sends (Accept-Language: ar / en) for
 * validation and other translated messages. Anything else stays English.
 */
class SetLocaleFromRequest
{
    private const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        app()->setLocale(in_array($preferred, self::SUPPORTED, true) ? $preferred : 'en');

        return $next($request);
    }
}
