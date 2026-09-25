<?php

declare(strict_types=1);

/**
 * public/dashboard.php
 *
 * User Analytics Dashboard:
 *  - Metric summary cards (Total Links, Total Clicks, Active Links, Avg Clicks)
 *  - Interactive charts (Clicks over time, Devices, Browsers, Referrers)
 *  - Link management table with live search, copy, status toggle, edit, & delete
 *  - Fast inline modal for shortening new links
 */

session_start();

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db.php';
require_once $projectRoot . '/src/Auth.php';
require_once $projectRoot . '/src/Services/AnalyticsService.php';

use App\Auth;
use App\Services\AnalyticsService;

// Auth check
if (!Auth::isLoggedIn()) {
    header('Location: login.php?redirect=dashboard.php', true, 302);
    exit;
}

$userId   = (int) Auth::currentUserId();
$username = (string) Auth::currentUsername();
$csrf     = Auth::csrfToken();

// Compute base and API URLs
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseUrl = $scheme . '://' . $host . $base;
$apiUrl  = $baseUrl . '/api/analytics.php';
$shortenApi = $baseUrl . '/api/shorten.php';

// Initial server-side query to prevent blank flicker
$pdo = getDbConnection();
$analyticsService = new AnalyticsService($pdo);
$userUrls = $analyticsService->getUserUrls($userId);
$initialStats = $analyticsService->getAnalytics($userId, null, 30);

$totalLinks   = (int) ($initialStats['summary']['total_links'] ?? count($userUrls));
$activeLinks  = (int) ($initialStats['summary']['active_links'] ?? 0);
$totalClicks  = (int) ($initialStats['summary']['overall_clicks'] ?? 0);
$avgClicks    = $totalLinks > 0 ? round($totalClicks / $totalLinks, 1) : 0;

// Calculate public root URL relative to the script
$webRoot = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
// If running from public directory, go up one level for root-relative assets on localhost
$assetPath = ($webRoot === '' || $webRoot === '/') ? '/assets' : $webRoot . '/../assets';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <title>Analytics Dashboard &mdash; SnapLink</title>
    <meta name="description"
        content="Track your link performance, analyze click metrics, and manage your shortened URLs." />
    <meta name="robots" content="noindex, nofollow" />

    <!-- Tailwind CSS (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            200: '#c7d2fe',
                            400: '#818cf8',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
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

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: #d4d4d8;
            border-radius: 99px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1aa;
        }

        /* Spinner */
        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(79, 70, 229, 0.2);
            border-top-color: #4f46e5;
            border-radius: 50%;
            animation: spin 0.65s linear infinite;
            flex-shrink: 0;
        }

        .spinner-white {
            border-color: rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
        }

        .spinner-danger {
            border-color: rgba(220, 38, 38, 0.2);
            border-top-color: #dc2626;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Field inputs */
        .field-input {
            background: white;
            border: 1px solid #e4e4e7;
            border-radius: 6px;
            padding: 0.5625rem 0.75rem;
            font-size: 0.875rem;
            color: #09090b;
            width: 100%;
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
            font-family: inherit;
        }

        .field-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .field-input:disabled {
            background: #f4f4f5;
            color: #a1a1aa;
            cursor: not-allowed;
        }

        /* Toast slide up */
        #dashboard-toast {
            transition: opacity 0.25s, transform 0.25s;
        }

        #dashboard-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        #dashboard-toast.hide {
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
        }
    </style>
</head>

<body class="bg-slate-50 font-sans antialiased text-slate-900 min-h-screen">

    <!-- navigation -->
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
        <div class="max-w-[1400px] mx-auto px-6 h-14 flex items-center justify-between gap-4">

            <!-- Left: Logo + badge -->
            <div class="flex items-center gap-3">
                <a href="index.php" id="nav-logo" class="flex items-center gap-2 group flex-shrink-0">
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
                <div class="h-4 w-px bg-slate-200"></div>
                <span class="hidden sm:inline text-[11px] font-semibold text-slate-400 uppercase
                             tracking-widest">
                    Dashboard
                </span>
            </div>

            <!-- Right: Actions -->
            <div class="flex items-center gap-2">
                <a href="index.php"
                    class="hidden sm:inline-flex text-[13px] font-medium text-slate-500
                          hover:text-slate-900 hover:bg-slate-100 px-3 py-1.5 rounded-md
                          transition-all duration-150">
                    Home
                </a>

                <button id="open-create-btn"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-900
                               hover:bg-brand-600 text-white text-[13px] font-semibold rounded-lg
                               transition-all duration-150 shadow-sm">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>New Link</span>
                </button>

                <div class="h-4 w-px bg-slate-200 mx-1"></div>

                <!-- User avatar -->
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-brand-600 flex items-center justify-center
                                text-[11px] font-bold text-white uppercase flex-shrink-0">
                        <?= htmlspecialchars(mb_substr($username, 0, 1), ENT_QUOTES) ?>
                    </div>
                    <span class="hidden md:inline text-[13px] font-medium text-slate-700">
                        <?= htmlspecialchars($username, ENT_QUOTES) ?>
                    </span>
                </div>

                <!-- Logout -->
                <a href="logout.php" id="logout-btn" title="Sign out"
                    class="p-1.5 rounded-md text-slate-400 hover:text-red-500 hover:bg-red-50
                          transition-all duration-150">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                </a>
            </div>
        </div>
    </header>

    <!-- ══════════════════════════════════════════════════════
         MAIN DASHBOARD CONTENT
    ══════════════════════════════════════════════════════ -->
    <main class="max-w-[1400px] mx-auto px-6 py-8 space-y-6">

        <!-- Page header + filter bar -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-extrabold tracking-tight text-slate-900">
                    Performance Analytics
                </h1>
                <p class="text-sm text-slate-500 mt-0.5">
                    Manage your short links, monitor real-time visits, and uncover traffic insights.
                </p>
            </div>

            <!-- Filter controls -->
            <div class="flex flex-wrap items-center gap-3">
                <!-- URL selector -->
                <div class="relative">
                    <select id="link-filter-select" aria-label="Filter by link"
                        class="field-input appearance-none pr-8 pl-3 py-2 text-[13px]
                                   min-w-[200px] cursor-pointer">
                        <option value="all">All Links (Combined)</option>
                        <?php foreach ($userUrls as $u): ?>
                            <option value="<?= $u['id'] ?>">
                                /s/<?= htmlspecialchars($u['short_code'], ENT_QUOTES) ?> &mdash;
                                <?= htmlspecialchars(mb_strimwidth($u['original_url'], 0, 30, '...'), ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2
                                text-slate-400">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </div>

                <!-- Time range -->
                <div class="flex items-center bg-white border border-slate-200 rounded-lg p-0.5
                            text-[12px] font-medium">
                    <button type="button" data-days="7"
                        class="time-filter-btn px-3 py-1.5 rounded-md text-slate-500
                                   hover:text-slate-900 hover:bg-slate-100 transition-all duration-150">
                        7D
                    </button>
                    <button type="button" data-days="30"
                        class="time-filter-btn px-3 py-1.5 rounded-md bg-slate-900 text-white
                                   font-semibold transition-all duration-150">
                        30D
                    </button>
                    <button type="button" data-days="90"
                        class="time-filter-btn px-3 py-1.5 rounded-md text-slate-500
                                   hover:text-slate-900 hover:bg-slate-100 transition-all duration-150">
                        90D
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Metric Cards ── -->
        <div class="grid grid-cols-2 lg:grid-cols-4 border border-slate-200 rounded-xl overflow-hidden
                    bg-white divide-x divide-y divide-slate-200 shadow-sm">

            <!-- Card 1: Total Links -->
            <div class="p-6 flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest">
                        Total Links
                    </span>
                    <div class="w-7 h-7 rounded-md bg-brand-50 border border-brand-100
                                flex items-center justify-center text-brand-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                    </div>
                </div>
                <div class="text-3xl font-extrabold text-slate-900 tracking-tight"
                    id="metric-total-links">
                    <?= number_format($totalLinks) ?>
                </div>
                <p class="text-xs text-slate-400">Generated so far</p>
            </div>

            <!-- Card 2: Total Clicks -->
            <div class="p-6 flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest">
                        Total Clicks
                    </span>
                    <div class="w-7 h-7 rounded-md bg-violet-50 border border-violet-100
                                flex items-center justify-center text-violet-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5" />
                        </svg>
                    </div>
                </div>
                <div class="text-3xl font-extrabold text-slate-900 tracking-tight"
                    id="metric-total-clicks">
                    <?= number_format($totalClicks) ?>
                </div>
                <p class="text-xs text-slate-400">Across all short links</p>
            </div>

            <!-- Card 3: Active Links -->
            <div class="p-6 flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest">
                        Active Links
                    </span>
                    <div class="w-7 h-7 rounded-md bg-emerald-50 border border-emerald-100
                                flex items-center justify-center text-emerald-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
                <div class="text-3xl font-extrabold text-emerald-600 tracking-tight"
                    id="metric-active-links">
                    <?= number_format($activeLinks) ?>
                </div>
                <p class="text-xs text-slate-400">Currently redirecting</p>
            </div>

            <!-- Card 4: Avg Clicks -->
            <div class="p-6 flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest">
                        Avg / Link
                    </span>
                    <div class="w-7 h-7 rounded-md bg-amber-50 border border-amber-100
                                flex items-center justify-center text-amber-500">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                    </div>
                </div>
                <div class="text-3xl font-extrabold text-slate-900 tracking-tight"
                    id="metric-avg-clicks">
                    <?= $avgClicks ?>
                </div>
                <p class="text-xs text-slate-400">Engagement ratio</p>
            </div>

        </div>

        <!-- ── Charts Grid ── -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-0 border border-slate-200 rounded-xl
                    overflow-hidden bg-white shadow-sm divide-y lg:divide-y-0 lg:divide-x
                    divide-slate-200">

            <!-- Primary Chart: Clicks Over Time (spans 2 cols) -->
            <div class="lg:col-span-2 p-6 flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                            Clicks Over Time
                            <span id="chart-scope-badge"
                                class="text-[10px] font-semibold text-brand-600 bg-brand-50
                                         border border-brand-100 px-2 py-0.5 rounded-full">
                                All Links
                            </span>
                        </h2>
                        <p class="text-xs text-slate-400 mt-0.5">Click volume timeline</p>
                    </div>
                    <div id="chart-spinner" class="spinner hidden" aria-label="Loading chart"></div>
                </div>
                <div class="relative w-full h-[280px]">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>

            <!-- Referrers -->
            <div class="p-6 flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Top Referrers</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Traffic sources</p>
                    </div>
                    <div class="flex items-center bg-slate-100 rounded-md p-0.5 text-[11px] font-medium">
                        <button type="button" id="ref-view-chart-btn"
                            class="px-2 py-0.5 rounded bg-white text-slate-900 shadow-sm
                                       transition-all duration-150">
                            Chart
                        </button>
                        <button type="button" id="ref-view-list-btn"
                            class="px-2 py-0.5 rounded text-slate-500 hover:text-slate-900
                                       transition-all duration-150">
                            List
                        </button>
                    </div>
                </div>

                <div id="referrer-chart-wrap" class="relative w-full h-[200px]">
                    <canvas id="referrerChart"></canvas>
                </div>
                <div id="referrers-container"
                    class="hidden space-y-2 overflow-y-auto max-h-[200px] pr-1">
                </div>
            </div>

        </div>

        <!-- Second chart row: Devices + Browsers + Countries -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-0 border border-slate-200 rounded-xl
                    overflow-hidden bg-white shadow-sm divide-y sm:divide-y-0 sm:divide-x
                    divide-slate-200">

            <!-- Devices -->
            <div class="p-6 flex flex-col items-center gap-4">
                <div class="w-full">
                    <h2 class="text-sm font-semibold text-slate-900">Devices</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Desktop · Mobile · Tablet</p>
                </div>
                <div class="relative w-full max-w-[180px] h-[180px]">
                    <canvas id="deviceChart"></canvas>
                </div>
                <div id="device-legend"
                    class="flex items-center justify-center gap-3 text-xs text-slate-500 w-full flex-wrap">
                </div>
            </div>

            <!-- Browsers -->
            <div class="p-6 flex flex-col items-center gap-4">
                <div class="w-full">
                    <h2 class="text-sm font-semibold text-slate-900">Browsers</h2>
                    <p class="text-xs text-slate-400 mt-0.5">User agent distribution</p>
                </div>
                <div class="relative w-full max-w-[180px] h-[180px]">
                    <canvas id="browserChart"></canvas>
                </div>
                <div id="browser-legend"
                    class="flex items-center justify-center gap-3 text-xs text-slate-500 w-full flex-wrap">
                </div>
            </div>

            <!-- Countries -->
            <div class="p-6 flex flex-col gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Geographic Origin</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Top countries by clicks</p>
                </div>
                <div id="countries-container"
                    class="space-y-2.5 overflow-y-auto max-h-[220px] pr-1 flex-1">
                </div>
            </div>

        </div>

        <!-- ── Links Table ── -->
        <div class="border border-slate-200 rounded-xl overflow-hidden bg-white shadow-sm">

            <!-- Table header -->
            <div class="px-6 py-4 border-b border-slate-200 flex flex-col sm:flex-row
                        sm:items-center justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Your Short Links</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Inspect, copy, toggle, edit, or delete any of your links.
                    </p>
                </div>

                <!-- Search -->
                <div class="relative max-w-xs w-full">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400
                                pointer-events-none">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input id="search-links-input" type="text" placeholder="Search links..."
                        class="field-input pl-8 py-2 text-[13px]" />
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-[13px]" id="links-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr class="text-[11px] font-semibold text-slate-400 uppercase tracking-widest">
                            <th class="py-3 px-6">Short Link</th>
                            <th class="py-3 px-4">Destination</th>
                            <th class="py-3 px-4 text-center">Clicks</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Created</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="links-table-body" class="divide-y divide-slate-100 text-slate-700">

                        <?php if (empty($userUrls)): ?>
                            <tr id="empty-state-row">
                                <td colspan="6" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-slate-100 border border-slate-200
                                                flex items-center justify-center text-slate-300">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                                stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                            </svg>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-600">
                                                No shortened links yet
                                            </p>
                                            <p class="text-xs text-slate-400 mt-0.5">
                                                Click "New Link" above to generate your first tracked short link.
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($userUrls as $u):
                                $shortUrl  = $baseUrl . '/s/' . $u['short_code'];
                                $isExpired = $u['is_expired'];
                                $isActive  = $u['is_active'];
                            ?>
                                <tr class="hover:bg-slate-50 transition-colors duration-100 link-row"
                                    data-id="<?= $u['id'] ?>"
                                    data-code="<?= htmlspecialchars($u['short_code'], ENT_QUOTES) ?>"
                                    data-url="<?= htmlspecialchars($u['original_url'], ENT_QUOTES) ?>"
                                    data-expiry="<?= htmlspecialchars((string) ($u['expiry_date'] ?? ''), ENT_QUOTES) ?>"
                                    data-active="<?= $isActive ? '1' : '0' ?>">

                                    <!-- Short link -->
                                    <td class="py-3.5 px-6 font-medium">
                                        <div class="flex items-center gap-2">
                                            <a href="<?= htmlspecialchars($shortUrl, ENT_QUOTES) ?>"
                                                target="_blank"
                                                class="font-semibold text-brand-600 hover:text-brand-700
                                              transition-colors duration-100 font-mono text-[13px]">
                                                /s/<?= htmlspecialchars($u['short_code'], ENT_QUOTES) ?>
                                            </a>
                                            <button type="button"
                                                class="copy-link-btn p-1 rounded text-slate-300
                                                   hover:text-slate-600 hover:bg-slate-100
                                                   transition-all duration-100"
                                                data-copy="<?= htmlspecialchars($shortUrl, ENT_QUOTES) ?>"
                                                title="Copy short link">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>

                                    <!-- Original URL -->
                                    <td class="py-3.5 px-4 max-w-[240px]"
                                        title="<?= htmlspecialchars($u['original_url'], ENT_QUOTES) ?>">
                                        <a href="<?= htmlspecialchars($u['original_url'], ENT_QUOTES) ?>"
                                            target="_blank" rel="noopener noreferrer"
                                            class="text-slate-500 hover:text-slate-800 transition-colors
                                          duration-100 truncate block max-w-[220px]">
                                            <?= htmlspecialchars($u['original_url'], ENT_QUOTES) ?>
                                        </a>
                                    </td>

                                    <!-- Clicks -->
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="inline-flex items-center justify-center px-2.5 py-0.5
                                             text-[11px] font-semibold text-slate-700 bg-slate-100
                                             border border-slate-200 rounded-full tabular-nums">
                                            <?= number_format($u['total_clicks']) ?>
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td class="py-3.5 px-4">
                                        <?php if ($isExpired): ?>
                                            <span class="inline-flex items-center gap-1.5 text-[11px]
                                                 font-semibold text-amber-600 bg-amber-50
                                                 border border-amber-200 px-2 py-0.5 rounded-full">
                                                Expired
                                            </span>
                                        <?php elseif ($isActive): ?>
                                            <span class="inline-flex items-center gap-1.5 text-[11px]
                                                 font-semibold text-emerald-600 bg-emerald-50
                                                 border border-emerald-200 px-2 py-0.5 rounded-full
                                                 status-pill">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500
                                                     animate-pulse"></span>
                                                Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 text-[11px]
                                                 font-semibold text-slate-500 bg-slate-100
                                                 border border-slate-200 px-2 py-0.5 rounded-full
                                                 status-pill">
                                                Paused
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Created -->
                                    <td class="py-3.5 px-4 text-slate-400 text-xs whitespace-nowrap">
                                        <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                    </td>

                                    <!-- Actions -->
                                    <td class="py-3.5 px-4 text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <!-- Stats -->
                                            <button type="button"
                                                class="view-stats-btn px-2.5 py-1 rounded-md text-[12px]
                                                   font-medium text-brand-600 hover:bg-brand-50
                                                   border border-transparent hover:border-brand-100
                                                   transition-all duration-100"
                                                data-id="<?= $u['id'] ?>" title="View stats">
                                                Stats
                                            </button>

                                            <!-- Toggle -->
                                            <button type="button"
                                                class="toggle-status-btn p-1.5 rounded-md text-slate-400
                                                   hover:text-slate-900 hover:bg-slate-100
                                                   transition-all duration-100"
                                                data-id="<?= $u['id'] ?>"
                                                title="<?= $isActive ? 'Pause link' : 'Activate link' ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </button>

                                            <!-- Edit -->
                                            <button type="button"
                                                class="edit-link-btn p-1.5 rounded-md text-slate-400
                                                   hover:text-slate-900 hover:bg-slate-100
                                                   transition-all duration-100"
                                                data-id="<?= $u['id'] ?>"
                                                data-code="<?= htmlspecialchars($u['short_code'], ENT_QUOTES) ?>"
                                                data-url="<?= htmlspecialchars($u['original_url'], ENT_QUOTES) ?>"
                                                data-expiry="<?= htmlspecialchars((string) ($u['expiry_date'] ?? ''), ENT_QUOTES) ?>"
                                                title="Edit link">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                                </svg>
                                            </button>

                                            <!-- Delete -->
                                            <button type="button"
                                                class="delete-link-btn p-1.5 rounded-md text-slate-400
                                                   hover:text-red-500 hover:bg-red-50
                                                   transition-all duration-100"
                                                data-id="<?= $u['id'] ?>"
                                                data-code="<?= htmlspecialchars($u['short_code'], ENT_QUOTES) ?>"
                                                title="Delete link">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                                                    stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- MODAL: Create New Link -->
    <div id="create-modal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4
                bg-slate-900/40 backdrop-blur-sm hidden">
        <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-md p-6
                    animate-fade-in-up">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-base font-semibold text-slate-900">Create Short Link</h3>
                <button type="button" class="close-modal-btn p-1 rounded-md text-slate-400
                                             hover:text-slate-600 hover:bg-slate-100
                                             transition-all duration-100">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form id="create-form" class="space-y-4">
                <input type="hidden" name="_csrf"
                    value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>" />

                <div>
                    <label for="create-url"
                        class="block text-[13px] font-medium text-slate-700 mb-1.5">
                        Destination URL
                    </label>
                    <input id="create-url" type="url" required
                        placeholder="https://example.com/your-destination"
                        class="field-input py-2.5" />
                </div>

                <div>
                    <label for="create-expiry"
                        class="block text-[13px] font-medium text-slate-700 mb-1.5">
                        Expiry Date
                        <span class="text-slate-400 font-normal">(optional)</span>
                    </label>
                    <input id="create-expiry" type="date" class="field-input py-2" />
                </div>

                <div id="create-error" class="hidden text-xs text-red-500 font-medium"></div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button"
                        class="close-modal-btn px-4 py-2 rounded-lg text-[13px] font-medium
                                   text-slate-600 hover:bg-slate-100 transition-all duration-100">
                        Cancel
                    </button>
                    <button type="submit" id="create-submit-btn"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900
                                   hover:bg-brand-600 text-white text-[13px] font-semibold rounded-lg
                                   transition-all duration-150 shadow-sm">
                        <span id="create-btn-text">Create Link</span>
                        <span id="create-btn-spinner" class="spinner spinner-white hidden"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Link -->
    <div id="edit-modal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4
                bg-slate-900/40 backdrop-blur-sm hidden">
        <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-base font-semibold text-slate-900">Edit Link</h3>
                <button type="button" class="close-modal-btn p-1 rounded-md text-slate-400
                                             hover:text-slate-600 hover:bg-slate-100
                                             transition-all duration-100">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                        stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form id="edit-form" class="space-y-4">
                <input type="hidden" id="edit-id" name="url_id" />
                <input type="hidden" name="_csrf"
                    value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>" />

                <div>
                    <label class="block text-[13px] font-medium text-slate-700 mb-1.5">
                        Short Code
                    </label>
                    <input id="edit-code" type="text" disabled class="field-input py-2" />
                </div>

                <div>
                    <label for="edit-url"
                        class="block text-[13px] font-medium text-slate-700 mb-1.5">
                        Destination URL
                    </label>
                    <input id="edit-url" type="url" required
                        placeholder="https://example.com/new-destination"
                        class="field-input py-2.5" />
                </div>

                <div>
                    <label for="edit-expiry"
                        class="block text-[13px] font-medium text-slate-700 mb-1.5">
                        Expiry Date
                    </label>
                    <input id="edit-expiry" type="date" class="field-input py-2" />
                </div>

                <div id="edit-error" class="hidden text-xs text-red-500 font-medium"></div>

                <div class="flex items-center justify-end gap-2 pt-1">
                    <button type="button"
                        class="close-modal-btn px-4 py-2 rounded-lg text-[13px] font-medium
                                   text-slate-600 hover:bg-slate-100 transition-all duration-100">
                        Cancel
                    </button>
                    <button type="submit" id="edit-submit-btn"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900
                                   hover:bg-brand-600 text-white text-[13px] font-semibold rounded-lg
                                   transition-all duration-150 shadow-sm">
                        <span id="edit-btn-text">Save Changes</span>
                        <span id="edit-btn-spinner" class="spinner spinner-white hidden"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation -->
    <div id="delete-modal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4
                bg-slate-900/40 backdrop-blur-sm hidden">
        <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-sm p-6
                    text-center">
            <div class="w-10 h-10 rounded-xl bg-red-50 border border-red-100 text-red-500
                        flex items-center justify-center mx-auto mb-4">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                    stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-slate-900 mb-1.5">Delete Short Link?</h3>
            <p class="text-xs text-slate-500 mb-6">
                Are you sure you want to delete
                <span id="delete-code-display" class="font-bold text-slate-800"></span>?
                All associated click analytics will be permanently erased.
            </p>
            <div class="flex items-center justify-center gap-3">
                <button type="button"
                    class="close-modal-btn px-4 py-2 rounded-lg text-[13px] font-medium
                               text-slate-600 hover:bg-slate-100 transition-all duration-100">
                    Cancel
                </button>
                <button type="button" id="confirm-delete-btn"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-red-600
                               hover:bg-red-700 text-white text-[13px] font-semibold rounded-lg
                               transition-all duration-150 shadow-sm">
                    <span>Delete Link</span>
                    <span id="delete-btn-spinner" class="spinner spinner-white hidden"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="dashboard-toast" role="status" aria-live="polite"
        class="fixed bottom-6 right-6 z-50 bg-slate-900 text-white text-[13px] font-medium
                px-4 py-2.5 rounded-xl shadow-2xl flex items-center gap-2.5
                hide pointer-events-none">
        <svg id="toast-icon-success" class="w-4 h-4 text-emerald-400" fill="none"
            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
        <svg id="toast-icon-error" class="w-4 h-4 text-red-400 hidden" fill="none"
            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span id="toast-message"></span>
    </div>

    <!-- Configuration + initial data -->
    <script>
        window.SnapDashboard = {
            apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
            shortenApi: <?= json_encode($shortenApi, JSON_UNESCAPED_SLASHES) ?>,
            baseUrl: <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>,
            csrf: <?= json_encode($csrf) ?>,
            initial: <?= json_encode($initialStats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        };
    </script>
    <script src="/assets/js/dashboard.js" defer></script>
</body>

</html>