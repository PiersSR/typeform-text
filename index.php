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
    return $response->withRedirect('/login');
});

$app->get('/send', function (Request $request, Response $response) {
    sendText($this->twilio);
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
        ], [], 'POST'), true);

        $account = requestAPI('/me', [], $json['access_token']);
        
        $campaignID = uniqid();
        runPDO($this->db, 'INSERT INTO campaigns (id, token, user, email) VALUES (:id, :token, :user, :email)', [
            'id'    => $campaignID,
            'token' => $json['access_token'],
            'user'  => $account['alias'],
            'email' => $account['email'],
        ]);

        return $response->withRedirect("/campaign/new?c=$campaignID");
    });
});

$app->group('/campaign', function() {
    
    $this->get('/new', function (Request $request, Response $response) {
        if (empty($request->getQueryParam('c'))) return notFoundHandler($this, $request, $response);
        $campaign = $request->getQueryParam('c');

        return $this->view->render($response, 'createCampaign.html.twig', [
            'forms'     => requestAPI('/forms', [], getAccessToken($this->db, $campaign)),
            'campaign'  => runPDO($this->db, 'SELECT * FROM campaigns WHERE id = :id', ['id' => $campaign])->fetch(),
        ]);
    });

    $this->post('/new', function (Request $request, Response $response) {
        $post = $request->getParsedBody();
        if (empty($post['campaign']) || empty($post['form']) || empty($post['numbers'])) return notFoundHandler($this, $request, $response);
        
        $form = requestAPI('/forms/' . $post['form'], [], getAccessToken($this->db, $post['campaign']));
        runPDO($this->db, 'UPDATE campaigns SET title = :title WHERE id = :id', [
            'title'     => $form['title'],
            'id'  => $post['campaign'],
        ]);

        $numbers = [];
        foreach (explode(',', $post['numbers']) as $number) {
            $id = uniqid();
            $textees[] = [
                'id'    => $id,
                'phone' => $number,
            ];
            print_r(explode('\n', $post['numbers']));
            runPDO($this->db, 'INSERT INTO textees VALUES (:id, :phone)', [
                'id'    => $id,
                'phone' => $number,
            ]);
        }

        foreach ($form['fields'] as $field) {
            $id = uniqid();
            runPDO($this->db, 'INSERT INTO questions VALUES (:id, :title, :type, :campaign)', [
                'id'        => $id,
                'title'     => $field['title'],
                'type'      => $field['type'],
                'campaign'  => $post['campaign'],
            ]);

            foreach ($textees as $textee) {
                runPDO($this->db, 'INSERT INTO texts (id, textee, question) VALUES (:id, :textee, :question)', [
                    'id'        => uniqid(),
                    'textee'    => $textee['id'],
                    'question'  => $id,
                ]);
            }
        }

        foreach ($textees as $textee) {
            sendText($this->twilio, $textee['phone']);
        }

        return $response->withStatus(200);
    });

});

$app->post('/twilio/callback', function (Request $request, Response $response) {
    $post = $request->getParsedBody();

    $textee = runPDO($this->db, 'SELECT textees.id FROM textees WHERE textees.phone = :from', 
        ['from' => $post['From']]
    )->fetchColumn();

    if (!$textee == false) return notFoundHandler($this, $request, $response);

    $result = runPDO($this->db, 'UPDATE texts SET answer WHERE texts.textee = :textee', [
        'textee' => $textee]);

    sendText($this->twilio, $post['From']);


});

$app->run();

function sendText($client, $to) {
   
    $question = runPDO($db, 'SELECT question.title FROM questions
                             INNER JOIN texts ON questions.id = texts.question
                             INNER JOIN textees ON texts.textee = textees.id
                             WHERE textees.phone = :from', 
                             ['from' => $from]
                         )->fetchColumn();

    if (!question) return;

    $twilioNumber = '+447449537878';

    $client->messages->create([
        'to'   => $to,
        'from' => 'typeform-text',
        'body' => $question,
    ]);
        
    runPDO($db, 'UPDATE texts SET sent = 1 WHERE textees.phone = :from', ['from' => $from]);
}


function performRequest($url, $data, $headers, $method) {
    $options = array(
        'http' => array(
            'header'  => array_merge([], $headers),
            'method'  => $method,
            'content' => http_build_query($data)
        )
    );
    $context  = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

function requestAPI($uri, $data, $token) {
    return json_decode(performRequest("https://api.typeform.com$uri", $data, ["Authorization: Bearer $token"], 'GET'), true);
}

function postData($url, $data) {
    return performRequest($url, $data, ['Content-type: application/x-www-form-urlencoded'], 'POST');
}

function runPDO($db, $sql, $params = null) {
    if (!$params) return $db->query($sql);

    $q = $db->prepare($sql);
    $q->execute($params);
    return $q;
}

function getAccessToken($db, $campaign) {
    return runPDO($db, 'SELECT token FROM campaigns WHERE id = :id', ['id' => $campaign])->fetch();
}