<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# PR #48 - Lokaler Code Review Report

**Datum:** 2025-11-01
**Branch:** feat/tenant-dek-encryption
**Reviewer:** GitHub Copilot (systematischer Review)
**Commits:** 17 (a4708c4 → ec758ec)

---

## 📊 Zusammenfassung

| Kategorie         | Status     | Bewertung                                     |
| ----------------- | ---------- | --------------------------------------------- |
| **Tests**         | ✅ PASS    | 101/101 (369 assertions)                      |
| **Code Style**    | ✅ PASS    | Laravel Pint, PHPStan Level Max               |
| **Architektur**   | ⚠️ REVIEW  | Separation of Concerns gut, aber PR zu groß   |
| **Security**      | ✅ PASS    | Keine Plaintext Leaks, Tenant Isolation OK    |
| **Documentation** | ⚠️ REVIEW  | Inline gut, API-Docs fehlen                   |
| **PR-Größe**      | ❌ PROBLEM | 48 files, 5737 lines - MUSS aufgeteilt werden |

---

## 🔍 KRITISCHE PROBLEME

### ❌ PROBLEM 1: PR-Größe (BLOCKER)

**Severity:** HIGH
**File Count:** 48 files
**Line Count:** 5,737 insertions, 21 deletions

**Problem:**

- PR ist **viel zu groß** für effektives Code Review
- GitHub Best Practice: Max 400 lines per PR
- Aktuell: **~14x zu groß**

**Lösung - Option A (EMPFOHLEN):**
Splitten in **3 logische PRs**:

```text
PR #48a: Core Encryption Infrastructure (MERGE FIRST)
├── app/Support/KeyStore.php
├── app/Support/BlindIndex.php
├── app/Casts/TenantEncrypted.php
├── app/Models/TenantKey.php
├── database/migrations/..._create_tenant_keys_table.php
├── config/keys.php
├── storage/testing/kek
├── tests/Unit/BlindIndexTest.php
├── tests/Feature/KeyStoreTest.php
└── tests/Feature/EnvironmentTest.php
~ 1200 lines

PR #48b: Person Model & Encryption (MERGE SECOND - depends on #48a)
├── app/Models/Person.php
├── app/Repositories/PersonRepository.php
├── database/migrations/..._create_person_table.php
├── database/migrations/..._add_nonce_columns_to_person_table.php
├── app/Console/Commands/RotateDekCommand.php
├── app/Console/Commands/RebuildIdxCommand.php
├── tests/Feature/PersonModelTest.php
├── tests/Feature/PersonRepositoryTest.php
├── tests/Feature/RotationCommandsTest.php
└── tests/Feature/TenantIsolationTest.php
~ 2000 lines

PR #48c: API Endpoints & Authorization (MERGE THIRD - depends on #48b)
├── app/Http/Controllers/Api/PersonController.php
├── app/Http/Requests/*.php
├── app/Http/Resources/*.php
├── app/Http/Middleware/SetTenant.php
├── app/Policies/PersonPolicy.php
├── routes/api.php
├── composer.json (Sanctum + Spatie)
├── config/sanctum.php
├── config/permission.php
├── database/migrations/..._create_permission_tables.php
├── database/migrations/..._create_personal_access_tokens_table.php
├── tests/Feature/ApiPersonsTest.php
├── tests/Feature/TenantMiddlewareTest.php
├── tests/Feature/AuditTest.php
└── tests/Feature/LoggingNoPlaintextTest.php
~ 2500 lines
```

**Lösung - Option B (Falls Aufteilen nicht möglich):**

- Als "foundational change" akzeptieren
- Reviewer **muss** commit-by-commit reviewen
- Jeder Commit sollte isoliert funktionieren

**Action:** ENTSCHEIDUNG ERFORDERLICH vor Merge

---

## ⚠️ WARNINGS (Nicht-Blocker, aber wichtig)

### ⚠️ WARNING 1: Fehlende API-Dokumentation

**Severity:** MEDIUM
**Betroffene Files:** routes/api.php, PersonController.php

**Problem:**

- Keine OpenAPI/Swagger Spec
- Request/Response Beispiele nur in Tests
- Keine API Version Management Strategie

**Empfehlung:**

- Ergänze PHPDoc mit `@api` Tags
- Erstelle `docs/API.md` mit cURL Beispielen
- Überlege Laravel Scribe oder l5-swagger

**Action:** Optional - kann in Follow-up PR

---

### ⚠️ WARNING 2: Cache Invalidation Strategy

**Severity:** MEDIUM
**File:** app/Support/KeyStore.php

**Problem:**

```php
// Zeile 146: Cache::flush() ist zu aggressiv
public function clearCache(?string $tenantId = null): void
{
    $this->kekCache = null;

    if ($tenantId) {
        Cache::forget("idx_key:{$tenantId}");
        Cache::forget("dek:{$tenantId}");
    } else {
        Cache::flush(); // ⚠️ Löscht ALLE Cache-Keys!
    }
}
```

**Risiko:**

- `Cache::flush()` löscht auch Session-Cache, View-Cache, etc.
- Kann Production-Performance beeinträchtigen

**Fix:**

```php
} else {
    // Nur Key-spezifische Tags flushen
    Cache::tags(['tenant_keys'])->flush();
}
```

**Action:** Fix in Follow-up oder jetzt

---

### ⚠️ WARNING 3: Missing Rate Limiting

**Severity:** MEDIUM
**File:** routes/api.php

**Problem:**

- Keine Rate Limits auf API Endpoints
- Potenzielle Brute-Force Attacks auf `/persons/by-email`

**Empfehlung:**

```php
Route::middleware(['auth:sanctum', 'throttle:60,1', SetTenant::class])
```

**Action:** Optional - kann in Security PR

---

## ✅ STRENGTHS (Was gut läuft)

### ✅ 1. Separation of Concerns

**Sehr gut implementiert:**

- `KeyStore`: Isoliert Key Management
- `BlindIndex`: Reine Utility-Class (stateless)
- `TenantEncrypted`: Single Responsibility (Encryption Cast)
- `PersonRepository`: Sauberes Repository Pattern

### ✅ 2. Security Best Practices

**Korrekt implementiert:**

- Nonce Uniqueness: `random_bytes(12)` per operation ✅
- Key Wrapping: libsodium secretbox ✅
- AEAD: AES-256-GCM mit authentication ✅
- Tenant Isolation: Middleware + DB constraints ✅
- No Plaintext Logging: Umfassend getestet ✅

### ✅ 3. Test Coverage

**Exzellent:**

- 101 Tests, 369 Assertions
- Unit + Feature Tests balanced
- Edge Cases abgedeckt (rotation, corruption, isolation)

### ✅ 4. Error Handling

**Robust:**

- `KeyStore`: RuntimeException mit klaren Messages
- `TenantEncrypted`: Nonce validation, decryption failure handling
- Commands: Proper exit codes, confirmation prompts

### ✅ 5. Code Quality

**Standards eingehalten:**

- PHP 8.4 strict types
- PSR-12 compliant (Pint)
- PHPStan Level Max (mit Baseline)
- REUSE SPDX compliant

---

## 🔧 MINOR ISSUES (Nice-to-have Fixes)

### 🔧 1. KeyStore: KEK Permission Check könnte strenger sein

**File:** app/Support/KeyStore.php:41

**Aktuell:**

```php
if (($perms & 0077) !== 0) {
    Log::warning('KEK file has insecure permissions'); // Nur Warning
}
```

**Empfehlung:**

```php
if (($perms & 0077) !== 0 && app()->environment('production')) {
    throw new \RuntimeException('KEK file MUST have 0600 permissions in production');
}
```

---

### 🔧 2. TenantEncrypted: Nonce wird nicht in Attributen gespeichert

**File:** app/Casts/TenantEncrypted.php:94

**Aktuell:**

```php
return [
    $key => $ciphertext,
    $nonceKey => $nonce, // Wird nur returned, nicht in $model->attributes gesetzt
];
```

**Problem:**

- Funktioniert, aber könnte verwirrend sein bei Debugging

**Empfehlung:**

- Dokumentieren, dass Laravel automatisch beide Werte setzt

---

### 🔧 3. BlindIndex: Fehlende Input Validation

**File:** app/Support/BlindIndex.php:62

**Aktuell:**

```php
public static function hmac(string $normalizedValue, string $idxKey): string
{
    if (strlen($idxKey) < 32) {
        throw new \InvalidArgumentException('Index key must be at least 32 bytes');
    }
    // ⚠️ Keine Validation für $normalizedValue
}
```

**Empfehlung:**

```php
if (empty($normalizedValue)) {
    throw new \InvalidArgumentException('Normalized value cannot be empty');
}
```

---

### 🔧 4. PersonRepository: Kein Pagination Limit

**File:** app/Repositories/PersonRepository.php

**Problem:**

- Keine Max-Limit für `findAll()` Pagination
- User könnte `?per_page=999999` anfordern

**Empfehlung:**

```php
public function findAll(string $tenantId, int $perPage = 15): LengthAwarePaginator
{
    $perPage = min($perPage, 100); // Hard limit
```

---

## 📝 DOCUMENTATION GAPS

### 📝 1. Fehlende ARCHITECTURE.md

**Was fehlt:**

- High-Level System Design Diagram
- Key Hierarchy Visualisierung
- Datenfluss: API Request → Encryption → DB

**Empfehlung:**

- Erstelle `docs/ARCHITECTURE.md`
- Nutze Mermaid Diagramme

---

### 📝 2. Fehlende DEPLOYMENT.md

**Was fehlt:**

- Production KEK Setup (HSM/KMS Integration)
- Backup/Restore Strategie für Keys
- Disaster Recovery Plan

**Empfehlung:**

- Erstelle `docs/DEPLOYMENT.md`
- Dokumentiere Key Rotation Procedure

---

### 📝 3. Fehlende CONTRIBUTING.md Updates

**Was fehlt:**

- TDD Workflow für neue Features
- Test Naming Conventions
- Security Review Checklist

---

## 🎯 ACTIONABLE RECOMMENDATIONS

### Sofort (vor Merge)

1. ❌ **PR aufteilen** in 3 separate PRs (siehe Option A oben)
   - Oder explizit als "foundational change" markieren
2. ⚠️ **Cache::flush()** durch gezielteres Flushing ersetzen
3. 🔧 **KEK permission check** für Production verschärfen

### Kurzfristig (Follow-up PR)

1. 📝 **API Dokumentation** erstellen (OpenAPI oder docs/API.md)
2. ⚠️ **Rate Limiting** auf API Endpoints
3. 🔧 **BlindIndex input validation** hinzufügen
4. 🔧 **PersonRepository pagination limit** setzen

### Mittelfristig (nächster Sprint)

1. 📝 **ARCHITECTURE.md** erstellen
2. 📝 **DEPLOYMENT.md** für Production Setup
3. 📝 **CONTRIBUTING.md** aktualisieren

---

## 🏆 ZUSAMMENFASSUNG

**Gesamtbewertung:** 8/10

**Stärken:**

- ✅ Exzellente Security Implementation
- ✅ Umfassende Test Coverage
- ✅ Saubere Architektur (Separation of Concerns)
- ✅ Code Quality Standards eingehalten

**Schwächen:**

- ❌ PR-Größe inakzeptabel für Review
- ⚠️ Cache Invalidation zu aggressiv
- 📝 Fehlende Dokumentation (API, Architecture, Deployment)

**Empfehlung:**

- **NEIN zum Merge in aktueller Form**
- **JA nach Aufteilen in 3 PRs**
- Optional: Cache + Permission Fixes vor Merge

---

## 👤 REVIEW SIGN-OFF

**Reviewed by:** GitHub Copilot AI
**Date:** 2025-11-01
**Status:** ⏸️ CHANGES REQUESTED (PR-Größe)

**Nächste Schritte:**

1. Entscheidung: PR aufteilen oder als foundational change akzeptieren?
2. Bei Aufteilen: Neue Branch-Strategie definieren
3. Bei Akzeptieren: Reviewer muss commit-by-commit reviewen
