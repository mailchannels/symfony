<?php

// The app runs on the components of this checkout, including the MailChannels bridge
$loader = require \dirname(__DIR__, 2).'/vendor/autoload.php';
$loader->addPsr4('App\\', __DIR__.'/src/');

return $loader;
