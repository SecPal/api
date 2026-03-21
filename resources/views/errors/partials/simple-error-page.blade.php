{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} | SecPal API</title>
    <style>
        :root {
            color-scheme: light dark;
            --background: #ffffff;
            --foreground: #111827;
            --muted: #6b7280;
            --primary: #4f46e5;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #111827;
                --foreground: #f9fafb;
                --muted: #9ca3af;
                --primary: #818cf8;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Inter", "Segoe UI", sans-serif;
            background: var(--background);
            color: var(--foreground);
        }

        .page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 96px 24px;
        }

        .content {
            width: 100%;
            max-width: 42rem;
            text-align: center;
        }

        .logo {
            display: block;
            width: auto;
            height: 48px;
            margin: 0 auto;
            object-fit: contain;
        }

        .eyebrow {
            margin: 64px 0 0;
            font-size: 1rem;
            line-height: 2rem;
            font-weight: 600;
            color: var(--primary);
        }

        .title {
            margin: 16px 0 0;
            font-size: clamp(3rem, 7vw, 4.5rem);
            line-height: 1;
            letter-spacing: -0.04em;
            font-weight: 600;
        }

        .copy {
            margin: 24px auto 0;
            max-width: 36rem;
            font-size: clamp(1.05rem, 2.3vw, 1.25rem);
            line-height: 2rem;
            font-weight: 500;
            color: var(--muted);
        }

        @media (min-width: 640px) {
            .logo {
                height: 56px;
            }
        }
    </style>
</head>
<body>
    <main class="page">
        <section class="content" aria-labelledby="error-title">
            <picture>
                <source media="(prefers-color-scheme: dark)" srcset="/secpal-logo-dark.png">
                <img class="logo" src="/secpal-logo-light.png" alt="SecPal Logo">
            </picture>

            <p class="eyebrow">{{ $status }}</p>
            <h1 id="error-title" class="title">{{ $title }}</h1>
            <p class="copy">{{ $message }}</p>
        </section>
    </main>
</body>
</html>
