<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors

SPDX-License-Identifier: CC0-1.0
-->

# Issue: PHPStan Type Inference for Laravel Sanctum

**Status:** ✅ FIXED (Workaround applied)
**Type:** Technical Debt / Upstream Issue
**Priority:** LOW
**Created:** 2025-11-02

## Problem

PHPStan cannot infer the return type of `$user->currentAccessToken()` correctly, causing lint errors:

```php
$user->currentAccessToken()->delete();
// Error: Undefined method 'delete'
```

**Root Cause:**

- `currentAccessToken()` returns `Laravel\Sanctum\PersonalAccessToken|null`
- PHPStan doesn't recognize `PersonalAccessToken` extends Eloquent Model
- `delete()` method is defined on Model but not visible to static analysis

## Workaround Applied

Added explicit type hint in `AuthController::logout()`:

```php
/** @var \Laravel\Sanctum\PersonalAccessToken $token */
$token = $user->currentAccessToken();
$token->delete();
```

**Files Modified:**

- `app/Http/Controllers/AuthController.php` (line 58)

**Tests:** ✅ All logout tests passing (5/5)

## Long-Term Solutions

### Option 1: Upstream Fix (Recommended)

- Report issue to Laravel Sanctum maintainers
- Request PHPStan stub improvements for Sanctum types
- **Timeline:** 6+ months (upstream dependency)

### Option 2: Local PHPStan Extension

- Create custom PHPStan extension for Sanctum types
- Add to `phpstan.neon` configuration
- **Effort:** Medium (2-3 hours)
- **Maintenance:** Ongoing (updates needed)

### Option 3: Keep Workaround

- Current solution works well
- No performance impact
- Easy to understand
- **Recommended until Option 1 available**

## Related Issues

- Laravel Sanctum: <https://github.com/laravel/sanctum/issues> (to be filed)
- PHPStan Laravel: <https://github.com/larastan/larastan> (may have existing fix)

## Action Items

- [ ] Check if larastan/larastan has updates for Sanctum types
- [ ] File issue with Laravel Sanctum if not resolved by larastan
- [ ] Monitor upstream for fixes (quarterly check)
- [ ] Remove workaround once upstream fix available

## Discovery Context

Found during Production Test self-review (feat/password-reset branch).
Adhering to principle: **"Leave code better than you found it"**

---

**Last Updated:** 2025-11-02
**Fixed By:** GitHub Copilot (Production Test Phase)
