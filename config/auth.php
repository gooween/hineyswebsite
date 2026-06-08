    <?php
    // ============================================================
    // Hiney's Eggs and Live Chicken Business
    // File: config/auth.php
    // Purpose: Session guards, role enforcement, no-cache headers
    //
    // HOW TO USE:
    //   Every admin page:   requireAdmin();
    //   Every user page:    requireCustomer();
    //   Optional redirect:  redirectIfLoggedIn();  ← use on login/register
    //
    // Already called by db.php after connection; you do NOT need to
    // require this file separately if you already require db.php.
    // ============================================================

    if (session_status() === PHP_SESSION_NONE) {
        // Harden the session cookie
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,      // set true if using HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // ── No-cache headers ─────────────────────────────────────────
    // Prevents the browser from serving a cached copy of a
    // protected page after logout (Back button exploit).
    function noCacheHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }

    // ── Redirect helper ──────────────────────────────────────────
    // Sends Location header and exits cleanly.
    function authRedirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    // ── Detect root depth for redirect paths ─────────────────────
    // Returns the path prefix needed to reach hineys_system/ root.
    // admin/ pages  → '../'
    // user/  pages  → '../'
    // root   pages  → './'
    function rootPath(): string
    {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        // Count how many levels below the project root we are
        if (str_contains($script, '/admin/') || str_contains($script, '/user/')) {
            return '../';
        }
        return './';
    }

    // ─────────────────────────────────────────────────────────────
    // requireAdmin()
    //   • Must be called at the very top of every admin/*.php file
    //   • Sends no-cache headers so Back button cannot show the page
    //   • Redirects to login if:
    //       – Not logged in at all
    //       – Role is not 'admin'
    //       – Account is deactivated (is_active = 0)
    // ─────────────────────────────────────────────────────────────
    function requireAdmin(): void
    {
        noCacheHeaders();

        $root = rootPath();

        if (empty($_SESSION['user_id'])) {
            // Not logged in → login page with flash message
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Please log in to access the admin panel.';
            authRedirect($root . 'index.php');
        }

        if (($_SESSION['role'] ?? '') !== 'admin') {
            // Wrong role (customer trying to access admin)
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Access denied. You do not have admin privileges.';

            // Send them to their correct area
            if (($_SESSION['role'] ?? '') === 'customer') {
                authRedirect($root . 'user/home.php');
            }
            authRedirect($root . 'index.php');
        }

        if (empty($_SESSION['is_active'])) {
            // Admin account was deactivated
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Your account has been deactivated. Please contact support.';
            authRedirect($root . 'index.php');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // requireCustomer()
    //   • Must be called at the very top of every user/*.php file
    //   • Sends no-cache headers
    //   • Redirects to login if not logged in or wrong role
    // ─────────────────────────────────────────────────────────────
    function requireCustomer(): void
    {
        noCacheHeaders();

        $root = rootPath();

        if (empty($_SESSION['user_id'])) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Please log in to continue.';
            authRedirect($root . 'index.php');
        }

        if (($_SESSION['role'] ?? '') !== 'customer') {
            // Admin trying to access user pages
            if (($_SESSION['role'] ?? '') === 'admin') {
                authRedirect($root . 'admin/dashboard.php');
            }
            authRedirect($root . 'index.php');
        }

        if (empty($_SESSION['is_active'])) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Your account has been deactivated. Please contact us for assistance.';
            authRedirect($root . 'index.php');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // redirectIfLoggedIn()
    //   • Use on index.php and register.php
    //   • Prevents already-logged-in users from seeing the login page
    // ─────────────────────────────────────────────────────────────
    function redirectIfLoggedIn(): void
    {
        if (empty($_SESSION['user_id'])) return;

        $root = rootPath();

        if (($_SESSION['role'] ?? '') === 'admin') {
            authRedirect($root . 'admin/dashboard.php');
        }

        authRedirect($root . 'user/home.php');
    }

    // ─────────────────────────────────────────────────────────────
    // isLoggedIn() — simple boolean check (no redirect)
    // ─────────────────────────────────────────────────────────────
    function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    // ─────────────────────────────────────────────────────────────
    // isAdmin() — simple boolean check (no redirect)
    // ─────────────────────────────────────────────────────────────
    function isAdmin(): bool
    {
        return isLoggedIn() && ($_SESSION['role'] ?? '') === 'admin';
    }

    // ─────────────────────────────────────────────────────────────
    // isCustomer() — simple boolean check (no redirect)
    // ─────────────────────────────────────────────────────────────
    function isCustomer(): bool
    {
        return isLoggedIn() && ($_SESSION['role'] ?? '') === 'customer';
    }
