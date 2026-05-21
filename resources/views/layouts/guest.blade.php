<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VietFeed') }} — {{ $title ?? 'Đăng nhập' }}</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Be+Vietnam+Pro:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --bg:           #0B0B0F;
            --surface:      #141418;
            --surface-alt:  #1C1C22;
            --border:       #2A2A32;
            --text:         #E8E8EC;
            --text-muted:   #8888A0;
            --accent:       #E63946;
            --accent-hover: #FF4D5A;
        }
        [data-theme="light"] {
            --bg:           #F5F5F0;
            --surface:      #FFFFFF;
            --surface-alt:  #FAFAF7;
            --border:       #E0E0D8;
            --text:         #1A1A1F;
            --text-muted:   #6B6B78;
            --accent:       #D62839;
            --accent-hover: #E63946;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Be Vietnam Pro', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 100%;
            max-width: 440px;
            padding: 2.5rem 2rem;
        }

        .auth-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: -0.02em;
            text-decoration: none;
        }

        .auth-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.25rem;
        }

        .auth-subtitle {
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        .form-label {
            color: var(--text-muted);
            font-size: 0.8125rem;
            font-weight: 500;
            margin-bottom: 0.375rem;
        }

        .form-control {
            background: var(--surface-alt);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 0.625rem 0.875rem;
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 0.9375rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus {
            background: var(--surface-alt);
            border-color: var(--accent);
            color: var(--text);
            box-shadow: 0 0 0 3px rgba(230, 57, 70, 0.15);
        }
        .form-control::placeholder {
            color: var(--text-muted);
        }
        .form-control.is-invalid {
            border-color: #dc3545;
        }

        .btn-accent {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: 'Be Vietnam Pro', sans-serif;
            font-weight: 600;
            font-size: 0.9375rem;
            padding: 0.625rem 1.25rem;
            transition: background 0.2s ease, transform 0.15s ease;
        }
        .btn-accent:hover {
            background: var(--accent-hover);
            color: #fff;
            transform: translateY(-1px);
        }
        .btn-accent:active {
            transform: translateY(0);
        }

        .form-check-input:checked {
            background-color: var(--accent);
            border-color: var(--accent);
        }
        .form-check-input {
            background-color: var(--surface-alt);
            border-color: var(--border);
        }

        .auth-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.2s;
        }
        .auth-link:hover {
            color: var(--accent-hover);
        }

        .auth-divider {
            border-color: var(--border);
            opacity: 1;
        }

        .invalid-feedback {
            font-size: 0.8125rem;
        }

        .alert-success {
            background: rgba(25, 135, 84, 0.15);
            border: 1px solid rgba(25, 135, 84, 0.3);
            color: #75b798;
            border-radius: 8px;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="auth-card shadow-lg">
        <div class="text-center mb-4">
            <a href="/" class="auth-brand">VietFeed</a>
        </div>
        {{ $slot }}
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
