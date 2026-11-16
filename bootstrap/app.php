<?php

// PHP 8.4 removed mb_strcut; Laravel's exception renderer still calls it for long SQL strings
if (! function_exists('mb_strcut')) {
    function mb_strcut(string $str, int $start, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        return $length !== null ? substr($str, $start, $length) : substr($str, $start);
    }
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));

        // เสิร์ฟผ่าน Cloudflare Tunnel ตอนเดโม: ถ้าไม่เชื่อ proxy Laravel จะมองว่าเป็น http
        // แล้วสร้างลิงก์/redirect กลับไปเป็น localhost ทำให้หน้าเว็บพัง
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
