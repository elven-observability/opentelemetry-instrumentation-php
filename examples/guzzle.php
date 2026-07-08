<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elven\Observability\Php\Integrations\Guzzle\GuzzleMiddleware;
use Elven\Observability\Php\Observability;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

Observability::init(['enabled' => true, 'service_name' => 'guzzle-example', 'environment' => 'local']);

$stack = HandlerStack::create();
$stack->push(GuzzleMiddleware::middleware());

$client = new Client(['handler' => $stack]);
$client->get('https://example.com/');

Observability::boot()->shutdown();
