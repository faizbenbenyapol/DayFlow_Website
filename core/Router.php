<?php
// =====================================================
// core/Router.php — URL Dispatcher
// =====================================================

class Router
{
    private array $routes = [];

    public function __construct()
    {
        $this->registerRoutes();
    }

    /**
     * Register all application routes
     * Format: [METHOD, pattern, ControllerClass, method, requiresAuth]
     */
    private function registerRoutes(): void
    {
        // --- Auth ---
        $this->add('GET',  '/login',    'AuthController', 'showLogin',  false);
        $this->add('GET',  '/register', 'AuthController', 'showLogin',  false);
        $this->add('GET',  '/logout',   'AuthController', 'logout',     true);

        // --- Pages ---
        $this->add('GET', '/',              'DashboardController', 'index',   true);
        $this->add('GET', '/tasks',         'TaskController',       'index',   true);
        $this->add('GET', '/notes',         'NoteController',       'index',   true);
        $this->add('GET', '/notes/{id}',    'NoteController',       'editor',  true);
        $this->add('GET', '/planner',       'PlannerController',    'index',   true);
        $this->add('GET', '/projects',      'ProjectController',    'index',   true);
        $this->add('GET', '/project/shared/{token}', 'ProjectController', 'sharedProjectView', false);
        $this->add('GET', '/exercise',      'ExerciseController',   'index',   true);
        $this->add('GET', '/finance',       'FinanceController',    'index',   true);
        $this->add('GET', '/subscriptions', 'SubscriptionController','index',  true);
        $this->add('GET', '/files',         'FileController',       'index',   true);
        $this->add('GET', '/files/{id}',    'FileController',       'folder',  true);
        $this->add('GET', '/settings',      'SettingsController',   'index',   true);
        $this->add('GET', '/food-notes',    'FoodNoteController',   'index',   true);
        $this->add('GET', '/calculator',    'CalculatorController', 'index',   true);
        $this->add('GET', '/ai',            'AiController',         'index',   true);

        // --- API: Auth ---
        $this->add('POST', '/api/auth/login',    'AuthController', 'apiLogin',    false);
        $this->add('POST', '/api/auth/logout',   'AuthController', 'apiLogout',   true);
        $this->add('POST', '/api/auth/register', 'AuthController', 'apiRegister', false);
        $this->add('POST', '/api/auth/google',   'AuthController', 'apiGoogleLogin', false);

        // --- API: Dashboard ---
        $this->add('GET',  '/api/dashboard/summary', 'DashboardController', 'summary', true);
        $this->add('POST', '/api/dashboard/layout',  'DashboardController', 'layout',  true);

        // --- API: Tasks ---
        $this->add('GET',    '/api/tasks',         'TaskController', 'apiList',    true);
        $this->add('POST',   '/api/tasks',         'TaskController', 'apiCreate',  true);
        $this->add('PUT',    '/api/tasks/{id}',    'TaskController', 'apiUpdate',  true);
        $this->add('DELETE', '/api/tasks/{id}',    'TaskController', 'apiDelete',  true);
        $this->add('POST',   '/api/tasks/reorder', 'TaskController', 'apiReorder', true);

        // --- API: Notes ---
        $this->add('GET',    '/api/notes',                       'NoteController', 'apiList',         true);
        $this->add('POST',   '/api/notes',                       'NoteController', 'apiCreate',       true);
        $this->add('PUT',    '/api/notes/{id}',                  'NoteController', 'apiUpdate',       true);
        $this->add('DELETE', '/api/notes/{id}',                  'NoteController', 'apiDelete',       true);
        $this->add('POST',   '/api/notes/{id}/verify',           'NoteController', 'apiVerify',       true);
        $this->add('GET',    '/api/notes/tags',                  'NoteController', 'apiTags',         true);
        $this->add('POST',   '/api/notes/tags',                  'NoteController', 'apiTagCreate',    true);
        $this->add('PUT',    '/api/notes/tags/{id}',             'NoteController', 'apiTagUpdate',    true);
        $this->add('DELETE', '/api/notes/tags/{id}',             'NoteController', 'apiTagDelete',    true);
        $this->add('GET',    '/api/notes/{id}/blocks',           'NoteController', 'apiBlocksList',   true);
        $this->add('POST',   '/api/notes/{id}/blocks',           'NoteController', 'apiBlockCreate',  true);
        $this->add('PUT',    '/api/notes/{id}/blocks/{bid}',     'NoteController', 'apiBlockUpdate',  true);
        $this->add('DELETE', '/api/notes/{id}/blocks/{bid}',     'NoteController', 'apiBlockDelete',  true);
        $this->add('POST',   '/api/notes/{id}/blocks/reorder',   'NoteController', 'apiBlockReorder', true);

        // --- API: Planner ---
        $this->add('GET',    '/api/planner/events',      'PlannerController', 'apiEventsList',   true);
        $this->add('POST',   '/api/planner/events',      'PlannerController', 'apiEventCreate',  true);
        $this->add('PUT',    '/api/planner/events/{id}', 'PlannerController', 'apiEventUpdate',  true);
        $this->add('DELETE', '/api/planner/events/{id}', 'PlannerController', 'apiEventDelete',  true);
        $this->add('GET',    '/api/planner/todos',       'PlannerController', 'apiTodosList',    true);
        $this->add('POST',   '/api/planner/todos',       'PlannerController', 'apiTodoCreate',   true);
        $this->add('PUT',    '/api/planner/todos/{id}',  'PlannerController', 'apiTodoUpdate',   true);
        $this->add('DELETE', '/api/planner/todos/{id}',  'PlannerController', 'apiTodoDelete',   true);
        $this->add('POST',   '/api/planner/todos/reorder','PlannerController','apiTodoReorder',  true);

        // --- API: Exercise ---
        $this->add('GET',    '/api/exercise',                 'ExerciseController', 'apiList',           true);
        $this->add('POST',   '/api/exercise',                 'ExerciseController', 'apiCreate',         true);
        $this->add('PUT',    '/api/exercise/{id}',            'ExerciseController', 'apiUpdate',         true);
        $this->add('DELETE', '/api/exercise/{id}',            'ExerciseController', 'apiDelete',         true);
        $this->add('GET',    '/api/exercise/stats',           'ExerciseController', 'apiStats',          true);
        $this->add('GET',    '/api/exercise/categories',      'ExerciseController', 'apiCategoriesList',   true);
        $this->add('POST',   '/api/exercise/categories',      'ExerciseController', 'apiCategoryCreate',   true);
        $this->add('PUT',    '/api/exercise/categories/{id}', 'ExerciseController', 'apiCategoryUpdate',   true);
        $this->add('DELETE', '/api/exercise/categories/{id}', 'ExerciseController', 'apiCategoryDelete',   true);

        // --- API: Finance ---
        $this->add('GET',    '/api/finance',                  'FinanceController', 'apiList',           true);
        $this->add('POST',   '/api/finance',                  'FinanceController', 'apiCreate',         true);
        $this->add('PUT',    '/api/finance/{id}',             'FinanceController', 'apiUpdate',         true);
        $this->add('DELETE', '/api/finance/{id}',             'FinanceController', 'apiDelete',         true);
        $this->add('GET',    '/api/finance/summary',          'FinanceController', 'apiSummary',        true);
        $this->add('GET',    '/api/finance/chart',            'FinanceController', 'apiChart',          true);
        $this->add('GET',    '/api/finance/categories',       'FinanceController', 'apiCategoriesList', true);
        $this->add('POST',   '/api/finance/categories',       'FinanceController', 'apiCategoryCreate', true);
        $this->add('PUT',    '/api/finance/categories/{id}',  'FinanceController', 'apiCategoryUpdate', true);
        $this->add('DELETE', '/api/finance/categories/{id}',  'FinanceController', 'apiCategoryDelete', true);

        // --- API: Subscriptions ---
        $this->add('GET',    '/api/subscriptions',            'SubscriptionController', 'apiList',   true);
        $this->add('POST',   '/api/subscriptions',            'SubscriptionController', 'apiCreate', true);
        $this->add('PUT',    '/api/subscriptions/{id}',       'SubscriptionController', 'apiUpdate', true);
        $this->add('DELETE', '/api/subscriptions/{id}',       'SubscriptionController', 'apiDelete', true);
        $this->add('POST',   '/api/subscriptions/{id}/renew', 'SubscriptionController', 'apiRenew',  true);

        // --- API: Files ---
        $this->add('GET',    '/api/files',                  'FileController', 'apiList',        true);
        $this->add('POST',   '/api/files/folder',           'FileController', 'apiFolder',      true);
        $this->add('POST',   '/api/files/upload',           'FileController', 'apiUpload',      true);
        $this->add('PUT',    '/api/files/{id}/rename',      'FileController', 'apiRename',      true);
        $this->add('PUT',    '/api/files/{id}/move',        'FileController', 'apiMove',        true);
        $this->add('DELETE', '/api/files/{id}',             'FileController', 'apiDelete',      true);
        $this->add('GET',    '/api/files/{id}/download',    'FileController', 'apiDownload',    true);
        $this->add('GET',    '/api/files/folders',          'FileController', 'apiFoldersList', true);

        // --- Share Links (public, no auth) ---
        $this->add('GET', '/share/{token}',                     'ShareController', 'viewShare',      false);
        $this->add('GET', '/share/{token}/download/{fileId}',   'ShareController', 'publicDownload', false);

        // --- API: Share Links (auth) ---
        $this->add('GET',    '/api/shares',      'ShareController', 'apiList',   true);
        $this->add('POST',   '/api/shares',      'ShareController', 'apiCreate', true);
        $this->add('PUT',    '/api/shares/{id}', 'ShareController', 'apiUpdate', true);
        $this->add('DELETE', '/api/shares/{id}', 'ShareController', 'apiDelete', true);

        // --- App Shares (Modules) ---
        $this->add('GET', '/shared/{token}',                     'AppShareController', 'viewShared', false);
        $this->add('GET', '/exit-share',                         'AppShareController', 'exitShare',  false);
        $this->add('GET',    '/api/app-shares',                  'AppShareController', 'apiList',    true);
        $this->add('POST',   '/api/app-shares',                  'AppShareController', 'apiCreate',  true);
        $this->add('DELETE', '/api/app-shares/{id}',             'AppShareController', 'apiDelete',  true);

        // --- Page: File Tools ---
        $this->add('GET',  '/file-tools',                    'FileToolsController', 'index',        true);

        // --- API: File Tools ---
        $this->add('POST', '/api/file-tools/image',          'FileToolsController', 'apiImage',     true);
        $this->add('POST', '/api/file-tools/zip/create',     'FileToolsController', 'apiZipCreate', true);
        $this->add('POST', '/api/file-tools/zip/inspect',    'FileToolsController', 'apiZipInspect',true);
        $this->add('POST', '/api/file-tools/zip/extract',    'FileToolsController', 'apiZipExtract',true);

        // --- Page & API: File Transfer ---
        $this->add('GET',    '/transfer',                    'FileTransferController', 'index',      true);
        $this->add('POST',   '/api/transfer/send',           'FileTransferController', 'apiSend',    true);
        $this->add('GET',    '/api/transfer',                'FileTransferController', 'apiList',    true);
        $this->add('DELETE', '/api/transfer/{id}',           'FileTransferController', 'apiDelete',  true);
        $this->add('POST',   '/api/transfer/receive',        'FileTransferController', 'apiReceive', false);
        $this->add('GET',    '/transfer/download/{token}',   'FileTransferController', 'download',   false);

        // --- API: Food Notes ---
        $this->add('GET',    '/api/food-notes',      'FoodNoteController', 'apiList',   true);
        $this->add('POST',   '/api/food-notes',      'FoodNoteController', 'apiCreate', true);
        $this->add('PUT',    '/api/food-notes/{id}', 'FoodNoteController', 'apiUpdate', true);
        $this->add('DELETE', '/api/food-notes/{id}', 'FoodNoteController', 'apiDelete', true);

        // --- API: Settings ---
        $this->add('GET',  '/api/settings',          'SettingsController', 'apiGet',      true);
        $this->add('POST', '/api/settings/profile',  'SettingsController', 'apiProfile',  true);
        $this->add('POST', '/api/settings/password', 'SettingsController', 'apiPassword', true);
        $this->add('POST', '/api/settings/theme',    'SettingsController', 'apiTheme',    true);
        $this->add('POST', '/api/settings/timezone', 'SettingsController', 'apiTimezone', true);
        $this->add('POST', '/api/settings/menus',    'SettingsController', 'apiMenus',    true);
        $this->add('POST', '/api/settings/telegram', 'SettingsController', 'apiTelegram', true);
        $this->add('POST', '/api/settings/telegram/test', 'SettingsController', 'apiTelegramTest', true);
        $this->add('POST', '/api/settings/telegram/cron', 'SettingsController', 'apiCronTest', true);
        $this->add('GET',  '/api/settings/export',   'SettingsController', 'apiExport',   true);
        $this->add('POST', '/api/settings/import',   'SettingsController', 'apiImport',   true);
        $this->add('POST', '/api/settings/delete',   'SettingsController', 'apiDeleteAccount', true);

        // --- API: AI ---
        $this->add('GET',    '/api/ai/keys',                  'AiController', 'apiKeysList',       true);
        $this->add('POST',   '/api/ai/keys',                  'AiController', 'apiKeysSave',       true);
        $this->add('POST',   '/api/ai/keys/test',             'AiController', 'apiKeysTest',       true);
        $this->add('DELETE', '/api/ai/keys/{provider}',       'AiController', 'apiKeysDelete',     true);
        $this->add('POST',   '/api/ai/script',                'AiController', 'apiGenerateScript', true);
        $this->add('POST',   '/api/ai/regenerate',            'AiController', 'apiRegenerateSection', true);
        $this->add('POST',   '/api/ai/video',                 'AiController', 'apiGenerateVideo',  true);
        $this->add('GET',    '/api/ai/video/{id}/status',     'AiController', 'apiVideoStatus',    true);
        $this->add('GET',    '/api/ai/history',               'AiController', 'apiHistory',        true);
        $this->add('DELETE', '/api/ai/history/{id}',          'AiController', 'apiHistoryDelete',  true);

        // --- Page & API: Skills (Time Tracker) ---
        $this->add('GET',    '/skills',                       'SkillController', 'index',          true);
        $this->add('GET',    '/api/skills',                   'SkillController', 'apiList',        true);
        $this->add('POST',   '/api/skills',                   'SkillController', 'apiCreate',      true);
        $this->add('PUT',    '/api/skills/{id}',              'SkillController', 'apiUpdate',      true);
        $this->add('DELETE', '/api/skills/{id}',              'SkillController', 'apiDelete',      true);
        $this->add('GET',    '/api/skills/logs',              'SkillController', 'apiLogsList',    true);
        $this->add('GET',    '/api/skills/stats',             'SkillController', 'apiStats',       true);
        $this->add('POST',   '/api/skills/timer/start',       'SkillController', 'apiStartTimer',  true);
        $this->add('POST',   '/api/skills/timer/stop',        'SkillController', 'apiStopTimer',   true);
        $this->add('POST',   '/api/skills/timer/update',      'SkillController', 'apiUpdateTimer', true);
        $this->add('DELETE', '/api/skills/logs/{id}',         'SkillController', 'apiDeleteLog',   true);

        // --- Page & API: Pomodoro Focus ---
        $this->add('GET',    '/focus',                        'FocusController', 'index',          true);
        $this->add('GET',    '/api/focus',                    'FocusController', 'apiList',        true);
        $this->add('POST',   '/api/focus',                    'FocusController', 'apiCreate',      true);
        $this->add('DELETE', '/api/focus/{id}',              'FocusController', 'apiDelete',      true);

        // --- Page: Stocks ---
        $this->add('GET',    '/stocks',                       'StocksController', 'index',         true);

        // --- API: Stocks transactions ---
        $this->add('GET',    '/api/stocks',                   'StocksController', 'apiList',       true);
        $this->add('POST',   '/api/stocks',                   'StocksController', 'apiCreate',     true);
        $this->add('PUT',    '/api/stocks/{id}',              'StocksController', 'apiUpdate',     true);
        $this->add('DELETE', '/api/stocks/{id}',              'StocksController', 'apiDelete',     true);

        // --- API: Stocks portfolio & pricing ---
        $this->add('GET',    '/api/stocks/summary',           'StocksController', 'apiSummary',    true);
        $this->add('GET',    '/api/stocks/chart',             'StocksController', 'apiChart',      true);
        $this->add('POST',   '/api/stocks/refresh',           'StocksController', 'apiRefresh',    true);
        $this->add('POST',   '/api/stocks/analyze',           'StocksController', 'apiAnalyze',    true);
        $this->add('GET',    '/api/stocks/watchlists',        'StocksController', 'apiWatchlists', true);
        $this->add('POST',   '/api/stocks/watchlists/toggle', 'StocksController', 'apiWatchlistToggle', true);

        // --- API: Stocks Capital & Screenshots ---
        $this->add('GET',    '/api/stocks/capital',           'StocksController', 'apiCapitalList',   true);
        $this->add('POST',   '/api/stocks/capital',           'StocksController', 'apiCapitalCreate', true);
        $this->add('PUT',    '/api/stocks/capital/{id}',      'StocksController', 'apiCapitalUpdate', true);
        $this->add('DELETE', '/api/stocks/capital/{id}',      'StocksController', 'apiCapitalDelete', true);
        $this->add('GET',    '/api/stocks/screenshots',       'StocksController', 'apiScreenshotList', true);
        $this->add('POST',   '/api/stocks/screenshots',       'StocksController', 'apiScreenshotUpload', true);
        $this->add('DELETE', '/api/stocks/screenshots/{id}',   'StocksController', 'apiScreenshotDelete', true);

        // --- API: Stocks API keys ---
        $this->add('GET',    '/api/stocks/keys',              'StocksController', 'apiKeysList',   true);
        $this->add('POST',   '/api/stocks/keys',              'StocksController', 'apiKeysSave',   true);
        $this->add('POST',   '/api/stocks/keys/test',         'StocksController', 'apiKeysTest',   true);
        $this->add('DELETE', '/api/stocks/keys/{provider}',   'StocksController', 'apiKeysDelete', true);

        // --- API: Projects & Kanban ---
        $this->add('GET',    '/api/projects',              'ProjectController', 'apiList',        true);
        $this->add('POST',   '/api/projects',              'ProjectController', 'apiCreate',      true);
        $this->add('PUT',    '/api/projects/{id}',         'ProjectController', 'apiUpdate',      true);
        $this->add('DELETE', '/api/projects/{id}',         'ProjectController', 'apiDelete',      true);
        $this->add('GET',    '/api/projects/{id}/tasks',   'ProjectController', 'apiTasksList',   true);
        $this->add('POST',   '/api/projects/{id}/tasks',   'ProjectController', 'apiTaskCreate',  true);
        $this->add('PUT',    '/api/projects/tasks/{tid}',  'ProjectController', 'apiTaskUpdate',  true);
        $this->add('DELETE', '/api/projects/tasks/{tid}',  'ProjectController', 'apiTaskDelete',  true);
        $this->add('POST',   '/api/projects/tasks/reorder','ProjectController', 'apiTaskReorder', true);

        // --- API: Projects Collaboration & Chat ---
        $this->add('GET',    '/api/projects/{id}/members',      'ProjectController', 'apiMemberList',   true);
        $this->add('POST',   '/api/projects/{id}/members',      'ProjectController', 'apiMemberAdd',    true);
        $this->add('DELETE', '/api/projects/{id}/members/{mid}', 'ProjectController', 'apiMemberRemove', true);
        $this->add('GET',    '/api/projects/{id}/chat',         'ProjectController', 'apiChatList',     true);
        $this->add('POST',   '/api/projects/{id}/chat',         'ProjectController', 'apiChatSend',     true);
        $this->add('POST',   '/api/projects/{id}/share',        'ProjectController', 'apiShareEnable',  true);
        $this->add('DELETE', '/api/projects/{id}/share',        'ProjectController', 'apiShareDisable', true);
        $this->add('POST',   '/api/projects/guest-name',        'ProjectController', 'apiSetGuestName', false);
    }

    private function add(string $method, string $pattern, string $controller, string $action, bool $auth): void
    {
        $this->routes[] = [$method, $pattern, $controller, $action, $auth];
    }

    /**
     * Match URI to a route and dispatch
     */
    public function dispatch(): void
    {
        $method = Request::method();
        $path   = Request::path();

        // Check for App Share Token in session
        if (!empty($_SESSION['app_share_token'])) {
            require_once dirname(__DIR__) . '/models/AppShare.php';
            $share = AppShare::getByToken($_SESSION['app_share_token']);
            if ($share && AppShare::isValid($share)) {
                $menus = json_decode($share['menus'], true) ?: [];
                Auth::setShareMode((int)$share['user_id'], $menus);
            } else {
                unset($_SESSION['app_share_token']);
            }
        }

        // Apply Read-Only restrictions
        if (Auth::isReadOnly()) {
            if (!in_array($method, ['GET', 'OPTIONS'])) {
                if (Request::isApi()) Response::json(['error' => 'โหมดแชร์ดูได้เท่านั้น (Read-only)'], 403);
                Response::abort(403, 'โหมดแชร์ดูได้เท่านั้น (Read-only)');
            }

            $allowed = Auth::getSharedMenus();
            $parts = explode('/', trim($path, '/'));
            $base = $parts[0] ?? '';
            if ($base === 'api') $base = $parts[1] ?? '';
            
            $alwaysAllowed = ['shared', 'exit-share', 'login', 'logout', 'dashboard', 'settings']; // Need settings for layout, but handled later if needed
            if (!in_array($base, $allowed) && !in_array($base, $alwaysAllowed) && $path !== '/') {
                if (Request::isApi()) Response::json(['error' => 'ไม่มีสิทธิ์เข้าถึงเมนูนี้'], 403);
                Response::abort(403, 'ไม่มีสิทธิ์เข้าถึงเมนูนี้');
            }
        }

        // Handle _method override for PUT/DELETE from forms
        if ($method === 'POST' && !empty($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        foreach ($this->routes as [$routeMethod, $pattern, $controller, $action, $requiresAuth]) {
            if ($routeMethod !== $method) continue;

            $params = $this->matchPattern($pattern, $path);
            if ($params === null) continue;

            // CSRF check for state-changing requests
            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
                $token = Csrf::fromRequest();
                if (!Csrf::verify($token)) {
                    Response::abort(403, 'CSRF token ไม่ถูกต้อง');
                }
            }

            // Auth check
            if ($requiresAuth) {
                Auth::requireLogin();
            }

            // Load and dispatch controller
            $controllerFile = dirname(__DIR__) . '/controllers/' . $controller . '.php';
            if (!file_exists($controllerFile)) {
                Response::abort(500, 'Controller not found: ' . $controller);
            }
            require_once $controllerFile;

            $obj = new $controller();
            $obj->$action(...array_values($params));
            return;
        }

        // No route matched
        Response::abort(404, 'ไม่พบหน้าที่ต้องการ');
    }

    /**
     * Match a path against a pattern with {param} wildcards
     * Returns array of captured params, or null if no match
     */
    private function matchPattern(string $pattern, string $path): ?array
    {
        // Exact match fast path
        if ($pattern === $path) return [];

        // Convert pattern to regex
        $regex = preg_replace('/\{[a-z_]+\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        // Extract param names from pattern
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $names);
        $paramNames = $names[1];

        if (!preg_match($regex, $path, $matches)) return null;

        array_shift($matches); // remove full match
        return array_combine($paramNames, $matches);
    }
}
