<?php
declare(strict_types=1);

/**
 * public/register.php
 *
 * User registration page.
 * Redirects to the index or dashboard if already authenticated.
 * Submits via Fetch API to api/auth.php (action=register) or standard POST.
 */

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';

use App\Auth;

// Already logged in? Go to index.php
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

$serverErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = (string) ($_POST['_csrf'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!Auth::validateCsrf($token)) {
        $serverErrors[] = 'Invalid or expired session token. Please try again.';
    } else {
        try {
            $pdo  = getDbConnection();
            $auth = new Auth($pdo);
            $res  = $auth->register($username, $email, $password);

            if ($res['ok']) {
                $auth->login($email, $password);
                header('Location: dashboard.php', true, 302);
                exit;
            } else {
                $serverErrors = $res['errors'] ?? ['Registration failed.'];
            }
        } catch (\Throwable $e) {
            error_log('[register.php] Error: ' . $e->getMessage());
            $serverErrors[] = 'A temporary error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>Create Account &mdash; SnapLink</title>
    <meta name="description"
          content="Sign up for a free SnapLink account to manage your short links and view click analytics." />
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
        .strength-bar { transition: width 0.3s ease, background-color 0.3s ease; }
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
                        Shorten. Share.<br />
                        <span class="text-brand-400">Understand.</span>
                    </h2>
                    <p class="text-slate-400 text-sm leading-relaxed max-w-[280px]">
                        Create a free account and get instant access to link analytics,
                        management tools, and expiry controls.
                    </p>
                </div>

                <!-- Stats preview -->
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ([
                        ['label' => 'Free plan', 'val' => 'Forever'],
                        ['label' => 'Setup time', 'val' => '< 30 sec'],
                        ['label' => 'Link limit', 'val' => 'Unlimited'],
                        ['label' => 'Credit card', 'val' => 'Not needed'],
                    ] as $stat): ?>
                        <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                            <p class="text-[10px] text-slate-500 font-medium uppercase tracking-wider mb-0.5">
                                <?= $stat['label'] ?>
                            </p>
                            <p class="text-sm font-bold text-white"><?= $stat['val'] ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Footer note -->
            <p class="text-[11px] text-slate-600 font-medium">
                &copy; <?= date('Y') ?> SnapLink &middot; Free forever
            </p>
        </div>
    </div>

    <!-- ── Right panel: Form -->
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
                        Create your account
                    </h1>
                    <p class="text-sm text-slate-500">
                        Free forever. No credit card required.
                    </p>
                </div>

                <!-- Error alert -->
                <div id="global-error" role="alert" aria-live="assertive"
                     class="<?= !empty($serverErrors) ? '' : 'hidden' ?> mb-5 flex items-start gap-2.5
                            px-3.5 py-3 rounded-lg bg-red-50 border border-red-200 text-sm">
                    <svg class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <ul id="global-error-list" class="text-red-600 space-y-0.5">
                        <?php foreach ($serverErrors as $err): ?>
                            <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Success alert -->
                <div id="global-success" role="status" aria-live="polite"
                     class="hidden mb-5 flex items-center gap-2.5 px-3.5 py-3 rounded-lg
                            bg-emerald-50 border border-emerald-200 text-sm text-emerald-700">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span id="global-success-text"></span>
                </div>

                <!-- Registration form -->
                <form id="register-form" method="POST" action="register.php" novalidate
                      aria-label="Registration form" class="space-y-4">
                    <input type="hidden" name="_csrf"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="action" value="register" />

                    <!-- Username -->
                    <div id="field-username" class="space-y-1.5">
                        <label for="username"
                               class="block text-sm font-medium text-slate-700">Username</label>
                        <div class="relative">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2
                                        text-slate-400 pointer-events-none">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                            </div>
                            <input id="username" name="username" type="text"
                                   inputmode="text" autocomplete="username" spellcheck="false"
                                   placeholder="e.g. johndoe_42" maxlength="50" required
                                   aria-describedby="username-error"
                                   class="w-full bg-white border border-slate-200 rounded-lg
                                          pl-9 pr-4 py-2.5 text-sm text-slate-900
                                          placeholder-slate-400 font-medium
                                          focus:outline-none focus:ring-2 focus:ring-brand-500
                                          focus:border-brand-500 transition-all duration-200" />
                        </div>
                        <p id="username-error" role="alert"
                           class="hidden text-red-500 text-xs font-medium"></p>
                    </div>

                    <!-- Email -->
                    <div id="field-email" class="space-y-1.5">
                        <label for="email"
                               class="block text-sm font-medium text-slate-700">Email address</label>
                        <div class="relative">
                            <div class="absolute left-3 top-1/2 -translate-y-1/2
                                        text-slate-400 pointer-events-none">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <input id="email" name="email" type="email" inputmode="email"
                                   autocomplete="email" placeholder="you@example.com"
                                   maxlength="255" required aria-describedby="email-error"
                                   class="w-full bg-white border border-slate-200 rounded-lg
                                          pl-9 pr-4 py-2.5 text-sm text-slate-900
                                          placeholder-slate-400 font-medium
                                          focus:outline-none focus:ring-2 focus:ring-brand-500
                                          focus:border-brand-500 transition-all duration-200" />
                        </div>
                        <p id="email-error" role="alert"
                           class="hidden text-red-500 text-xs font-medium"></p>
                    </div>

                    <!-- Password -->
                    <div id="field-password" class="space-y-1.5">
                        <label for="password"
                               class="block text-sm font-medium text-slate-700">Password</label>
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
                                   autocomplete="new-password"
                                   placeholder="Min. 8 characters"
                                   minlength="8" maxlength="72" required
                                   aria-describedby="password-error password-strength-label"
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

                        <!-- Strength bar -->
                        <div class="h-1 w-full bg-slate-100 rounded-full overflow-hidden mt-1.5"
                             aria-hidden="true">
                            <div id="strength-bar"
                                 class="strength-bar h-full w-0 rounded-full bg-red-500"></div>
                        </div>
                        <p id="password-strength-label" class="text-[11px] text-slate-400"></p>
                        <p id="password-error" role="alert"
                           class="hidden text-red-500 text-xs font-medium"></p>
                    </div>

                    <!-- Submit -->
                    <button id="register-btn" type="submit"
                            class="w-full flex items-center justify-center gap-2 px-5 py-2.5
                                   bg-slate-900 hover:bg-brand-600 text-white text-sm font-semibold
                                   rounded-lg transition-all duration-150 shadow-sm mt-2
                                   disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="register-btn-text">Create my account</span>
                        <span id="register-btn-spinner" class="spinner hidden" aria-hidden="true"></span>
                        <svg id="register-btn-icon" class="w-3.5 h-3.5" fill="none"
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

                <!-- Login link -->
                <p class="text-center text-sm text-slate-500">
                    Already have an account?
                    <a href="login.php" id="link-to-login"
                       class="font-semibold text-brand-600 hover:text-brand-700
                              transition-colors duration-150 ml-1">
                        Sign in
                    </a>
                </p>

                <p class="text-center text-xs text-slate-400 mt-6">
                    By creating an account you agree to our
                    <a href="/terms" class="underline hover:text-slate-600 transition-colors">Terms</a>
                    and
                    <a href="/privacy" class="underline hover:text-slate-600 transition-colors">Privacy Policy</a>.
                </p>
            </div>
        </div>
    </div>

    <!-- Pass config to JS -->
    <script>
        window.SnapLink = {
            apiUrl:  <?= json_encode($apiUrl,  JSON_UNESCAPED_SLASHES) ?>,
            baseUrl: <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>,
            csrf:    <?= json_encode($csrfToken) ?>,
        };
    </script>
    <script src="../assets/js/register.js" defer></script>
</body>
</html>
