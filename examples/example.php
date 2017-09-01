<?php

include __DIR__ . '/../vendor/autoload.php';

$rules = include __DIR__ . '/../tests/config.php';

$container = new \DElfimov\DI\Container($rules);

$dt = $container->get('dt');
$dt2 = $container->get('dt2');

var_dump($dt);

var_dump($dt2);


// if your rules are quite simple, you don't use rules inheritance and/or default rule ('*'),
// rules caching is not necessary, because it will not give your significant performace boost.
$cachedRulesFilename = __DIR__ . '/rules_cached.php';

file_put_contents($cachedRulesFilename, '<?php return ' . var_export($container->getRules(), true) . ';');

try {
    $cachedRules = include $cachedRulesFilename;
} catch (Exception $e) {
    $cachedRules = null;
}

$container = new \DElfimov\DI\Container($rules, $cachedRules);

$dt3 = $container->get('dt3');

var_dump($dt3);
