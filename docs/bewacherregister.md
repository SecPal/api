<!--
SPDX-FileCopyrightText: 2024-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Bewacherregister reporting and personnel records

Operators submit reports in the official Bewacherregister (BWR). The SecPal
API checks readiness to record a manual submission and records the submission
date and the result reported by the competent authority.
[Official Bewacherregister portal and help](https://info.bewacherregister.de/)

The OpenAPI specification defines the supported requests, responses, and
status values. The API readiness service and controller tests show the
current checks and handling of submission evidence.
[Public OpenAPI specification](https://github.com/SecPal/contracts/blob/main/docs/openapi.yaml) ·
[Reporting readiness](../app/Services/BwrReportingReadiness.php) ·
[Employee controller tests](../tests/Feature/Controllers/EmployeeControllerTest.php)

The personnel implementation tracks changes to evidence, document access, and
retention separately from employment state.
[Personnel evidence delivery](https://github.com/SecPal/api/issues/3113)

## Legal sources

The responsible operator assesses the legal requirements for its reporting,
records, and processing of personal data.

- Security trade and personnel qualification requirements. [§ 34a GewO](https://www.gesetze-im-internet.de/gewo/__34a.html)
- Register and portal. [§ 11b GewO](https://www.gesetze-im-internet.de/gewo/__11b.html) · [Bewacherregisterverordnung](https://www.gesetze-im-internet.de/bewachrv/BJNR088210019.html)
- Personnel registration, deregistration, recordkeeping, and retention. [§ 16 BewachV](https://www.gesetze-im-internet.de/bewachv_2019/__16.html) · [§ 21 BewachV](https://www.gesetze-im-internet.de/bewachv_2019/__21.html)
- Personal data protection. [Regulation (EU) 2016/679](https://eur-lex.europa.eu/legal-content/EN/TXT/PDF/?uri=CELEX%3A32016R0679)
