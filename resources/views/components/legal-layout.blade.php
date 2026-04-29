<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index, follow">
    <title>{{ $title ?? 'U-fit' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            color: #1f2937;
            background: #f9fafb;
            line-height: 1.65;
        }
        .container {
            max-width: 760px;
            margin: 0 auto;
            padding: 3rem 1.5rem 5rem;
        }
        header {
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1.25rem;
            margin-bottom: 2rem;
        }
        header .brand {
            font-size: 1.1rem;
            font-weight: 700;
            color: #111827;
            text-decoration: none;
            letter-spacing: -0.01em;
        }
        header .brand a { color: inherit; text-decoration: none; }
        header nav {
            margin-top: 0.75rem;
            display: flex;
            gap: 1.5rem;
            font-size: 0.85rem;
        }
        header nav a {
            color: #6b7280;
            text-decoration: none;
        }
        header nav a:hover { color: #111827; }
        header nav a.active {
            color: #111827;
            font-weight: 600;
        }
        h1 {
            font-size: 1.85rem;
            font-weight: 800;
            color: #111827;
            margin: 0 0 0.5rem;
            letter-spacing: -0.02em;
        }
        .effective-date {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 2rem;
        }
        h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #111827;
            margin: 2.25rem 0 0.85rem;
            letter-spacing: -0.01em;
        }
        h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
            margin: 1.5rem 0 0.5rem;
        }
        p { margin: 0 0 1rem; }
        ul, ol { padding-left: 1.5rem; margin: 0 0 1rem; }
        li { margin-bottom: 0.4rem; }
        a { color: #2563eb; text-decoration: underline; }
        a:hover { color: #1d4ed8; }
        strong { color: #111827; font-weight: 600; }
        hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 2.5rem 0;
        }
        .contact-block {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
        }
        footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 0.8rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="brand"><a href="https://u-fit.com.ua">U-fit</a></div>
            <nav>
                <a href="/privacy" @if(($active ?? '') === 'privacy') class="active" @endif>Політика конфіденційності</a>
                <a href="/terms" @if(($active ?? '') === 'terms') class="active" @endif>Умови</a>
                <a href="/data-deletion" @if(($active ?? '') === 'data-deletion') class="active" @endif>Видалення даних</a>
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer>
            © {{ date('Y') }} U-fit. Усі права захищені.
        </footer>
    </div>
</body>
</html>
