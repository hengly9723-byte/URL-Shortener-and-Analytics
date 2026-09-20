<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Expired &mdash; SnapLink</title>
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
        <div class="h-1 w-full bg-amber-400"></div>

        <div class="p-10 text-center">

            <!-- Icon -->
            <div class="w-14 h-14 rounded-2xl bg-amber-50 border border-amber-100
                        flex items-center justify-center mx-auto mb-6">
                <svg class="w-7 h-7 text-amber-500" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 6v6l4 2m4-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>

            <!-- Heading -->
            <h1 class="text-2xl font-extrabold tracking-tight text-slate-900 mb-2">
                Link Expired
            </h1>

            <?php if (!empty($expiryDate)): ?>
                <!-- Expiry meta badge -->
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full
                            bg-amber-50 border border-amber-200 text-xs font-semibold
                            text-amber-600 mb-4">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Expired on <?= htmlspecialchars(
                        date('D, d M Y \a\t H:i', strtotime($expiryDate)),
                        ENT_QUOTES, 'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <p class="text-sm text-slate-500 leading-relaxed mb-8">
                This short link reached its expiry date and is no longer active.
                Create a new one if you need to share this destination again.
            </p>

            <!-- Divider -->
            <div class="border-t border-slate-100 mb-6"></div>

            <!-- CTA -->
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

        </div>
    </div>

    <!-- Footer note -->
    <p class="relative mt-6 text-xs text-slate-400">
        &copy; <?= date('Y') ?> SnapLink
    </p>

</body>
</html>
