<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingFormTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create KEK and tenant (no factory for TenantKey)
        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    }

    public function test_onboarding_form_template_can_be_created_with_factory(): void
    {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->assertTrue($template->exists);
        $this->assertSame($this->tenant->id, $template->tenant_id);
        $this->assertIsString($template->name);
        $this->assertIsArray($template->form_schema);
        $this->assertIsBool($template->is_required);
        $this->assertFalse($template->is_system_template);
        $this->assertIsInt($template->sort_order);
    }

    public function test_onboarding_form_template_has_tenant_relationship(): void
    {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->assertInstanceOf(TenantKey::class, $template->tenant);
        $this->assertSame($this->tenant->id, $template->tenant->id);
    }

    public function test_onboarding_form_template_has_submissions_relationship(): void
    {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'form_template_id' => $template->id,
        ]);

        $this->assertCount(1, $template->submissions);
        $this->assertInstanceOf(OnboardingFormSubmission::class, $template->submissions->first());
        $this->assertSame($submission->id, $template->submissions->first()->id);
    }

    public function test_system_template_has_null_tenant_id(): void
    {
        $template = OnboardingFormTemplate::factory()->systemTemplate()->create();

        $this->assertTrue($template->exists);
        $this->assertNull($template->tenant_id);
        $this->assertTrue($template->is_system_template);
        $this->assertTrue($template->is_required);
    }

    public function test_onboarding_form_template_casts_booleans_correctly(): void
    {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'is_system_template' => false,
        ]);

        $this->assertTrue($template->is_required);
        $this->assertFalse($template->is_system_template);

        $template->is_required = false;
        $template->is_system_template = true;
        $template->save();
        $template->refresh();

        $this->assertFalse($template->is_required);
        $this->assertTrue($template->is_system_template);
    }

    public function test_onboarding_form_template_casts_form_schema_as_array(): void
    {
        $schema = [
            'fields' => [
                ['name' => 'test_field', 'type' => 'text', 'required' => true],
            ],
        ];

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => $schema,
        ]);

        $this->assertIsArray($template->form_schema);
        $this->assertSame($schema, $template->form_schema);
        $this->assertIsArray($template->form_schema['fields']);
        $this->assertSame('test_field', $template->form_schema['fields'][0]['name']);
    }

    public function test_onboarding_form_template_factory_states_work_correctly(): void
    {
        $required = OnboardingFormTemplate::factory()->required()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $optional = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->assertTrue($required->is_required);
        $this->assertFalse($optional->is_required);
    }
}

