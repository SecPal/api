{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

@include('errors.partials.simple-error-page', [
    'status' => '503',
    'title' => 'Service unavailable',
    'message' => 'Sorry, this service is temporarily unavailable. Please try again in a moment.',
])
