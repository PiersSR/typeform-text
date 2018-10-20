<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

// load classes

require __DIR__ . DIRECTORY_SEPARATOR . 'keys.php';
require __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function ($class) {
    include __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
});


// setup Slim

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;
$config['keys'] = $keys;

$app = new Slim\App(['settings' => $config]);

session_start();

$container = $app->getContainer();

// Twilio

$container['twilio'] = function ($c) {
    return new Twilio\Rest\Client(
        $c['settings']['keys']['twilio']['sid'], 
        $c['settings']['keys']['twilio']['authToken']
    );
};

// Twig

$container['view'] = function ($container) {
    $templates = __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
    $debug = false;
    $debug = true;
    $view = new Slim\Views\Twig($templates, compact('cache', 'debug'));
    $view->getEnvironment()->addGlobal('_get', $_GET);

    if ($debug) {
        $view->addExtension(new \Slim\Views\TwigExtension(
            $container['router'],
            $container['request']->getUri()
        ));
        $view->addExtension(new \Twig_Extension_Debug());
    }
    return $view;
};

// 404

$container['notFoundHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        return $c->view->render($response, '404.html.twig')->withStatus(404);
    };
};

// Routes

$app->get('/', function (Request $request, Response $response) {

});

$app->get('/send', function (Request $request, Response $response) {
    sendText($this->twilio);
});

$app->post('/twilio/callback', function (Request $request, Response $response) {
    $post = $request->getParsedBody();
    
});

$app->get('/login', function (Request $request, Response $response) {
    return $this->view->render($response, 'login.html.twig', [
        'tfClient' => $this->get('settings')['keys']['typeform']['client'],
    ]);
});

$app->run();

function sendText($client) {
    $twilioNumber = "+447449537878";

    $client->messages->create(
        '+447759945447',
        [
            'from' => '',
            'body' => '',
        ]   
    );

function recieveText($client) {
    
}

}