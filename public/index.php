<?php

/**
 * public/index.php
 *
 * Main public landing page — served for every route that is not:
 *   /s/{code}      (short-link redirect, handled by redirect.php)
 *   /api/*         (JSON API endpoints)
 *
 * Supports both guest visitors and authenticated users.
 * Auth state is detected via $_SESSION['user_id'] / $_SESSION['username'].
 */

declare(strict_types=1);

session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$username   = $isLoggedIn ? htmlspecialchars((string) $_SESSION['username'], ENT_QUOTES, 'UTF-8') : '';

// Derive the public base URL so JS can call the correct API path.
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseUrl = $scheme . '://' . $host . $base;          // e.g. http://localhost/URL/public
$apiUrl  = $baseUrl . '/api/shorten.php';

// -------------------------------------------------------------------------
// Live Analytics Preview — queries across ALL users from the database.
// All queries handle empty database states gracefully without PHP errors.
// -------------------------------------------------------------------------
$totalLinks     = 0;
$totalClicks    = 0;
$heroDailyBars  = [];
$heroDevices    = [
    ['label' => 'Desktop', 'pct' => 0, 'color' => 'bg-brand-500'],
    ['label' => 'Mobile',  'pct' => 0, 'color' => 'bg-violet-400'],
    ['label' => 'Tablet',  'pct' => 0, 'color' => 'bg-slate-300'],
];
$heroSources    = [];

try {
    require_once dirname(__DIR__) . '/config/db.php';
    $pdo = getDbConnection();

    // 1. Total links & clicks
    $stmtLinks   = $pdo->query("SELECT COUNT(*) FROM urls");
    $totalLinks  = (int) $stmtLinks->fetchColumn();

    $stmtClicks  = $pdo->query("SELECT COUNT(*) FROM clicks");
    $totalClicks = (int) $stmtClicks->fetchColumn();

    // 2. Clicks over time (last 14 days)
    $dailyRows = $pdo->query("SELECT DATE(clicked_at) AS day, COUNT(*) AS cnt
                               FROM clicks
                              WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                              GROUP BY day
                              ORDER BY day ASC")->fetchAll(PDO::FETCH_ASSOC);

    $dailyMap = [];
    foreach ($dailyRows as $r) {
        $dailyMap[$r['day']] = (int) $r['cnt'];
    }
    $maxDailyClicks = max(1, max(array_values($dailyMap) ?: [1]));
    for ($i = 13; $i >= 0; $i--) {
        $day   = date('Y-m-d', strtotime("-{$i} days"));
        $count = $dailyMap[$day] ?? 0;
        $pct   = ($totalClicks > 0 && $count > 0) ? max(8, (int) round(($count / $maxDailyClicks) * 100)) : 4;
        $heroDailyBars[] = [
            'pct'   => $pct,
            'count' => $count,
            'label' => date('D', strtotime($day)),
        ];
    }

    // 3. Device Breakdown (Desktop, Mobile, Tablet)
    $deviceCounts = [
        'Desktop' => 0,
        'Mobile'  => 0,
        'Tablet'  => 0,
    ];

    $deviceRows = $pdo->query("SELECT 
        CASE
            WHEN user_agent REGEXP 'ipad|tablet|playbook|silk|kindle' THEN 'Tablet'
            WHEN user_agent REGEXP 'mobile|iphone|ipod|android|blackberry|opera mini|iemobile|wpdesktop' THEN 'Mobile'
            ELSE 'Desktop'
        END AS device,
        COUNT(*) AS count
    FROM clicks
    GROUP BY device")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deviceRows as $row) {
        $dev = (string) $row['device'];
        if (isset($deviceCounts[$dev])) {
            $deviceCounts[$dev] = (int) $row['count'];
        } else {
            $deviceCounts['Desktop'] += (int) $row['count'];
        }
    }

    $deviceTotal = array_sum($deviceCounts);
    $devicePercentages = [
        'Desktop' => $deviceTotal > 0 ? (int) round(($deviceCounts['Desktop'] / $deviceTotal) * 100) : 0,
        'Mobile'  => $deviceTotal > 0 ? (int) round(($deviceCounts['Mobile']  / $deviceTotal) * 100) : 0,
        'Tablet'  => $deviceTotal > 0 ? (int) round(($deviceCounts['Tablet']  / $deviceTotal) * 100) : 0,
    ];

    // Ensure non-empty percentage values sum cleanly to 100%
    if ($deviceTotal > 0) {
        $diff = 100 - array_sum($devicePercentages);
        if ($diff !== 0) {
            $devicePercentages['Desktop'] += $diff;
        }
    }

    $heroDevices = [
        ['label' => 'Desktop', 'pct' => $devicePercentages['Desktop'], 'color' => 'bg-brand-500'],
        ['label' => 'Mobile',  'pct' => $devicePercentages['Mobile'],  'color' => 'bg-violet-400'],
        ['label' => 'Tablet',  'pct' => $devicePercentages['Tablet'],  'color' => 'bg-slate-300'],
    ];

    // 4. Top 4 Sources (referrer domains, Direct for empty)
    $refRows = $pdo->query("SELECT 
        CASE
            WHEN referrer IS NULL OR TRIM(referrer) = '' THEN 'Direct'
            WHEN referrer REGEXP '^https?://' THEN
                REGEXP_REPLACE(
                    SUBSTRING_INDEX(SUBSTRING(referrer, INSTR(referrer, '://') + 3), '/', 1),
                    '^www\\.', ''
                )
            ELSE SUBSTRING(referrer, 1, 50)
        END AS source,
        COUNT(*) AS count
    FROM clicks
    GROUP BY source
    ORDER BY count DESC
    LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($refRows as $r) {
        $heroSources[] = [
            'source' => (string) $r['source'],
            'count'  => (int) $r['count'],
        ];
    }
} catch (\Throwable $e) {
    error_log('[index.php] Live analytics error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <!-- SEO -->
    <title>SnapLink — Shorten URLs Instantly, Track Every Click</title>
    <meta name="description"
        content="SnapLink is a blazing-fast URL shortener. Paste any long link, get a beautiful short URL in seconds, and unlock powerful click analytics." />
    <meta name="keywords" content="url shortener, short link, link analytics, free url shortener" />
    <meta name="robots" content="index, follow" />

    <!-- Open Graph -->
    <meta property="og:title" content="SnapLink — Shorten URLs Instantly" />
    <meta property="og:description" content="Paste any long URL and get a powerful short link with click analytics." />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" />

    <!-- Tailwind CSS (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50:  '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            800: '#3730a3',
                            900: '#312e81',
                        },
                    },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.5s cubic-bezier(0.16,1,0.3,1) both',
                        'fade-in':    'fadeIn 0.35s ease both',
                        'blink':      'blink 1.2s step-end infinite',
                        'bar-grow':   'barGrow 1.2s cubic-bezier(0.4,0,0.2,1) both',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%':   { opacity: '0', transform: 'translateY(18px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        fadeIn: {
                            '0%':   { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        blink: {
                            '0%,100%': { opacity: '1' },
                            '50%':     { opacity: '0' },
                        },
                        barGrow: {
                            '0%':   { transform: 'scaleY(0)', transformOrigin: 'bottom' },
                            '100%': { transform: 'scaleY(1)', transformOrigin: 'bottom' },
                        },
                    },
                },
            },
        };
    </script>

    <!-- Google Fonts — Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />

    <style>
        /* ── Spinner ── */
        .spinner {
            width: 15px; height: 15px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.65s linear infinite;
            flex-shrink: 0;
        }
        .spinner-dark {
            border-color: rgba(79,70,229,0.25);
            border-top-color: #4f46e5;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Chart bar ── */
        .chart-bar { transform-origin: bottom; }

        /* ── Subtle grid bg pattern ── */
        .grid-bg {
            background-color: #fafafa;
            background-image:
                linear-gradient(rgba(226,232,240,0.5) 1px, transparent 1px),
                linear-gradient(90deg, rgba(226,232,240,0.5) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        /* ── Cursor blink on hero ── */
        .cursor { animation: blink 1.2s step-end infinite; }

        /* ── Hero right panel chart bars ── */
        .animate-bar { animation: barGrow 0.8s cubic-bezier(0.4,0,0.2,1) both; }
    </style>
</head>

<body class="bg-slate-50 font-sans antialiased text-slate-900 overflow-x-hidden">

    <!-- navigation -->
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-slate-200">
        <nav class="max-w-[1200px] mx-auto px-6 h-14 flex items-center justify-between gap-6">

            <!-- Logo -->
            <a href="index.php" id="nav-logo" class="flex items-center gap-2 flex-shrink-0 group">
                <div class="w-6 h-6 bg-slate-900 rounded-md flex items-center justify-center
                            group-hover:bg-brand-600 transition-colors duration-200">
                    <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                </div>
                <span class="text-sm font-bold tracking-tight text-slate-900">SnapLink</span>
            </a>

            <!-- Nav links -->
            <div class="hidden md:flex items-center gap-0.5">
                <a href="#features"
                   class="text-[13px] font-medium text-slate-500 hover:text-slate-900
                          hover:bg-slate-100 px-3 py-1.5 rounded-md transition-all duration-150">
                    Features
                </a>
                <a href="#how-it-works"
                   class="text-[13px] font-medium text-slate-500 hover:text-slate-900
                          hover:bg-slate-100 px-3 py-1.5 rounded-md transition-all duration-150">
                    How it works
                </a>
                <?php if ($isLoggedIn): ?>
                    <a href="dashboard.php"
                       class="text-[13px] font-medium text-slate-500 hover:text-slate-900
                              hover:bg-slate-100 px-3 py-1.5 rounded-md transition-all duration-150">
                        Dashboard
                    </a>
                <?php endif; ?>
            </div>

            <!-- Auth actions -->
            <div class="flex items-center gap-2">
                <?php if ($isLoggedIn): ?>
                    <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-100
                                border border-slate-200 text-[13px] font-medium text-slate-700">
                        <span class="w-5 h-5 rounded-full bg-brand-600 flex items-center justify-center
                                     text-[10px] font-bold text-white uppercase">
                            <?= mb_substr($username, 0, 1) ?>
                        </span>
                        <?= $username ?>
                    </div>
                    <a href="logout.php" id="nav-logout"
                       class="text-[13px] font-medium text-slate-500 hover:text-slate-900
                              hover:bg-slate-100 px-3 py-1.5 rounded-md border border-slate-200
                              transition-all duration-150">
                        Log out
                    </a>
                <?php else: ?>
                    <a href="login.php" id="nav-login"
                       class="text-[13px] font-medium text-slate-500 hover:text-slate-900
                              hover:bg-slate-100 px-3 py-1.5 rounded-md transition-all duration-150">
                        Sign in
                    </a>
                    <a href="register.php" id="nav-register"
                       class="text-[13px] font-semibold text-white bg-slate-900
                              hover:bg-brand-600 px-4 py-2 rounded-lg
                              transition-all duration-150 shadow-sm">
                        Sign up free
                    </a>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main>
        <!-- ══════════════════════════════════════════════════════
             HERO SECTION — 2-column grid
        ══════════════════════════════════════════════════════ -->
        <section id="hero" class="border-b border-slate-200">
            <div class="max-w-[1200px] mx-auto">
                <div class="grid grid-cols-1 md:grid-cols-2 md:divide-x divide-slate-200">

                    <!-- Left column: Form -->
                    <div class="px-8 py-16 md:py-24 lg:px-16 flex flex-col justify-center">

                        <!-- Label pill -->
                        <div class="animate-fade-in-up inline-flex items-center gap-1.5 mb-6 self-start"
                             style="animation-delay:0.05s">
                            <span class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-brand-50
                                         border border-brand-200 text-[11px] font-semibold text-brand-600
                                         tracking-wide uppercase">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Free &middot; No account required
                            </span>
                        </div>

                        <!-- Headline -->
                        <h1 class="animate-fade-in-up text-[2.75rem] sm:text-5xl font-extrabold
                                   leading-[1.08] tracking-tight text-slate-900 mb-4"
                            style="animation-delay:0.1s">
                            Shorten Links.<br />
                            <span class="text-brand-600">Track Engagement.</span>
                        </h1>

                        <!-- Subtitle -->
                        <p class="animate-fade-in-up text-base text-slate-500 leading-relaxed mb-8 max-w-sm"
                           style="animation-delay:0.16s">
                            Fast, secure URL shortening with real-time geographic and device analytics.
                            Paste a URL, go.
                        </p>

                        <!-- URL Shorten Form -->
                        <div class="animate-fade-in-up" style="animation-delay:0.22s">
                            <form id="shorten-form" novalidate aria-label="URL shortener form">

                                <!-- Input row -->
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <div class="relative flex-1">
                                        <div class="absolute left-3 top-1/2 -translate-y-1/2
                                                    text-slate-400 pointer-events-none">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                                 stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                        </div>
                                        <input
                                            id="url-input"
                                            name="url"
                                            type="url"
                                            inputmode="url"
                                            autocomplete="url"
                                            spellcheck="false"
                                            placeholder="https://your-very-long-url.com/paste-it-here"
                                            required
                                            aria-label="Long URL to shorten"
                                            aria-describedby="url-error"
                                            class="w-full bg-white border border-slate-200 rounded-lg
                                                   pl-10 pr-4 py-3 text-sm text-slate-900
                                                   placeholder-slate-400 font-medium
                                                   focus:outline-none focus:ring-2 focus:ring-brand-500
                                                   focus:border-brand-500 transition-all duration-200
                                                   shadow-sm" />
                                    </div>

                                    <button
                                        id="shorten-btn"
                                        type="submit"
                                        aria-label="Shorten URL"
                                        class="flex items-center justify-center gap-2 px-5 py-3
                                               bg-slate-900 hover:bg-brand-600 text-white text-sm font-semibold
                                               rounded-lg transition-all duration-200 shadow-sm
                                               disabled:opacity-50 disabled:cursor-not-allowed
                                               whitespace-nowrap min-w-[130px]">
                                        <span id="btn-text">Shorten it</span>
                                        <span id="btn-spinner" class="spinner spinner-white hidden"
                                              aria-hidden="true"></span>
                                        <svg id="btn-icon" class="w-3.5 h-3.5" fill="none"
                                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                        </svg>
                                    </button>
                                </div>

                                <!-- Validation error -->
                                <p id="url-error" role="alert" aria-live="assertive"
                                   class="hidden text-red-500 text-xs font-medium mt-2"></p>

                                <!-- Expiry toggle -->
                                <div class="flex items-center gap-2 mt-3">
                                    <button id="toggle-expiry" type="button"
                                            aria-expanded="false" aria-controls="expiry-row"
                                            class="flex items-center gap-1.5 text-xs text-slate-400
                                                   hover:text-slate-600 transition-colors duration-150">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                             stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 6v6l4 2m4-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Set expiry date (optional)
                                    </button>
                                </div>

                                <!-- Expiry date row -->
                                <div id="expiry-row" class="hidden mt-2">
                                    <input
                                        id="expiry-input"
                                        name="expiry_date"
                                        type="date"
                                        aria-label="Link expiry date"
                                        class="bg-white border border-slate-200 rounded-lg px-3 py-2
                                               text-sm text-slate-900 focus:outline-none
                                               focus:ring-2 focus:ring-brand-500 focus:border-brand-500
                                               transition-all duration-200" />
                                </div>
                            </form>

                            <!-- Result Panel -->
                            <div id="result-panel" role="region" aria-label="Shortened URL result"
                                 aria-live="polite"
                                 class="hidden mt-4 bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                                <p class="text-[11px] font-semibold text-slate-400 uppercase
                                          tracking-widest mb-2">
                                    Your short link ✓
                                </p>
                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                    <a id="result-link" href="#" target="_blank" rel="noopener noreferrer"
                                       aria-label="Open shortened URL"
                                       class="text-xl font-extrabold text-brand-600 hover:text-brand-700
                                              break-all transition-colors duration-150 tracking-tight">
                                    </a>
                                    <button id="copy-btn" type="button"
                                            aria-label="Copy short URL to clipboard"
                                            class="flex items-center gap-1.5 px-3 py-2 rounded-lg
                                                   bg-slate-900 hover:bg-brand-600 text-white text-xs
                                                   font-semibold transition-all duration-150 shadow-sm
                                                   flex-shrink-0">
                                        <svg id="copy-icon" class="w-3.5 h-3.5" fill="none"
                                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                        </svg>
                                        <span id="copy-label">Copy</span>
                                    </button>
                                </div>
                                <p class="text-[11px] text-slate-400 mt-1.5 break-all truncate"
                                   id="result-original"></p>

                                <!-- Expiry badge -->
                                <div id="result-expiry-wrap" class="hidden mt-2">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium
                                                 text-amber-600 bg-amber-50 border border-amber-200
                                                 rounded-full px-2.5 py-0.5">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                                             stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 6v6l4 2m4-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Expires <span id="result-expiry-date"></span>
                                    </span>
                                </div>

                                <!-- Copy feedback -->
                                <div id="copy-feedback" role="status" aria-live="polite"
                                     class="hidden mt-2 flex items-center gap-1.5 text-xs
                                            font-semibold text-emerald-600">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M5 13l4 4L19 7" />
                                    </svg>
                                    Copied to clipboard!
                                </div>
                            </div>

                            <!-- Error Alert -->
                            <div id="error-alert" role="alert" aria-live="assertive"
                                 class="hidden mt-4 flex items-start gap-3 bg-red-50 border
                                        border-red-200 rounded-xl px-4 py-3 text-left">
                                <svg class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-red-600 text-sm">Something went wrong</p>
                                    <p id="error-message" class="text-slate-600 text-xs mt-0.5"></p>
                                </div>
                                <button id="error-close" type="button"
                                        aria-label="Dismiss error"
                                        class="text-slate-400 hover:text-slate-600 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Shorten another -->
                            <button id="shorten-again-btn" type="button"
                                    aria-label="Shorten another URL"
                                    class="hidden mt-4 text-xs text-slate-400 hover:text-slate-600
                                           transition-colors underline underline-offset-2">
                                &larr; Shorten another link
                            </button>
                        </div>

                        <!-- Trust badges -->
                        <div class="animate-fade-in flex flex-wrap gap-3 mt-10"
                             style="animation-delay:0.35s">
                            <?php
                            $trustItems = [
                                ['icon' => 'M13 10V3L4 14h7v7l9-11h-7z',
                                 'label' => 'Instant shortening'],
                                ['icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                                 'label' => 'No spam, ever'],
                                ['icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                                 'label' => 'Click analytics'],
                            ];
                            foreach ($trustItems as $t): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full
                                             bg-white border border-slate-200 text-[11px] font-medium
                                             text-slate-500 shadow-sm">
                                    <svg class="w-3 h-3 text-slate-400" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="<?= $t['icon'] ?>" />
                                    </svg>
                                    <?= $t['label'] ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right column: Analytics bento preview -->
                    <div class="hidden md:flex flex-col border-l border-slate-200 bg-slate-50/50">
                        <!-- Sub-header -->
                        <div class="border-b border-slate-200 px-8 py-4 flex items-center
                                    justify-between">
                            <span class="text-[11px] font-semibold text-slate-400 uppercase
                                         tracking-widest">
                                Live Analytics Preview
                            </span>
                            <span class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-600">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Real-time
                            </span>
                        </div>

                        <!-- Bento grid -->
                        <div class="flex-1 grid grid-rows-[1fr_1fr] divide-y divide-slate-200">

                            <!-- Top bento: Clicks over time (real DB data — last 14 days) -->
                            <div class="p-8 flex flex-col gap-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-[11px] font-semibold text-slate-400 uppercase
                                                  tracking-widest mb-0.5">Total clicks</p>
                                        <p class="text-2xl font-extrabold text-slate-900 tracking-tight">
                                            <?= number_format($totalClicks) ?>
                                        </p>
                                    </div>
                                    <span class="px-2.5 py-1 text-[11px] font-semibold rounded-full
                                                 bg-slate-100 border border-slate-200 text-slate-500
                                                 tabular-nums">
                                        <?= number_format($totalLinks) ?> link<?= $totalLinks !== 1 ? 's' : '' ?>
                                    </span>
                                </div>

                                <!-- Real bar chart — 14-day click volume -->
                                <div class="flex items-end gap-1.5 h-20"
                                     title="Clicks per day, last 14 days">
                                    <?php foreach ($heroDailyBars as $idx => $bar):
                                        $delay = round($idx * 0.05, 2);
                                    ?>
                                        <div class="flex-1 bg-brand-200 hover:bg-brand-500 rounded-t
                                                    transition-colors duration-200 chart-bar animate-bar"
                                             style="height: <?= $bar['pct'] ?>%; animation-delay: <?= $delay ?>s;"
                                             title="<?= htmlspecialchars($bar['label'], ENT_QUOTES) ?>: <?= number_format($bar['count']) ?> click<?= $bar['count'] !== 1 ? 's' : '' ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- X-axis day labels — 7 evenly-spaced labels across 14 bars -->
                                <div class="flex justify-between text-[10px] text-slate-400 font-medium">
                                    <?php
                                    $labelIndices = [0, 2, 4, 6, 8, 10, 13];
                                    foreach ($labelIndices as $li):
                                        $lbl = $heroDailyBars[$li]['label'] ?? '';
                                    ?>
                                        <span><?= htmlspecialchars($lbl, ENT_QUOTES) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Bottom bento: 2-col stats -->
                            <div class="grid grid-cols-2 divide-x divide-slate-200">

                                <!-- Device breakdown (real DB) -->
                                <div class="p-6 flex flex-col gap-3">
                                    <p class="text-[11px] font-semibold text-slate-400 uppercase
                                              tracking-widest">Devices</p>
                                    <div class="space-y-2">
                                        <?php foreach ($heroDevices as $d): ?>
                                            <div class="flex items-center gap-2 text-xs">
                                                <div class="w-2 h-2 rounded-full <?= $d['color'] ?>
                                                            flex-shrink-0"></div>
                                                <span class="text-slate-600 flex-1 font-medium">
                                                    <?= htmlspecialchars($d['label'], ENT_QUOTES) ?>
                                                </span>
                                                <span class="text-slate-900 font-semibold tabular-nums">
                                                    <?= $d['pct'] ?>%
                                                </span>
                                            </div>
                                            <div class="w-full h-1 bg-slate-100 rounded-full overflow-hidden">
                                                <div class="h-full <?= $d['color'] ?> rounded-full
                                                            transition-all duration-700"
                                                      style="width: <?= $d['pct'] ?>%"></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($totalClicks === 0): ?>
                                        <p class="text-[10px] text-slate-300 mt-1">No click data yet</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Top sources (real DB referrers) -->
                                <div class="p-6 flex flex-col gap-3">
                                    <p class="text-[11px] font-semibold text-slate-400 uppercase
                                              tracking-widest">Top Sources</p>
                                    <div class="space-y-1.5">
                                        <?php if (!empty($heroSources)): ?>
                                            <?php foreach ($heroSources as $s): ?>
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="text-slate-600 font-medium truncate" title="<?= htmlspecialchars($s['source'], ENT_QUOTES) ?>">
                                                        <?= htmlspecialchars($s['source'], ENT_QUOTES) ?>
                                                    </span>
                                                    <span class="text-slate-900 font-semibold tabular-nums
                                                                 ml-2 flex-shrink-0">
                                                        <?= number_format($s['count']) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-xs text-slate-400 italic">
                                                No referrer data yet.
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <!-- ══════════════════════════════════════════════════════
             FEATURES — Seamless bento grid
        ══════════════════════════════════════════════════════ -->
        <section id="features" class="border-b border-slate-200">
            <div class="max-w-[1200px] mx-auto">

                <!-- Section header row -->
                <div class="px-8 py-12 lg:px-16 border-b border-slate-200 text-center">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-3">
                        Features
                    </p>
                    <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 mb-3">
                        Everything you need in a link
                    </h2>
                    <p class="text-slate-500 text-base max-w-lg mx-auto">
                        SnapLink is more than a shortener — it's a complete link intelligence platform.
                    </p>
                </div>

                <!-- Bento grid: 3 cols with seamless 1px borders -->
                <?php
                $features = [
                    [
                        'title' => 'Blazing Fast',
                        'desc'  => 'Shortened links redirect in under 10 ms with our optimised single-query lookup engine.',
                        'icon'  => 'M13 10V3L4 14h7v7l9-11h-7z',
                        'accent'=> 'text-brand-600 bg-brand-50 border-brand-100',
                    ],
                    [
                        'title' => 'Click Analytics',
                        'desc'  => 'Track every click — browser, country, referrer and timestamp — in real time.',
                        'icon'  => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                        'accent'=> 'text-violet-600 bg-violet-50 border-violet-100',
                    ],
                    [
                        'title' => 'Link Expiry',
                        'desc'  => 'Set an optional expiry date on any link. After it expires, visitors see a clean "expired" page.',
                        'icon'  => 'M12 6v6l4 2m4-2a9 9 0 11-18 0 9 9 0 0118 0z',
                        'accent'=> 'text-emerald-600 bg-emerald-50 border-emerald-100',
                    ],
                    [
                        'title' => 'Guest-Friendly',
                        'desc'  => 'No account required to shorten links. Sign up only when you want to manage and track them.',
                        'icon'  => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
                        'accent'=> 'text-sky-600 bg-sky-50 border-sky-100',
                    ],
                    [
                        'title' => 'Secure by Default',
                        'desc'  => 'Every redirect is validated to prevent open-redirect abuse. Security headers set on every response.',
                        'icon'  => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                        'accent'=> 'text-amber-600 bg-amber-50 border-amber-100',
                    ],
                    [
                        'title' => 'Your Dashboard',
                        'desc'  => 'Registered users get a personal dashboard to view, deactivate, or delete every link they own.',
                        'icon'  => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
                        'accent'=> 'text-rose-600 bg-rose-50 border-rose-100',
                    ],
                ];
                ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3
                            border-l border-slate-200 divide-y divide-slate-200">
                    <?php foreach ($features as $f): ?>
                        <div class="border-r border-slate-200 p-8 bg-white hover:bg-slate-50
                                    transition-colors duration-200 group">
                            <div class="w-9 h-9 rounded-lg <?= $f['accent'] ?> border
                                        flex items-center justify-center mb-4
                                        group-hover:scale-105 transition-transform duration-200">
                                <svg class="w-4.5 h-4.5" fill="none" viewBox="0 0 24 24"
                                     stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="<?= $f['icon'] ?>" />
                                </svg>
                            </div>
                            <h3 class="font-semibold text-slate-900 text-sm mb-1.5 tracking-tight">
                                <?= $f['title'] ?>
                            </h3>
                            <p class="text-slate-500 text-sm leading-relaxed">
                                <?= $f['desc'] ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </section>

        <!-- ══════════════════════════════════════════════════════
             HOW IT WORKS — 3-col grid row
        ══════════════════════════════════════════════════════ -->
        <section id="how-it-works" class="border-b border-slate-200">
            <div class="max-w-[1200px] mx-auto">

                <!-- Header -->
                <div class="px-8 py-12 lg:px-16 border-b border-slate-200 text-center">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-3">
                        How it works
                    </p>
                    <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-900 mb-3">
                        Three steps, done in seconds
                    </h2>
                    <p class="text-slate-500 text-base">
                        No setup. No friction. Just paste, shorten, and share.
                    </p>
                </div>

                <!-- Steps: horizontal grid row with dividers -->
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x
                            divide-slate-200">
                    <?php
                    $steps = [
                        ['num' => '01', 'title' => 'Paste your URL',
                         'desc' => 'Copy the long URL from your browser and paste it into the input field above.'],
                        ['num' => '02', 'title' => 'Click Shorten',
                         'desc' => 'Hit the button and our engine generates a unique 6-character short code instantly.'],
                        ['num' => '03', 'title' => 'Share &amp; track',
                         'desc' => 'Copy your new short link and share it anywhere. Watch the click analytics roll in.'],
                    ];
                    foreach ($steps as $s): ?>
                        <div class="p-10 lg:p-12 bg-white flex flex-col items-start gap-4">
                            <span class="text-[11px] font-bold text-slate-300 tracking-[0.15em]
                                         uppercase font-mono">
                                <?= $s['num'] ?>
                            </span>
                            <h3 class="text-lg font-semibold text-slate-900 tracking-tight">
                                <?= $s['title'] ?>
                            </h3>
                            <p class="text-sm text-slate-500 leading-relaxed">
                                <?= $s['desc'] ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </section>

        <!-- ══════════════════════════════════════════════════════
             CTA — Guest users only
        ══════════════════════════════════════════════════════ -->
        <?php if (!$isLoggedIn): ?>
            <section id="cta" class="border-b border-slate-200">
                <div class="max-w-[1200px] mx-auto">
                    <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0
                                md:divide-x divide-slate-200">

                        <!-- Left: CTA text -->
                        <div class="p-12 lg:p-16 bg-white flex flex-col justify-center gap-5">
                            <div>
                                <p class="text-[11px] font-semibold text-slate-400 uppercase
                                          tracking-widest mb-3">Get started</p>
                                <h2 class="text-3xl font-extrabold tracking-tight text-slate-900 mb-3">
                                    Unlock the full power of SnapLink
                                </h2>
                                <p class="text-slate-500 text-sm leading-relaxed max-w-xs">
                                    Create a free account to manage links, view analytics,
                                    and set custom expiry dates.
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3">
                                <a href="/register" id="cta-register"
                                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5
                                          bg-slate-900 hover:bg-brand-600 text-white text-sm font-semibold
                                          rounded-lg transition-all duration-150 shadow-sm">
                                    Create free account
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                    </svg>
                                </a>
                                <a href="/login" id="cta-login"
                                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5
                                          border border-slate-200 text-slate-600 hover:text-slate-900
                                          hover:bg-slate-50 text-sm font-medium rounded-lg
                                          transition-all duration-150">
                                    Sign in
                                </a>
                            </div>
                        </div>

                        <!-- Right: Feature checklist -->
                        <div class="p-12 lg:p-16 bg-slate-50 flex flex-col justify-center">
                            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest mb-6">
                                Free plan includes
                            </p>
                            <ul class="space-y-3.5">
                                <?php
                                $perks = [
                                    'Unlimited short links',
                                    'Click & referrer analytics',
                                    'Device & browser breakdown',
                                    'Link expiry dates',
                                    'Dashboard link management',
                                    'No credit card required',
                                ];
                                foreach ($perks as $p): ?>
                                    <li class="flex items-center gap-3 text-sm text-slate-700 font-medium">
                                        <svg class="w-4 h-4 text-emerald-500 flex-shrink-0"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                             stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M5 13l4 4L19 7" />
                                        </svg>
                                        <?= $p ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                    </div>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- ══════════════════════════════════════════════════════
         FOOTER
    ══════════════════════════════════════════════════════ -->
    <footer class="border-t border-slate-200 bg-white">
        <div class="max-w-[1200px] mx-auto px-6">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 h-14">
                <div class="flex items-center gap-2 text-[13px] font-medium text-slate-400">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    <span>&copy; <?= date('Y') ?> SnapLink. All rights reserved.</span>
                </div>
                <nav class="flex gap-5 text-[13px] text-slate-400" aria-label="Footer navigation">
                    <a href="/privacy" class="hover:text-slate-700 transition-colors duration-150">Privacy</a>
                    <a href="/terms"   class="hover:text-slate-700 transition-colors duration-150">Terms</a>
                    <a href="/api-docs" class="hover:text-slate-700 transition-colors duration-150">API</a>
                </nav>
            </div>
        </div>
    </footer>

    <!-- Pass PHP vars to JS -->
    <script>
        window.SnapLink = {
            apiUrl:  <?= json_encode($apiUrl,  JSON_UNESCAPED_SLASHES) ?>,
            baseUrl: <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>,
        };
    </script>
    <script src="../assets/js/main.js" defer></script>

</body>

</html>