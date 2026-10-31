<?php

use Symfony\Component\RemoteEvent\RemoteEvent;

return [new RemoteEvent('test', 'test-request', json_decode(file_get_contents(str_replace('.php', '.json', __FILE__)), true, flags: JSON_THROW_ON_ERROR)[0])];
