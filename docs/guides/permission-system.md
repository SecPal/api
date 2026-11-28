<!-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# API access and authorization

Use the public OpenAPI specification for request and response details.
[SecPal OpenAPI specification](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml)

The API implementation and its tests show current authorization behavior.
The seeder defines repository defaults for roles and permissions. Use the
policies and feature tests to determine access in a specific context.

- [API routes](../../routes/api.php) and [policies](../../app/Policies)
- [Role and permission defaults](../../database/seeders/RolesAndPermissionsSeeder.php)
- [RBAC integration](../../tests/Feature/Integration/RbacIntegrationTest.php)
- [Role management](../../tests/Feature/RoleManagementApiTest.php) and
  [direct permission assignments](../../tests/Feature/UserPermissionAssignmentApiTest.php)
- [Temporal assignments](../../tests/Feature/Models/TemporalRoleUserTest.php)

The accepted target model uses ADR-014 for tenant identity and access. ADR-025
supersedes its decisions on employment and related time authorities. Follow
the tenant and access issue for current implementation status.
[ADR-014](https://github.com/SecPal/.github/blob/main/docs/adr/20260720-tenant-identity-access-model-adr014.md) ·
[ADR-025](https://github.com/SecPal/.github/blob/main/docs/adr/20261002-employment-time-absence-compensation-adr025.md) ·
[Implementation issue](https://github.com/SecPal/api/issues/1345)
