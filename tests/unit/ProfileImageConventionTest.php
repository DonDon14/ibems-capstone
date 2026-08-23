<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProfileImageConventionTest extends TestCase
{
    public function testEveryPortalUsesTheSessionProfileImageAndBundledDefault(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');
        $common = (string) file_get_contents(APPPATH . 'Common.php');
        $defaultImage = FCPATH . 'assets/images/default-profile.svg';

        $this->assertFileExists($defaultImage);
        $this->assertStringContainsString('function ibems_profile_image_url', $common);
        $this->assertStringContainsString('data-current-user-avatar', $shell);
        $this->assertStringContainsString('data-profile-image-open', $shell);
        $this->assertStringContainsString('id="account-menu-toggle"', $shell);
        $this->assertStringContainsString('Change or upload your account photo', $shell);
        foreach (['admin', 'accounting', 'store', 'store_admin', 'user'] as $layout) {
            $source = (string) file_get_contents(APPPATH . 'Views/layouts/' . $layout . '.php');
            $this->assertStringContainsString("session()->get('profile_image_url')", $source);
        }
    }

    public function testAuthenticatedUploadUsesTheManagedImageStoragePath(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/ProfileController.php');
        $storage = (string) file_get_contents(APPPATH . 'Services/AssetStorageService.php');

        $this->assertStringContainsString("profile/image', 'ProfileController::uploadImage'", $routes);
        $this->assertStringContainsString("profile/image', 'ProfileController::uploadImage', ['filter' => 'access:account.self']", $routes);
        $this->assertStringContainsString("storeImage(\$file, 'profile-images')", $controller);
        $this->assertStringContainsString("'USER_UPDATE_PROFILE_IMAGE'", $controller);
        $this->assertStringContainsString("private const MAX_FILE_SIZE = 2 * 1024 * 1024", $storage);
        $this->assertStringContainsString("'image/webp'", $storage);
    }

    public function testPrimaryUserIdentitySurfacesUseTheSharedAvatarRenderer(): void
    {
        $scripts = [
            FCPATH . 'assets/js/admin-user-view.js',
            FCPATH . 'assets/js/accounting-debts.js',
            FCPATH . 'assets/js/store-staff-records.js',
            FCPATH . 'assets/js/store-pos.js',
            FCPATH . 'assets/js/store-history.js',
            FCPATH . 'assets/js/admin-audit.js',
        ];

        foreach ($scripts as $script) {
            $source = (string) file_get_contents($script);
            $this->assertStringContainsString('IbemsAvatar.html', $source, basename($script));
        }

        $admin = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $accounting = (string) file_get_contents(APPPATH . 'Controllers/AccountingController.php');
        $store = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php');
        $this->assertStringContainsString('u.profile_image_url', $admin);
        $this->assertStringContainsString('u.profile_image_url', $accounting);
        $this->assertStringContainsString('u.profile_image_url', $store);

        foreach ([
            FCPATH . 'assets/js/admin-accounting-debts.js',
            FCPATH . 'assets/js/admin-stores.js',
            FCPATH . 'assets/js/accounting-dashboard.js',
            FCPATH . 'assets/js/store-admin-dashboard.js',
            FCPATH . 'assets/js/store-reports.js',
        ] as $identitySurface) {
            $this->assertStringContainsString('IbemsAvatar.html', (string) file_get_contents($identitySurface));
        }
    }

    public function testUserDashboardHeaderShowsTheCurrentProfileImage(): void
    {
        $dashboard = (string) file_get_contents(APPPATH . 'Views/user/dashboard.php');
        $pageHeader = (string) file_get_contents(APPPATH . 'Views/components/page_header.php');

        $this->assertStringContainsString("ibems_profile_image_url(session()->get('profile_image_url'))", $dashboard);
        $this->assertStringContainsString("'imageSize' => 'large'", $dashboard);
        $this->assertStringContainsString('page-header-icon--avatar', $pageHeader);
        $this->assertStringContainsString('page-header-icon--avatar-large', $pageHeader);
        $this->assertStringContainsString('data-current-user-avatar', $pageHeader);
    }
}
