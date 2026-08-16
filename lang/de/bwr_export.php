<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'missing_fields' => [
        'first_name' => 'Der Vorname muss im Mitarbeiterprofil gesetzt sein.',
        'last_name' => 'Der Nachname muss im Mitarbeiterprofil gesetzt sein.',
        'date_of_birth' => 'Das Geburtsdatum muss im Mitarbeiterprofil gesetzt sein.',
        'gender' => 'Das Geschlecht muss im Mitarbeiterprofil gesetzt sein.',
        'birth_city' => 'Der Geburtsort (Ort) muss im Mitarbeiterprofil gesetzt sein.',
        'birth_country' => 'Das Geburtsland muss im Mitarbeiterprofil gesetzt sein.',
        'nationalities' => 'Die Staatsangehörigkeiten müssen im Mitarbeiterprofil gesetzt sein.',
        'address_street' => 'Die Straße in der Adresse des Mitarbeiters muss gesetzt sein.',
        'address_house_number' => 'Die Hausnummer in der Adresse des Mitarbeiters muss gesetzt sein.',
        'address_postal_code' => 'Die Postleitzahl in der Adresse des Mitarbeiters muss gesetzt sein.',
        'address_city' => 'Der Ort in der Adresse des Mitarbeiters muss gesetzt sein.',
        'address_country' => 'Das Land in der Adresse des Mitarbeiters muss gesetzt sein.',
        'current_address_missing' => 'Es ist keine aktuelle Anschrift hinterlegt (genau eine Adresse ohne Enddatum).',
        'current_address_ambiguous' => 'Es sind mehrere aktuelle Anschrift-Zeilen vorhanden — es darf nur eine Zeile ohne Enddatum geben.',
        'current_address_resided_from_required' => 'Bei vorhandener Anschrift-Historie muss für die aktuelle Anschrift ein „wohnhaft seit“-Datum gesetzt sein.',
        'address_history_incomplete' => 'Die Ansässigkeit ist für den Export nicht vollständig (letzte fünf Jahre lückenlos bis heute erforderlich).',
        'address_history_gap' => 'Die Ansässigkeit ist für den Export nicht durchgehend — es gibt eine Lücke zwischen gespeicherten Zeiträumen.',
        'address_history_overlap' => 'Die Ansässigkeit für den Export darf sich nicht überschneiden — Zeiträume überlappen.',
        'intended_activities' => 'Die geplanten Tätigkeiten nach §34a GewO müssen vor dem Export im Mitarbeiterprofil gesetzt sein (bei Bedarf durch HR, falls nicht beim Onboarding erfasst).',
        'id_document_type' => 'Die Ausweisart muss im Mitarbeiterprofil gesetzt sein.',
        'id_document_number' => 'Die Ausweisnummer muss im Mitarbeiterprofil gesetzt sein.',
        'id_document_expiry' => 'Das Ablaufdatum des Ausweises muss im Mitarbeiterprofil gesetzt sein.',
        'sachkunde_type' => 'Die Sachkunde / Eingruppierung (§34a) muss vor dem Export gesetzt sein.',
        'sachkunde_certificate' => 'Der Nachweis / das Sachkunde-Zertifikat muss vor dem Export gesetzt sein.',
        'id_document_expiry_expired' => 'Der Ausweis ist abgelaufen — bitte das Ablaufdatum vor dem Export aktualisieren.',
        'valid_work_authorization' => 'Für diese Staatsangehörigkeit ist eine gültige Arbeitserlaubnis vor dem Export erforderlich.',
    ],
];
