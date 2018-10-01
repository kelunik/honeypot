<?php

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Options;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Socket;
use Monolog\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

$logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);

$logger = new Logger('server');
$logger->pushHandler($logHandler);

Loop::run(function () use ($logger) {
    $sockets = [
        Socket\listen('0.0.0.0:1337'),
        Socket\listen('[::]:1337'),
    ];

    $debugMode = true;

    $twigLoader = new Twig_Loader_Filesystem(__DIR__ . '/../resources/templates');
    $twig = new Twig_Environment($twigLoader, [
        'cache' => false,
        'debug' => $debugMode,
    ]);

    $router = new Router;
    $router->setFallback(new DocumentRoot(__DIR__ . '/../public'));

    $router->addRoute('GET', '/wp-admin{anything:(?:/?|/.+)}', new CallableRequestHandler(function () {
        return new Response(Status::TEMPORARY_REDIRECT, ['location' => '/wp-login.php']);
    }));

    $router->addRoute('GET', '/wp-login.php', new CallableRequestHandler(function () use ($twig) {
        return new Response(Status::OK, [
            'content-type' => 'text/html; charset=UTF-8',
        ], $twig->render('login.html.twig'));
    }));

    $options = new Options;
    if ($debugMode) {
        $options = $options->withDebugMode();
    }

    $server = new Server($sockets, $router, $logger, $options);

    yield $server->start();

    Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Loop::cancel($watcherId);

        yield $server->stop();

        Loop::stop();
    });
});