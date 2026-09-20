<?php
/**
 * public/login.php
 *
 * User login page.
 * Redirects to the index or dashboard if already authenticated.
 * Supports both Fetch API submission and standard POST form fallback.
 */

declare(strict_types=1);

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';

use App\Auth;

// Already logged in? Redirect
if (Auth::isLoggedIn()) {
    header('Location: index.php', true, 302);
    exit;
}

// Derive URLs
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseUrl = $scheme . '://' . $host . $base;
$apiUrl  = $baseUrl . '/api/auth.php';

// Generate CSRF token
$csrfToken = Auth::csrfToken();

$serverError = '';
$redirectUrl = filter_input(INPUT_GET, 'redirect', FILTER_SANITIZE_URL) ?: ($baseUrl . '/dashboard.php');
if (!str_starts_with($redirectUrl, $baseUrl) && !str_starts_with($redirectUrl, '/')) {
    $redirectUrl = $baseUrl . '/dashboard.php';
}

// Server-side POST fallback (if JavaScript is disabled)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token      = (string) ($_POST['_csrf'] ?? '');
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');

    if (!Auth::validateCsrf($token)) {
        $serverError = 'Invalid or expired session token. Please try again.';
    } else {
        try {
            $pdo  = getDbConnection();
            $auth = new Auth($pdo);
            $res  = $auth->login($identifier, $password);

            if ($res['ok']) {
                $dest = !empty($_POST['redirect']) ? (string) $_POST['redirect'] : $redirectUrl;
                if (!str_starts_with($dest, $baseUrl) && !str_starts_with($dest, '/')) {
                    $dest = $baseUrl . '/index.php';
                }
                header('Location: ' . $dest, true, 302);
                exit;
            } else {
                $serverError = $res['error'] ?? 'Invalid username or password.';
            }
        } catch (\Throwable $e) {
            error_log('[login.php] Error: ' . $e->getMessage());
            $serverError = 'A temporary error occurred. Please try again.';
        }
    }
}

// Notice banners from query parameters
$loggedOut  = isset($_GET['logged_out']);
$registered = isset($_GET['registered']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>Sign In &mdash; SnapLink</title>
    <meta name="description" content="Sign in to your SnapLink account to view and manage your short links." />
    <meta name="robots" content="noindex, nofollow" />

    <!-- Tailwind CSS (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:  '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                        },
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.45s cubic-bezier(0.16,1,0.3,1) both',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%':   { opacity: '0', transform: 'translateY(14px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                    },
                },
            },
        };
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet" />

    <style>
        .spinner {
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.65s linear infinite;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .eye-btn { color: #94a3b8; transition: color 0.15s; }
        .eye-btn:hover { color: #475569; }
    </style>
</head>

<body class="bg-slate-50 font-sans antialiased text-slate-900 min-h-screen flex">

    <!-- ── Left panel: Branding ── -->
    <div class="hidden lg:flex flex-col w-[440px] flex-shrink-0 bg-slate-900 border-r border-slate-800
                relative overflow-hidden">

        <!-- Grid bg pattern -->
        <div class="absolute inset-0 opacity-[0.06]"
             style="background-image:linear-gradient(rgba(255,255,255,0.3) 1px,transparent 1px),
                    linear-gradient(90deg,rgba(255,255,255,0.3) 1px,transparent 1px);
                    background-size:40px 40px;">
        </div>

        <div class="relative flex flex-col h-full px-12 py-10">
            <!-- Logo -->
            <a href="index.php" id="nav-logo" class="flex items-center gap-2.5 group">
                <div class="w-7 h-7 bg-white rounded-md flex items-center justify-center
                            group-hover:bg-brand-400 transition-colors duration-200">
                    <svg class="w-4 h-4 text-slate-900" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                </div>
                <span class="text-sm font-bold text-white tracking-tight">SnapLink</span>
            </a>

            <!-- Center content -->
            <div class="flex-1 flex flex-col justify-center gap-8 py-12">
                <div>
                    <h2 class="text-3xl font-extrabold text-white tracking-tight leading-tight mb-4">
                        Track every click.<br />
                        <span class="text-brand-400">Own your links.</span>
                    </h2>
                    <p class="text-slate-400 text-sm leading-relaxed max-w-[280px]">
                        Sign in to access your full analytics dashboard, manage links, and
                        monitor real-time click data.
                    </p>
                </div>

                <!-- Feature list -->
                <ul class="space-y-3">
                    <?php foreach ([
                        'Real-time click analytics',
                        'Device & browser breakdown',
                        'Link management dashboard',
                        'Expiry date controls',
                    ] as $f): ?>
                        <li class="flex items-center gap-3 text-sm text-slate-300">
                            <div class="w-4 h-4 rounded-full bg-brand-600/20 border border-brand-500/30
                                        flex items-center justify-center flex-shrink-0">
                                <svg class="w-2.5 h-2.5 text-brand-400" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <?= $f ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Footer note -->
            <p class="text-[11px] text-slate-600 font-medium">
                &copy; <?= date('Y') ?> SnapLink &middot; Free forever
            </p>
        </div>
    </div>

    <!-- ── Right panel: Form ── -->
    <div class="flex-1 flex flex-col">

        <!-- Mobile header -->
        <div class="lg:hidden border-b border-slate-200 bg-white px-6 h-14 flex items-center">
            <a href="index.php" id="nav-logo-mobile" class="flex items-center gap-2 group">
                <div class="w-6 h-6 bg-slate-900 rounded-md flex items-center justify-center
                            group-hover:bg-brand-600 transition-colors duration-200">
                    <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                </div>
                <span class="text-sm font-bold text-slate-900">SnapLink</span>
            </a>
        </div>

        <div class="flex-1 flex items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm animate-fade-in-up">

                <!-- Form header -->
                <div class="mb-8">
                    <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 mb-1.5">
                        Welcome back
                    </h1>
                    <p class="text-sm text-slate-500">
                        Sign in to manage your short links and analytics.
                    </p>
                </div>

                <!-- Banners -->
                <?php if ($loggedOut): ?>
                <div id="logged-out-alert" role="status"
                     class="mb-5 flex items-center gap-2.5 px-3.5 py-3 rounded-lg bg-blue-50
                            border border-blue-200 text-sm text-blue-700">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>You have been signed out safely.</span>
                </div>
                <?php endif; ?>

                <?php if ($registered): ?>
                <div id="registered-alert" role="status"
                     class="mb-5 flex items-center gap-2.5 px-3.5 py-3 rounded-lg bg-emerald-50
                            border border-emerald-200 text-sm text-emerald-700">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>Account created! Please sign in.</span>
                </div>
                <?php endif; ?>

                <!-- Error alert -->
                <div id="global-error" role="alert" aria-live="assertive"
                     class="<?= !empty($serverError) ? '' : 'hidden' ?> mb-5 flex items-start gap-2.5
                            px-3.5 py-3 rounded-lg bg-red-50 border border-red-200 text-sm">
                    <svg class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span id="global-error-text" class="text-red-600">
                        <?= htmlspecialchars($serverError, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <!-- Login form -->
                <form id="login-form" method="POST" action="login.php" novalidate
                      aria-label="Login form" class="space-y-4">
                    <input type="hidden" name="_csrf"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="action" value="login" />
                    <input type="hidden" name="redirect"
                           value="<?= htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') ?>" />

                    <!-- Identifier -->
                    <div id="field-identifier" class="space-y-1.5">
                        <label for="identifier"
                               class="block text-sm font-medium text-slate-700">
                            Email or Username
                        </label>
                        <div class="relative">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2
                                        text-slate-400 pointer-events-none">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <input id="identifier" name="identifier" type="text"
                                   inputmode="text" autocomplete="username" spellcheck="false"
                                   placeholder="name@domain.com or username"
                                   required aria-describedby="identifier-error"
                                   class="w-full bg-white border border-slate-200 rounded-lg
                                          pl-9 pr-4 py-2.5 text-sm text-slate-900
                                          placeholder-slate-400 font-medium
                                          focus:outline-none focus:ring-2 focus:ring-brand-500
                                          focus:border-brand-500 transition-all duration-200" />
                        </div>
                        <p id="identifier-error" role="alert"
                           class="hidden text-red-500 text-xs font-medium"></p>
                    </div>

                    <!-- Password -->
                    <div id="field-password" class="space-y-1.5">
                        <label for="password"
                               class="block text-sm font-medium text-slate-700">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2
                                        text-slate-400 pointer-events-none">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <input id="password" name="password" type="password"
                                   autocomplete="current-password"
                                   placeholder="Your password"
                                   required aria-describedby="password-error"
                                   class="w-full bg-white border border-slate-200 rounded-lg
                                          pl-9 pr-10 py-2.5 text-sm text-slate-900
                                          placeholder-slate-400 font-medium
                                          focus:outline-none focus:ring-2 focus:ring-brand-500
                                          focus:border-brand-500 transition-all duration-200" />
                            <button id="toggle-password" type="button"
                                    aria-label="Toggle password visibility"
                                    class="eye-btn absolute right-3 top-1/2 -translate-y-1/2">
                                <svg id="eye-open" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg id="eye-closed" class="w-4 h-4 hidden" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                            </button>
                        </div>
                        <p id="password-error" role="alert"
                           class="hidden text-red-500 text-xs font-medium"></p>
                    </div>

                    <!-- Submit -->
                    <button id="login-btn" type="submit"
                            class="w-full flex items-center justify-center gap-2 px-5 py-2.5
                                   bg-slate-900 hover:bg-brand-600 text-white text-sm font-semibold
                                   rounded-lg transition-all duration-150 shadow-sm mt-2
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="login-btn-text">Sign In</span>
                        <span id="login-btn-spinner" class="spinner hidden" aria-hidden="true"></span>
                        <svg id="login-btn-icon" class="w-3.5 h-3.5" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </button>
                </form>

                <!-- Divider -->
                <div class="flex items-center gap-3 my-6">
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-xs text-slate-400">or</span>
                    <div class="flex-1 h-px bg-slate-200"></div>
                </div>

                <!-- Sign up link -->
                <p class="text-center text-sm text-slate-500">
                    Don't have an account?
                    <a href="register.php" id="link-to-register"
                       class="font-semibold text-brand-600 hover:text-brand-700
                              transition-colors duration-150 ml-1">
                        Sign up free
                    </a>
                </p>

                <p class="text-center text-xs text-slate-400 mt-6">
                    <a href="index.php" class="hover:text-slate-600 transition-colors duration-150">
                        &larr; Back to SnapLink home
                    </a>
                </p>
            </div>
        </div>
    </div>

    <!-- Pass configuration to JS -->
    <script>
        window.SnapLink = {
            apiUrl:      <?= json_encode($apiUrl,      JSON_UNESCAPED_SLASHES) ?>,
            baseUrl:     <?= json_encode($baseUrl,     JSON_UNESCAPED_SLASHES) ?>,
            csrf:        <?= json_encode($csrfToken) ?>,
            redirectUrl: <?= json_encode($redirectUrl, JSON_UNESCAPED_SLASHES) ?>,
        };
    </script>
    <script src="../assets/js/login.js" defer></script>
</body>
</html>