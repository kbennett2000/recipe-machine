<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Phase 10: /shopping-list/calculate is a stateless JSON endpoint
        // — no DB writes, no session mutation, no auth. It's a pure
        // function of the POSTed `recipes` array. CSRF exemption here
        // is correct: a forged cross-origin POST can't alter anything,
        // and the exemption lets non-browser clients (curl, scripts)
        // call the endpoint without first scraping a token. The legacy
        // fetch() in resources/js/app.js still sends X-CSRF-TOKEN as a
        // header — that's harmless with this exemption.
        $middleware->validateCsrfTokens(except: [
            'shopping-list/calculate',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
