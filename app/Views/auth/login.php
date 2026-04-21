<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IBEMS Login</title>
    <style>
        :root {
            --bg: #f6f7fb;
            --card: #ffffff;
            --text: #1b2430;
            --muted: #5f6b7a;
            --line: #d9deea;
            --primary: #1d4ed8;
            --primary-hover: #1e40af;
            --danger: #b91c1c;
            --ok: #166534;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(165deg, #eef2ff, var(--bg));
            color: var(--text);
            display: grid;
            place-items: center;
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 1.5rem;
        }

        p {
            margin: 0 0 20px;
            color: var(--muted);
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.92rem;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 0.95rem;
        }

        input:focus {
            outline: 2px solid rgba(29, 78, 216, 0.24);
            border-color: var(--primary);
        }

        button {
            width: 100%;
            border: 0;
            border-radius: 8px;
            padding: 11px 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            color: #fff;
            background: var(--primary);
        }

        button:hover {
            background: var(--primary-hover);
        }

        button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        #status {
            min-height: 20px;
            margin-top: 12px;
            font-size: 0.9rem;
        }

        .error {
            color: var(--danger);
        }

        .ok {
            color: var(--ok);
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>IBEMS Login</h1>
        <p>Sign in to continue to the system.</p>

        <form id="login-form">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" required autocomplete="username">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">

            <button id="submit-btn" type="submit">Login</button>
        </form>

        <div id="status"></div>
    </main>

    <script>
        const statusEl = document.getElementById('status');
        const formEl = document.getElementById('login-form');
        const submitBtn = document.getElementById('submit-btn');

        function setStatus(message, type) {
            statusEl.textContent = message || '';
            statusEl.className = type || '';
        }

        function targetPathByRole(role) {
            if (role === 'STORE_SYSTEM') return '/store/pos';
            if (role === 'ADMIN' || role === 'ACCOUNTING_OFFICE') return '/dashboard';
            if (role === 'USER') return '/user/dashboard';
            return '/login';
        }


        async function tryRedirectIfLoggedIn() {
            try {
                const meResp = await fetch('/auth/me');
                const meData = await meResp.json();

                if (meData && meData.status === 'success' && meData.user && meData.user.role) {
                    window.location.href = targetPathByRole(meData.user.role);
                }
            } catch (err) {
                // Ignore and stay on login page.
            }
        }

        formEl.addEventListener('submit', async (event) => {
            event.preventDefault();
            setStatus('', '');
            submitBtn.disabled = true;

            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;

            try {
                const response = await fetch('/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password })
                });

                const data = await response.json();

                if (!data || data.status !== 'success') {
                    setStatus(data && data.message ? data.message : 'Login failed.', 'error');
                    return;
                }

                const role = data.user && data.user.role ? data.user.role : null;
                setStatus('Login successful. Redirecting...', 'ok');
                window.location.href = targetPathByRole(role);
            } catch (err) {
                setStatus('Unable to reach server. Please try again.', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        tryRedirectIfLoggedIn();
    </script>
</body>
</html>
