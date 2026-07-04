{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

@include('errors.partials.simple-error-page', [
    'status' => '404',
    'title' => 'Page not found',
    'message' => 'Sorry, we couldn\'t find the page you\'re looking for.',
])
