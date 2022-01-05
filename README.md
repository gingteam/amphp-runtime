```php
// worker.php
<?php

use App\Kernel;
use GingTeam\AmphpRuntime\Runtime;

require_once dirname(__DIR__).'/vendor/autoload.php';

$app = function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

$runtime = new Runtime([
    'project_dir' => dirname(__DIR__, 1),
    'document_root' => __DIR__,
    'port' => 8000,
]);

[$app, $args] = $runtime->getResolver($app)->resolve();

$app = $app(...$args);

$runtime->getRunner($app)->run();
```
