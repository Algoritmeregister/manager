<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Mailgun\Mailgun;
use Tiltshift\Algoritmeregister\Algoritmeregister;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../private/config.php';

$app = AppFactory::create();

$twig = Twig::create(__DIR__ . '/../templates', [
    //'cache' => __DIR__ . '/../cache'
]);

$app->add(TwigMiddleware::create($app, $twig));

$algoritmeregister = new Algoritmeregister(__DIR__ . "/../storage/");

$app->get('/', function (Request $request, Response $response, $args) use ($algoritmeregister) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'overzicht.twig', [
        'items' => $algoritmeregister->getIndex()
    ]);
});

$app->get('/over', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'over.twig', [
        'title' => 'Over Algoritmeregister'
    ]);
});

$app->get('/aanmelden', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);
    return $view->render($response, 'aanmelden.twig', [
        'title' => 'Algoritme aanmelden',
        'description' => ''
    ]);
});

$app->post('/aanmelden', function (Request $request, Response $response, $args) use ($config, $algoritmeregister) {
    $data = $request->getParsedBody();
    $organisatie = $data["organisatie"];
    $afdeling = $data["afdeling"];
    $naam = $data["naam"];
    $contact = $data["contact"];
    $type = "onbekend";
    $status = "aangemeld";
    $herziening = date("d-m-Y");

    $maildomain = array_pop(explode('@', $contact));
    if (!in_array($maildomain, $config['known-maildomains'])) {
        return $response->withHeader("Location", "/aanmelden")->withStatus(303);
    }

    $algoritmeInfo = $algoritmeregister->create();
    $algoritmeInfo->setNaam($naam);
    $algoritmeregister->store($algoritmeInfo);
    $algoritmeregister->storeIndex($uuid, $organisatie, $afdeling, $naam, $type, $status, $herziening, $contact, $hash);

    $mgClient = Mailgun::create($config["mailgun-key"], $config["mailgun-url"]);
    $result = $mgClient->messages()->send("algoritmeregister.nl", array(
        'from'	=> 'Algoritmeregister <no-reply@algoritmeregister.nl>',
        'to'	=> $contact,
        'subject' => "Detailpagina {$naam} beschikbaar",
        'text'	=> "Je bent aangemeld als beheerder voor de detailpagina van {$naam} in het Algoritmeregister. Op https://www.algoritmeregister.nl/details/{$uuid}?token={$token} kun je de gegevens bekijken en bijwerken."
    ));
    
    return $response->withHeader("Location", "/details/{$uuid}")->withStatus(303);
});

$app->get('/details/{id}', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);
    $id = $args['id'];
    $metadata = json_decode(file_get_contents(__DIR__ . "/../storage/{$id}." . md5($id) . ".json"), true);
    $grouped = [];
    foreach ($metadata as $item) {
        $grouped[$item["categorie"]][] = $item;
    }
    return $view->render($response, 'details.twig', [
        'id' => $id,
        'title' => $metadata["naam"]["waarde"],
        'description' => $metadata["beschrijving"]["waarde"],
        'grouped' => $grouped
    ]);
});

$app->get('/data/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    header("Content-type: text/json");
    readfile(__DIR__ . "/../storage/{$id}." . md5($id) . ".json");
    die;
});

$app->get('/aanpassen/{id}', function (Request $request, Response $response, $args) {
    $view = Twig::fromRequest($request);
    $id = $args['id'];
    $metadata = json_decode(file_get_contents(__DIR__ . "/../storage/{$id}." . md5($id) . ".json"), true);
    $grouped = [];
    foreach ($metadata as $item) {
        $grouped[$item["categorie"]][] = $item;
    }
    return $view->render($response, 'aanpassen.twig', [
        'id' => $id,
        'title' => $metadata["naam"]["waarde"],
        'description' => $metadata["beschrijving"]["waarde"],
        'grouped' => $grouped
    ]);
});

$app->post('/aanpassen/{id}', function (Request $request, Response $response, $args) {
    $id = $args['id'];
    $attributes = json_decode(file_get_contents(__DIR__ . "/../storage/{$id}." . md5($id) . ".json"), true);
    foreach ($request->getParsedBody() as $key => $value) {
        $attributes[$key]["waarde"] = $value;
    }
    file_put_contents(__DIR__ . "/../storage/{$id}." . md5($id) . ".json", json_encode($attributes));
    return $response->withHeader("Location", "/details/{$id}")->withStatus(303);
});

$app->run();
