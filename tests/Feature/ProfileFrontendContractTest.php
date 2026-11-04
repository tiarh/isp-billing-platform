<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProfileFrontendContractTest extends TestCase
{
    public function test_profile_forms_keep_accessible_processing_states_without_slop(): void
    {
        $files = [
            'resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.tsx',
            'resources/js/Pages/Profile/Partials/UpdatePasswordForm.tsx',
            'resources/js/Pages/Profile/Partials/DeleteUserForm.tsx',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(base_path($file));

            $this->assertStringNotContainsString('backdrop-blur', $source, $file);
            $this->assertStringNotContainsString('alert(', $source, $file);
        }

        $profile = (string) file_get_contents(base_path($files[0]));
        $password = (string) file_get_contents(base_path($files[1]));
        $delete = (string) file_get_contents(base_path($files[2]));

        $this->assertStringContainsString("processing ? 'Saving...' : 'Save'", $profile);
        $this->assertStringContainsString("processing ? 'Saving...' : 'Save'", $password);
        $this->assertStringContainsString('aria-hidden="true"', $profile);
        $this->assertStringContainsString('aria-hidden="true"', $password);
        $this->assertStringContainsString('aria-hidden="true"', $delete);
    }
}
