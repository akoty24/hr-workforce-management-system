<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the built React SPA (frontend/ -> public/app/) for every non-API
 * page path, so client-side routes like /departments work on reload.
 *
 * Registered as the router fallback: API, health and asset routes always win.
 * In development the SPA is served by Vite (http://localhost:5173) instead.
 */
class SpaController
{
    public function __invoke(Request $request): Response
    {
        // Unknown API paths are API 404s (JSON), never the SPA.
        abort_if($request->is('api', 'api/*'), 404);

        $index = public_path('app/index.html');

        if (! is_file($index)) {
            return response(
                '<!doctype html><meta charset="utf-8"><title>HR Workforce</title>'.
                '<p style="font-family:system-ui;margin:2rem">The frontend has not been built yet. '.
                'Run <code>npm run build</code> in <code>frontend/</code>, or use the dev server at '.
                '<a href="http://localhost:5173">http://localhost:5173</a>.</p>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return response(file_get_contents($index), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // index.html must always be revalidated; hashed assets can be cached forever.
            'Cache-Control' => 'no-cache',
        ]);
    }
}
