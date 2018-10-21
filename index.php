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

// middleware

$m_accesscontrol = function ($request, $response, $next) use ($container) {
    if (count(
        runPDO($container['db'], 'SELECT id FROM campaigns 
            WHERE id = :id
            AND keystr = :key', [
                'id' => explode('/', $request->getUri()->getPath())[2],
                'key'=> $request->getQueryParam('k'),
            ])->fetchAll()
    ) == 0) return $response->withRedirect('/login');
    return $next($request, $response);
};

// Routes

$app->get('/', function (Request $request, Response $response) {
    return $response->withRedirect('/login');
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

$app->group('/campaign', function() use ($m_accesscontrol) {
    
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
        $key = generateKey();
        runPDO($this->db, 'UPDATE campaigns SET title = :title, keystr = :key WHERE id = :id', [
            'title' => $form['title'],
            'key'   => $key,
            'id'    => $post['campaign'],
        ]);

        $numbers = [];
        foreach (explode(',', $post['numbers']) as $number) {
            $id = uniqid();
            $textees[] = [
                'id'    => $id,
                'phone' => $number,
            ];
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
            sendText($this->twilio, $textee['phone'], 'Hey there! Mind answering a few questions? This survey\'s called ' . $form['title'] . '. If you\'d rather not, just don\'t reply to the first question - we won\'t bother you again. :)');
            sendText($this->twilio, $textee['phone'], 'You can also complete this survey online: ' . $form['_links']['display']);
            sendNextText($this->db, $this->twilio, $textee['phone']);
        }

        return $response->withRedirect('/campaign/' . $post['campaign'] . "?k=$key");
    });

    $this->group('/{campaign}', function() {

        $this->get('', function (Request $request, Response $response, $args) {
            return $this->view->render($response, 'campaign.html.twig', [
                'campaign'  => $args['campaign'],
                'questions' => runPDO($this->db, 
                    'SELECT * FROM questions WHERE campaign = :campaign', 
                    ['campaign' => $args['campaign']]
                ),
            ]);
        });

        $this->get('/poll', function (Request $request, Response $response, $args) {
            $answers = runPDO($this->db, 'SELECT texts.answer, textees.phone, q.id
                FROM texts
                INNER JOIN textees on texts.textee = textees.id
                INNER JOIN questions q on texts.question = q.id
                INNER JOIN campaigns c on q.campaign = c.id
                WHERE texts.answer IS NOT NULL
                AND c.id = :id
                ORDER BY q.id ASC',
                ['id' => $args['campaign']]
            );
            $data = [];
            foreach ($answers as $answer) {
                $data[$answer['phone']][] = $answer['answer'];
            }

            $nQuestions = count(runPDO(
                $this->db, 'SELECT id FROM questions WHERE campaign = :campaign', 
                ['campaign' => $args['campaign']]
            )->fetchAll());

            $json = $data;
            foreach ($data as $phone => $answers) {
                if (count($answers) < $nQuestions) {
                    unset($json[$phone]);
                }
            }

            echo json_encode($json);
            return;
        });

    })->add($m_accesscontrol);

    
});

$app->post('/twilio/callback', function (Request $request, Response $response) {
    $post = $request->getParsedBody();

    $textee = runPDO($this->db, 'SELECT textees.id FROM textees WHERE textees.phone = :from', 
        ['from' => $post['From']]
    )->fetchColumn();

    if (!$textee) return notFoundHandler($this, $request, $response);

    $result = runPDO($this->db, 'UPDATE texts SET answer = :answer 
        WHERE texts.textee = :textee 
        AND answer IS NULL
        ORDER BY texts.id ASC
        LIMIT 1', [
            'textee'    => $textee,
            'answer'    => $post['Body'],
        ]
    );

    sendNextText($this->db, $this->twilio, $post['From']);

    return $response->withStatus(200);
});

$app->run();

function sendNextText($db, $client, $to) {
   
    $question = runPDO($db, 'SELECT questions.title, questions.type, texts.id FROM questions
                             INNER JOIN texts ON questions.id = texts.question
                             INNER JOIN textees ON texts.textee = textees.id
                             WHERE textees.phone = :phone
                             AND texts.sent = 0', 
                             ['phone' => $to]
                         )->fetchAll();

    if (count($question) == 0) return;
    $question = $question[0];

    sendText($client, $to, $question['title']);

    runPDO($db, 'UPDATE texts SET sent = 1 WHERE id = :id', ['id' => $question['id']]);
}

function sendText($client, $to, $body) {
    $twilioNumber = '+447449537878';

    $client->messages->create(
        $to, [
            'from' => $twilioNumber,
            'body' => $body,
        ]
    );
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
    return runPDO($db, 'SELECT token FROM campaigns WHERE id = :id', ['id' => $campaign])->fetchColumn();
}

function notFoundHandler($app, $request, $response) {
    return $app->get('notFoundHandler')($request, $response);
}

function generateKey() {
    $characters = '0123456789abcdef';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < 44; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}