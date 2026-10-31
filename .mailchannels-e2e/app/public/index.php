<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require \dirname(__DIR__).'/autoload.php';

$kernel = new Kernel('prod', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
