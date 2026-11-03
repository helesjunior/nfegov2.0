<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| A SPA (React/Vite) é servida pelo próprio Laravel (mesmo domínio). O build
| vai para `public/spa`. Todas as rotas que não forem de API/assets devolvem
| o `index.html`, deixando o roteamento para o React Router.
|
*/

Route::get('/{rota?}', function () {
    $index = public_path('spa/index.html');

    // Sem build da SPA: mensagem clara em vez de 404 confuso.
    abort_unless(file_exists($index), 503, 'A interface (SPA) ainda não foi compilada. Rode `npm run build`.');

    return response(file_get_contents($index), 200, ['Content-Type' => 'text/html']);
})->where('rota', '^(?!api|sanctum|up).*$');