<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Kontrak UI <-> backend untuk daftar "secret belum terhubung": nama route, halaman, tanpa password di UI.
 */
#[Group('blocking-core')]
class RoutersUnlinkedSecretsFrontendContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    public function test_section_calls_the_backend_route_with_page_and_search_and_fixed_25_per_page(): void
    {
        $section = $this->source('resources/js/Pages/Routers/Partials/UnlinkedSecretsSection.tsx');

        $this->assertStringContainsString('/api/routers/${routerId}/unlinked-secrets?', $section);
        $this->assertStringContainsString('page: String(page)', $section);
        $this->assertStringContainsString('search: debouncedSearch', $section);
        $this->assertStringContainsString('onPageChange={setPage}', $section);
        $this->assertStringContainsString('PER_PAGE_OPTIONS = [25]', $section);
        $this->assertStringNotContainsString('limit:', $section);
        $this->assertStringNotContainsString('per_page:', $section);

        // Route, nama, dan 25 per halaman di sisi backend harus cocok dengan UI.
        $routes = $this->source('routes/web-router-unlinked.php');
        $this->assertStringContainsString("'/api/routers/{router}/unlinked-secrets'", $routes);
        $this->assertStringContainsString("name('api.routers.unlinked-secrets.index')", $routes);
        $this->assertStringContainsString('PER_PAGE = 25', $this->source('app/Services/Routers/UnlinkedSecretQuery.php'));
        $this->assertStringContainsString("require __DIR__.'/web-router-unlinked.php';", $this->source('routes/web.php'));
    }

    public function test_row_action_links_to_customer_create_with_staged_id(): void
    {
        $section = $this->source('resources/js/Pages/Routers/Partials/UnlinkedSecretsSection.tsx');

        $this->assertStringContainsString("route('customers.create', { staged: row.id })", $section);
        $this->assertStringContainsString('Tambah pelanggan', $section);
        $this->assertStringContainsString('Terakhir terlihat', $section);
        $this->assertStringContainsString('Salin username', $section);
    }

    public function test_delete_button_and_dialog_follow_the_backend_contract(): void
    {
        $section = $this->source('resources/js/Pages/Routers/Partials/UnlinkedSecretsSection.tsx');
        $dialog = $this->source('resources/js/Pages/Routers/Partials/DeleteUnlinkedSecretDialog.tsx');
        $routes = $this->source('routes/web-router-unlinked.php');

        // Tombol hanya tampil kalau backend bilang boleh (can_delete); selain itu tersembunyi.
        $this->assertStringContainsString('data?.meta.can_delete ?? false', $section);
        $this->assertStringContainsString('{canDelete && (', $section);
        $this->assertStringContainsString('Hapus secret', $section);
        $this->assertStringContainsString("'can_delete' =>", $this->source('app/Http/Controllers/RouterUnlinkedSecretController.php'));

        // Dialog memanggil route yang sama dengan backend (delete-check lalu DELETE) dan mengirim isi yang diminta backend.
        $this->assertStringContainsString("'/api/routers/{router}/unlinked-secrets/{stagedCustomer}/delete-check'", $routes);
        $this->assertStringContainsString("'/api/routers/{router}/unlinked-secrets/{stagedCustomer}'", $routes);
        $this->assertStringContainsString('throttle:10,1', $routes);
        $this->assertStringContainsString('`${base}/delete-check`', $dialog);
        $this->assertStringContainsString("method: 'DELETE'", $dialog);
        foreach (['confirm_username', 'disconnect_online', 'lookalike_ack', 'expected_profile', 'expected_disabled', 'request_id'] as $field) {
            $this->assertStringContainsString($field.':', $dialog);
            $this->assertStringContainsString("'{$field}'", $this->source('app/Http/Controllers/RouterUnlinkedSecretDeleteController.php'));
        }

        // Ketik username persis, centang hanya kalau online, penolakan tanpa tombol konfirmasi.
        $this->assertStringContainsString('typedConfirmation={secret.username}', $dialog);
        $this->assertStringContainsString('Koneksi yang sedang aktif akan diputus', $dialog);
        $this->assertStringContainsString('{secret.online && (', $dialog);
        $this->assertStringContainsString('confirmDisabled={(secret.online && !tick) || (lookalikes.length > 0 && !lookalikeAck)}', $dialog);
        // Mirip pelanggan = peringatan + centang "sudah cek"; daftarnya datang dari delete-check (secret.lookalikes).
        $this->assertStringContainsString('Saya sudah cek, ini bukan pelanggan itu', $dialog);
        $this->assertStringContainsString('lookalikes: {', $dialog);
        $this->assertStringContainsString("'lookalikes' =>", $this->source('app/Services/Routers/UnlinkedSecretDeleter.php'));
        $this->assertStringContainsString('requireConfirmation={false}', $dialog);
        $this->assertStringContainsString("if (phase.name !== 'ready') {", $dialog);
        $this->assertStringContainsString('Hapus secret', $dialog);
    }

    public function test_create_form_shows_the_lookalike_hint_without_blocking(): void
    {
        $create = $this->source('resources/js/Pages/Customers/Create.tsx');

        $this->assertStringContainsString('lookalike_hint: string | null', $create);
        $this->assertStringContainsString('{prefill?.lookalike_hint && (', $create);
        $this->assertStringContainsString("'lookalike_hint' =>", $this->source('app/Services/Routers/UnlinkedSecretPrefill.php'));
    }

    public function test_notice_and_tab_wiring_in_show_page(): void
    {
        $notice = $this->source('resources/js/Pages/Routers/Partials/UnlinkedSecretsNotice.tsx');
        $this->assertStringContainsString('secret belum terhubung ke eBilling', $notice);
        $this->assertStringContainsString('Lihat daftar', $notice);

        $show = $this->source('resources/js/Pages/Routers/Show.tsx');
        $this->assertStringContainsString('<UnlinkedSecretsNotice', $show);
        $this->assertStringContainsString('<RouterCustomersTab', $show);
        $this->assertStringContainsString("setActiveTab('customers')", $show);

        $tab = $this->source('resources/js/Pages/Routers/Partials/RouterCustomersTab.tsx');
        $this->assertStringContainsString("get('tab')", $tab);
        $this->assertStringContainsString("get('section') === 'unlinked'", $tab);
        $this->assertStringNotContainsString('window.location', $tab);
        // Daftar dibaca ulang setiap tab dibuka (tidak memakai cache kunjungan sebelumnya).
        $this->assertStringContainsString('<SWRConfig value={{ provider: freshCache }}>', $tab);
        $this->assertStringContainsString('usePage().url', $show);
        $this->assertStringContainsString('scrollIntoView', $tab);

        // Pengalihan setelah simpan memakai parameter yang sama.
        $prefill = $this->source('app/Services/Routers/UnlinkedSecretPrefill.php');
        $this->assertStringContainsString("'tab' => 'customers', 'section' => 'unlinked'", $prefill);
    }

    public function test_customer_create_locks_user_and_router_when_prefilled_and_sends_the_staged_id(): void
    {
        $create = $this->source('resources/js/Pages/Customers/Create.tsx');

        $this->assertStringContainsString('prefill?: Prefill | null', $create);
        $this->assertStringContainsString('router_staged_customer_id: prefill ? String(prefill.router_staged_customer_id)', $create);
        $this->assertStringContainsString('readOnly={prefill !== null}', $create);
        $this->assertStringContainsString('disabled={prefill !== null}', $create);
        $this->assertStringContainsString('status: (prefill?.status ?? ', $create);
        $this->assertStringContainsString('Data dari secret di router {prefill.router_name}. Password dan profil di MikroTik tidak diubah.', $create);
        $this->assertStringContainsString('Aktif = pelanggan mulai ditagih.', $create);
    }

    public function test_new_components_never_handle_a_password_field(): void
    {
        foreach (['UnlinkedSecretsSection', 'UnlinkedSecretsNotice', 'RouterCustomersTab', 'DeleteUnlinkedSecretDialog'] as $name) {
            $source = strtolower($this->source("resources/js/Pages/Routers/Partials/{$name}.tsx"));
            $this->assertStringNotContainsString('password', $source, $name);
            $this->assertStringNotContainsString('raw_payload', $source, $name);
        }
    }
}
