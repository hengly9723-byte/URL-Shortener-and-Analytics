<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Not Found &mdash; SnapLink</title>
    <meta name="robots" content="noindex, nofollow">

    <!-- Tailwind CSS (CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    animation: {
                        'fade-in-up': 'fadeInUp 0.45s cubic-bezier(0.16,1,0.3,1) both',
                    },
                    keyframes: {
                        fadeInUp: {
                            '0%':   { opacity: '0', transform: 'translateY(16px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                    },
                },
            },
        };
    </script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
          rel="stylesheet">
</head>

<body class="bg-slate-50 font-sans antialiased text-slate-900 min-h-screen
             flex flex-col items-center justify-center px-4 py-12">

    <!-- Subtle grid background -->
    <div class="fixed inset-0 pointer-events-none"
         style="background-image:linear-gradient(rgba(226,232,240,0.6) 1px,transparent 1px),
                linear-gradient(90deg,rgba(226,232,240,0.6) 1px,transparent 1px);
                background-size:48px 48px;">
    </div>

    <!-- Card -->
    <div class="relative animate-fade-in-up w-full max-w-md bg-white border border-slate-200
                rounded-2xl shadow-sm overflow-hidden">

        <!-- Top accent bar -->
        <div class="h-1 w-full bg-red-400"></div>

        <div class="p-10 text-center">

            <!-- Icon -->
            <div class="w-14 h-14 rounded-2xl bg-red-50 border border-red-100
                        flex items-center justify-center mx-auto mb-6">
                <svg class="w-7 h-7 text-red-500" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
            </div>

            <!-- Heading -->
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 mb-2">
                Link Not Found
            </h1>

            <?php if (!empty($shortCode)): ?>
                <!-- Code badge -->
                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg
                            bg-red-50 border border-red-200 text-xs font-mono font-semibold
                            text-red-600 tracking-widest mb-4">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                    </svg>
                    /s/<?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <p class="text-sm text-slate-500 leading-relaxed mb-8">
                This short link doesn't exist, has been disabled,
                or you may have mistyped the address.
                Double-check the URL and try again.
            </p>

            <!-- Divider -->
            <div class="border-t border-slate-100 mb-6"></div>

            <!-- Actions -->
            <div class="flex flex-col gap-2">
                <a href="/"
                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5
                          bg-slate-900 hover:bg-indigo-600 text-white text-sm font-semibold
                          rounded-lg transition-all duration-150 shadow-sm w-full">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to SnapLink
                </a>
                <a href="/"
                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5
                          border border-slate-200 text-slate-600 hover:text-slate-900
                          hover:bg-slate-50 text-sm font-medium rounded-lg
                          transition-all duration-150 w-full">
                    Shorten a new URL
                </a>
            </div>

        </div>
    </div>

    <!-- Footer note -->
    <p class="relative mt-6 text-xs text-slate-400">
        &copy; <?= date('Y') ?> SnapLink
    </p>

</body>
</html>
