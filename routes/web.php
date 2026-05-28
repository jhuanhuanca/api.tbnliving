<?php

use App\Http\Controllers\Sanctum\CrossDomainCsrfCookieController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/*
|--------------------------------------------------------------------------
| Sanctum CSRF Cookie
|--------------------------------------------------------------------------
*/
Route::get('/sanctum/csrf-cookie', CrossDomainCsrfCookieController::class)
    ->middleware('web')
    ->name('sanctum.csrf-cookie');

/*
|--------------------------------------------------------------------------
| Welcome
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Email Verification
|--------------------------------------------------------------------------
*/
Route::get('/email/verify/{id}/{hash}', function (
    Request $request,
    $id,
    $hash
) {

    $user = User::findOrFail($id);

    /*
    |--------------------------------------------------------------------------
    | Validar firma signed URL
    |--------------------------------------------------------------------------
    */
    if (! $request->hasValidSignature()) {
        abort(403, 'Link inválido o expirado');
    }

    /*
    |--------------------------------------------------------------------------
    | Validar hash email
    |--------------------------------------------------------------------------
    */
    if (! hash_equals(
        (string) $hash,
        sha1($user->getEmailForVerification())
    )) {
        abort(403, 'Hash inválido');
    }

    /*
    |--------------------------------------------------------------------------
    | Verificar email
    |--------------------------------------------------------------------------
    */
    if (! $user->hasVerifiedEmail()) {

        $user->markEmailAsVerified();

        event(new Verified($user));
    }

    /*
    |--------------------------------------------------------------------------
    | Redireccionar frontend
    |--------------------------------------------------------------------------
    */
    return redirect()->away(
        rtrim((string) config('app.frontend_url'), '/')
        . '/signin?verified=1'
    );

})->middleware(['signed'])->name('verification.verify');