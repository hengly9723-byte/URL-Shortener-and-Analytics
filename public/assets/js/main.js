/**
 * assets/js/main.js
 *
 * SnapLink — Landing Page JavaScript
 *
 * Responsibilities:
 *  1. Submit long URLs to api/shorten.php via Fetch API (no page reload).
 *  2. Show the generated short link with a gradient display.
 *  3. Provide a "Copy to Clipboard" button with visual feedback.
 *  4. Handle and display API errors gracefully.
 *  5. Toggle the optional expiry date field.
 *  6. Allow the user to shorten another link from the result state.
 *
 * Reads window.SnapLink.apiUrl (injected by index.php) for the POST endpoint.
 */

'use strict';

/* ─────────────────────────────────────────────────────────────────────────────
   CONSTANTS & DOM REFS
───────────────────────────────────────────────────────────────────────────── */

/** API endpoint — set by PHP in window.SnapLink.apiUrl */
const API_URL = (window.SnapLink && window.SnapLink.apiUrl) || '/api/shorten.php';

// Form & inputs
const form         = document.getElementById('shorten-form');
const urlInput     = document.getElementById('url-input');
const expiryInput  = document.getElementById('expiry-input');
const toggleExpiry = document.getElementById('toggle-expiry');
const expiryRow    = document.getElementById('expiry-row');

// Button elements
const shortenBtn   = document.getElementById('shorten-btn');
const btnText      = document.getElementById('btn-text');
const btnSpinner   = document.getElementById('btn-spinner');
const btnIcon      = document.getElementById('btn-icon');

// Inline validation
const urlError     = document.getElementById('url-error');

// Result panel
const resultPanel    = document.getElementById('result-panel');
const resultLink     = document.getElementById('result-link');
const resultOriginal = document.getElementById('result-original');
const resultExpWrap  = document.getElementById('result-expiry-wrap');
const resultExpDate  = document.getElementById('result-expiry-date');

// Copy button
const copyBtn      = document.getElementById('copy-btn');
const copyLabel    = document.getElementById('copy-label');
const copyIcon     = document.getElementById('copy-icon');
const copyFeedback = document.getElementById('copy-feedback');

// Error alert
const errorAlert   = document.getElementById('error-alert');
const errorMessage = document.getElementById('error-message');
const errorClose   = document.getElementById('error-close');

// "Shorten another" link
const shortenAgainBtn = document.getElementById('shorten-again-btn');

/* ─────────────────────────────────────────────────────────────────────────────
   STATE
───────────────────────────────────────────────────────────────────────────── */

/** The last successfully shortened URL, kept for the copy button. */
let currentShortUrl = '';

/** Timer ID for clearing copy feedback text. */
let copyFeedbackTimer = null;

/* ─────────────────────────────────────────────────────────────────────────────
   HELPERS
───────────────────────────────────────────────────────────────────────────── */

/**
 * Show or hide an element using Tailwind's 'hidden' class.
 * @param {HTMLElement} el
 * @param {boolean}     visible
 */
function setVisible(el, visible) {
    if (!el) return;
    el.classList.toggle('hidden', !visible);
}

/**
 * Set the loading state of the submit button.
 * @param {boolean} loading
 */
function setLoading(loading) {
    shortenBtn.disabled = loading;
    setVisible(btnSpinner, loading);
    setVisible(btnIcon, !loading);
    btnText.textContent = loading ? 'Shortening…' : 'Shorten it!';
}

/**
 * Display an inline validation error below the URL input.
 * Pass an empty string to clear the error.
 * @param {string} message
 */
function showUrlError(message) {
    if (!urlError) return;
    if (message) {
        urlError.textContent = message;
        setVisible(urlError, true);
        urlInput.setAttribute('aria-invalid', 'true');
        urlInput.classList.add('border-red-500/60');
    } else {
        setVisible(urlError, false);
        urlError.textContent = '';
        urlInput.removeAttribute('aria-invalid');
        urlInput.classList.remove('border-red-500/60');
    }
}

/**
 * Show the API error alert with a given message.
 * @param {string} message
 */
function showErrorAlert(message) {
    if (errorMessage) errorMessage.textContent = message;
    setVisible(errorAlert, true);
    // Auto-dismiss after 8 seconds
    setTimeout(() => setVisible(errorAlert, false), 8000);
}

/**
 * Hide the error alert.
 */
function hideErrorAlert() {
    setVisible(errorAlert, false);
}

/**
 * Reset the UI back to the initial "empty form" state.
 */
function resetToForm() {
    setVisible(resultPanel, false);
    setVisible(shortenAgainBtn, false);
    setVisible(errorAlert, false);
    showUrlError('');
    urlInput.value    = '';
    expiryInput.value = '';
    currentShortUrl   = '';
    urlInput.focus();
}

/**
 * Format an ISO date string (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS) into a
 * human-readable form, e.g. "Sep 30, 2026".
 * @param {string} isoDate
 * @returns {string}
 */
function formatDate(isoDate) {
    if (!isoDate) return '';
    try {
        const d = new Date(isoDate.replace(' ', 'T'));
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch {
        return isoDate;
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   CLIPBOARD
───────────────────────────────────────────────────────────────────────────── */

/**
 * Checked SVG path (✓) for the copy button "copied" state.
 */
const CHECK_PATH = 'M5 13l4 4L19 7';

/**
 * Copy SVG path (two overlapping rectangles) for the default copy button state.
 */
const COPY_PATH  = 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z';

/**
 * Copy the current short URL to the clipboard and show visual feedback.
 */
async function copyToClipboard() {
    if (!currentShortUrl) return;

    try {
        await navigator.clipboard.writeText(currentShortUrl);
        onCopySuccess();
    } catch {
        // Fallback for browsers / HTTP contexts without clipboard API
        fallbackCopy(currentShortUrl);
    }
}

/**
 * Update button icon/label to "Copied" state, then revert after 2.5 s.
 */
function onCopySuccess() {
    // Swap icon to check mark
    const pathEl = copyIcon.querySelector('path');
    if (pathEl) pathEl.setAttribute('d', CHECK_PATH);
    copyLabel.textContent = 'Copied!';
    copyBtn.classList.replace('bg-brand-600', 'bg-emerald-600');
    copyBtn.classList.replace('hover:bg-brand-500', 'hover:bg-emerald-500');

    // Show inline "Copied to clipboard!" text
    setVisible(copyFeedback, true);

    // Clear any pending reset timer
    if (copyFeedbackTimer) clearTimeout(copyFeedbackTimer);

    copyFeedbackTimer = setTimeout(() => {
        // Revert icon
        const p = copyIcon.querySelector('path');
        if (p) p.setAttribute('d', COPY_PATH);
        copyLabel.textContent = 'Copy';
        copyBtn.classList.replace('bg-emerald-600', 'bg-brand-600');
        copyBtn.classList.replace('hover:bg-emerald-500', 'hover:bg-brand-500');
        setVisible(copyFeedback, false);
    }, 2500);
}

/**
 * Clipboard write fallback using a temporary <textarea>.
 * @param {string} text
 */
function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try {
        document.execCommand('copy');
        onCopySuccess();
    } catch {
        // Last resort: nothing we can do silently
    } finally {
        ta.remove();
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   RESULT RENDERING
───────────────────────────────────────────────────────────────────────────── */

/**
 * Populate and reveal the result panel after a successful API response.
 * @param {{ short_url: string, original_url: string, expires_at: string|null }} data
 */
function showResult(data) {
    currentShortUrl = data.short_url;

    // Populate the short URL anchor
    resultLink.href        = data.short_url;
    resultLink.textContent = data.short_url;

    // Populate the original URL preview
    resultOriginal.textContent = 'Original: ' + data.original_url;

    // Expiry badge
    if (data.expires_at) {
        resultExpDate.textContent = formatDate(data.expires_at);
        setVisible(resultExpWrap, true);
    } else {
        setVisible(resultExpWrap, false);
    }

    // Reset copy button to default state
    const pathEl = copyIcon.querySelector('path');
    if (pathEl) pathEl.setAttribute('d', COPY_PATH);
    copyLabel.textContent = 'Copy';
    setVisible(copyFeedback, false);

    // Show result panel + "shorten another" link
    setVisible(resultPanel, true);
    setVisible(shortenAgainBtn, true);

    // Smooth scroll to result
    resultPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/* ─────────────────────────────────────────────────────────────────────────────
   VALIDATION
───────────────────────────────────────────────────────────────────────────── */

/**
 * Client-side URL validation — mirrors the server-side rules.
 * Returns an error string, or an empty string if valid.
 * @param {string} value
 * @returns {string}
 */
function validateUrl(value) {
    if (!value.trim()) {
        return 'Please enter a URL.';
    }
    try {
        const url = new URL(value.trim());
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return 'Only http:// and https:// URLs are accepted.';
        }
    } catch {
        return 'Please enter a valid URL (e.g. https://example.com).';
    }
    return '';
}

/* ─────────────────────────────────────────────────────────────────────────────
   FORM SUBMISSION
───────────────────────────────────────────────────────────────────────────── */

/**
 * Handle the form submit event — validate, POST to API, render result.
 * @param {SubmitEvent} e
 */
async function handleSubmit(e) {
    e.preventDefault();

    // Clear previous feedback
    showUrlError('');
    hideErrorAlert();
    setVisible(resultPanel, false);
    setVisible(shortenAgainBtn, false);

    const rawUrl    = urlInput.value.trim();
    const expiryVal = expiryInput ? expiryInput.value.trim() : '';

    // Client-side validation
    const validationError = validateUrl(rawUrl);
    if (validationError) {
        showUrlError(validationError);
        urlInput.focus();
        return;
    }

    // Build request payload
    const payload = { url: rawUrl };
    if (expiryVal) {
        payload.expiry_date = expiryVal;
    }

    // Enter loading state
    setLoading(true);

    try {
        const response = await fetch(API_URL, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });

        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('The server returned an unexpected response. Please try again.');
        }

        if (response.ok && data.success) {
            // ── Success ─────────────────────────────────────────────────────
            showResult(data);
        } else {
            // ── API-level error (4xx / 5xx with JSON body) ───────────────────
            let msg = data.message || 'An unknown error occurred.';

            // Append field-level validation errors if present
            if (Array.isArray(data.errors) && data.errors.length) {
                msg += ' ' + data.errors.join(' ');
            }

            // If it's a 422 Validation error and purely about the URL field,
            // show it inline rather than in the alert banner.
            if (response.status === 422) {
                showUrlError(msg);
            } else {
                showErrorAlert(msg);
            }
        }
    } catch (networkErr) {
        // ── Network / fetch error ────────────────────────────────────────────
        showErrorAlert(networkErr.message || 'A network error occurred. Please check your connection.');
    } finally {
        setLoading(false);
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   EXPIRY DATE TOGGLE
───────────────────────────────────────────────────────────────────────────── */

/**
 * Toggle the expiry date row visibility.
 */
function toggleExpiryRow() {
    if (!expiryRow || !toggleExpiry) return;

    const isHidden  = expiryRow.classList.contains('hidden');
    setVisible(expiryRow, isHidden);
    toggleExpiry.setAttribute('aria-expanded', String(isHidden));

    if (isHidden) {
        // Set minimum date to tomorrow
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        expiryInput.min = tomorrow.toISOString().split('T')[0];
        expiryInput.focus();
    } else {
        // Clear value when collapsing
        expiryInput.value = '';
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   REAL-TIME INPUT FEEDBACK
───────────────────────────────────────────────────────────────────────────── */

/**
 * Clear the inline URL error as soon as the user starts typing again.
 */
function handleUrlInput() {
    if (urlError && !urlError.classList.contains('hidden')) {
        showUrlError('');
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   EVENT LISTENERS
───────────────────────────────────────────────────────────────────────────── */

// Form submission
if (form) {
    form.addEventListener('submit', handleSubmit);
}

// URL input — clear error on type
if (urlInput) {
    urlInput.addEventListener('input', handleUrlInput);

    // Allow pasting and immediately trim whitespace
    urlInput.addEventListener('paste', () => {
        setTimeout(() => {
            urlInput.value = urlInput.value.trim();
        }, 0);
    });
}

// Expiry date toggle
if (toggleExpiry) {
    toggleExpiry.addEventListener('click', toggleExpiryRow);
}

// Copy button
if (copyBtn) {
    copyBtn.addEventListener('click', copyToClipboard);
}

// Error alert dismiss
if (errorClose) {
    errorClose.addEventListener('click', hideErrorAlert);
}

// "Shorten another link" button
if (shortenAgainBtn) {
    shortenAgainBtn.addEventListener('click', resetToForm);
}

/* ─────────────────────────────────────────────────────────────────────────────
   KEYBOARD SHORTCUTS
───────────────────────────────────────────────────────────────────────────── */

document.addEventListener('keydown', (e) => {
    // Escape: dismiss error alert
    if (e.key === 'Escape') {
        hideErrorAlert();
    }

    // Ctrl/Cmd + Shift + C: copy short URL if result is visible
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'C') {
        if (currentShortUrl && !resultPanel.classList.contains('hidden')) {
            e.preventDefault();
            copyToClipboard();
        }
    }
});

/* ─────────────────────────────────────────────────────────────────────────────
   SMOOTH ANCHOR SCROLLING
   (Tailwind's scroll-smooth on <html> handles most cases, but this ensures
    correct offset for the sticky nav on smaller viewports.)
───────────────────────────────────────────────────────────────────────────── */

document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (e) => {
        const targetId = anchor.getAttribute('href').slice(1);
        const target   = document.getElementById(targetId);
        if (!target) return;
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});
