<?php
// =====================================================
// index.php — Front Controller
// =====================================================

define('ROOT', __DIR__);

require_once ROOT . '/config/config.php';

// Registered before anything else can fail, so an unexpected throwable is
// logged with its request context instead of reaching the browser raw.
require_once ROOT . '/core/ErrorHandler.php';
ErrorHandler::register();

require_once ROOT . '/config/database.php';
require_once ROOT . '/config/session.php';

// Classes in core/, models/ and controllers/ load on first use. Only the
// pieces every single request needs are pulled in eagerly below.
require_once ROOT . '/core/Autoloader.php';
Autoloader::register();

require_once ROOT . '/core/Request.php';
require_once ROOT . '/core/Response.php';
require_once ROOT . '/core/Auth.php';
require_once ROOT . '/core/Router.php';

Security::headers();

// Dispatch request
$router = new Router();
$router->dispatch();
