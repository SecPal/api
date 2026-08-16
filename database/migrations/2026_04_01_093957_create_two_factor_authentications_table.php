<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Schema\Blueprint;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;

return TwoFactorAuthentication::migration()
    ->morph('uuid')
    ->with(function (Blueprint $table) {
        // Here you can add custom columns to the Two Factor table.
        //
        // $table->string('alias')->nullable();
    });
