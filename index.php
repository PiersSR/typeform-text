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

$app = new Slim\App(['settings' => $config]);

session_start();

$container = $app->getContainer();

$container['keys'] = $keys;

// Twilio

$container['twilio'] = function ($c) {
    return new Twilio\Rest\Client(
        $c['keys']['twilio']['sid'], 
        $c['keys']['twilio']['authToken']
    );
};

// Database

$container['db'] = function ($c) {
    $db = $c['keys']['db'];
    $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'] . ';charset=utf8',
        $db['user'], $db['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
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

$app->group('/login', function() {

    $this->get('', function (Request $request, Response $response) {
        return $this->view->render($response, 'login.html.twig', [
            'tfClient' => $this->keys['typeform']['client'],
        ]);
    });

    $this->get('/callback', function (Request $request, Response $response, $args) {
        $json = json_decode(postData('https://api.typeform.com/oauth/token', [
            'grant_type'    => 'authorization_code',
            'code'          => $request->getQueryParam('code'),
            'client_id'     => $this->keys['typeform']['client'],
            'client_secret' => $this->keys['typeform']['secret'],
            'redirect_uri'  => 'http://hackupc.dev.guymac.eu/login/callback',
        ]), true);
        
        $campaignID = uniqid(),
        runPDO($this->db, 'INSERT INTO campaigns (id, token) VALUES (:id, :token)' [
            'id'    => $campaignID,
            'token' => $json['access_token'],
        ]);

        return $response->withRedirect("/campaign/new?c=$campaignID");

    });
});

$app->group('/campaign', function() {
    
    $this->get('/new', function (Request $request, Response $response) {
        
    });
});



$app->run();

function sendText($client) {
    $twilioNumber = "+447449537878";

    $client->messages->create(
        '+447759945447',
        [
            'from' => 'typeform text',
            'body' => "$body",
        ]   
    );
}

function postData($url, $data) {
    $options = array(
        'http' => array(
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)
        )
    );
    $context  = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

public function runPDO($db, $sql, $params = null) {
    if (!$params) return $db->query($sql);

    $q = $db->prepare($sql);
    $q->execute($params);
    return $q;
}

function receiveText($client, $db) {
    $db = $pdo->$query('SELECT form FROM questions')->fetchAll(PDO::FETCH_GROUP);
}