<?php
// =====================================================
// index.php — Front Controller
// =====================================================

define('ROOT', __DIR__);

require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/config/session.php';
require_once ROOT . '/core/Csrf.php';
require_once ROOT . '/core/Request.php';
require_once ROOT . '/core/Response.php';
require_once ROOT . '/core/Auth.php';
require_once ROOT . '/core/Router.php';
require_once ROOT . '/core/Security.php';
require_once ROOT . '/core/RateLimiter.php';
require_once ROOT . '/core/RememberToken.php';

Security::headers();

// Dispatch request
$router = new Router();
$router->dispatch();
