<?php

include __DIR__ . '/../vendor/autoload.php';

$rules = include __DIR__ . '/../tests/config.php';

$container = new \DElfimov\DI\Container($rules);

$dt = $container->get('dt');
$dt2 = $container->get('dt2');

var_dump($dt);

var_dump($dt2);