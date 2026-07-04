{{-- SPDX-FileCopyrightText: 2026 SecPal Contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution --}}

@include('errors.partials.simple-error-page', [
    'status' => '403',
    'title' => 'Access forbidden',
    'message' => 'Sorry, you do not have permission to view this page.',
])
