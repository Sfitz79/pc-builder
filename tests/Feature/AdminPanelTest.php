<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        foreach (['component', 'category', 'build', 'order', 'software_product'] as $resource) {
            foreach (['view_any', 'view', 'create', 'update', 'delete', 'delete_any'] as $action) {
                $role->givePermissionTo(Permission::create([
                    'name' => "{$action}::{$resource}",
                    'guard_name' => 'web',
                ]));
            }
        }

        $user = User::create([
            'name' => 'Simon',
            'email' => 'simon@pctechguyonline.com',
            'password' => bcrypt('test-password'),
        ]);

        $user->assignRole($role);

        return $user;
    }

    public function test_admin_login_page_is_public(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_admin_pages_render_for_admin_user(): void
    {
        $this->actingAs($this->adminUser());

        $this->get('/admin')->assertOk();
        $this->get('/admin/components')->assertOk();
        $this->get('/admin/categories')->assertOk();
        $this->get('/admin/builds')->assertOk();
        $this->get('/admin/orders')->assertOk();
        $this->get('/admin/software-products')->assertOk();
    }

    public function test_sitemap_returns_valid_xml(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $this->assertStringStartsWith('<?xml', $response->getContent());
    }
}