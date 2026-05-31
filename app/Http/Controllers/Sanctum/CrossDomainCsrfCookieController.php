<?php

namespace App\Http\Controllers\Sanctum;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cookie CSRF con dominio compartido (.tbnliving.com) para SPAs en admin/front.
 */
class CrossDomainCsrfCookieController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! $request->hasSession()) {
            $request->setLaravelSession(app('session.store'));
        }

        $request->session()->start();
        $request->session()->save();

        $cookie = cookie(
            'XSRF-TOKEN',
            $request->session()->token(),
            0,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure'),
            false,
            false,
            config('session.same_site', 'lax'),
        );

        return response()
            ->noContent(204)
            ->withCookie($cookie);
    }
}
