<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class RenderDeploymentConventionTest extends CIUnitTestCase
{
    public function testBlueprintUsesFreeDockerServiceAndSecretPlaceholders(): void
    {
        $blueprint = (string) file_get_contents(ROOTPATH . 'render.yaml');

        $this->assertStringContainsString('runtime: docker', $blueprint);
        $this->assertStringContainsString('plan: free', $blueprint);
        $this->assertStringContainsString('name: ibems-production', $blueprint);
        $this->assertStringContainsString('region: singapore', $blueprint);
        $this->assertStringContainsString('branch: production', $blueprint);
        $this->assertStringContainsString('autoDeployTrigger: off', $blueprint);
        $this->assertStringContainsString('healthCheckPath: /healthz', $blueprint);
        $this->assertStringContainsString('IBEMS_DATABASE_PASSWORD', $blueprint);
        $this->assertStringContainsString('IBEMS_SUPABASE_SECRET_KEY', $blueprint);
        $this->assertGreaterThanOrEqual(2, substr_count($blueprint, 'sync: false'));
        $this->assertStringNotContainsString('database.default.password', $blueprint);
    }

    public function testContainerUsesPublicDocumentRootAndHostedBootstrap(): void
    {
        $dockerfile = (string) file_get_contents(ROOTPATH . 'Dockerfile');
        $entrypoint = (string) file_get_contents(ROOTPATH . 'deploy/render-entrypoint.sh');
        $vhost = (string) file_get_contents(ROOTPATH . 'deploy/apache-vhost.conf');

        $this->assertStringContainsString('APACHE_DOCUMENT_ROOT=/var/www/html/public', $dockerfile);
        $this->assertStringContainsString('ibems:hosted-bootstrap', $entrypoint);
        $this->assertStringContainsString('RENDER_EXTERNAL_URL', $entrypoint);
        $this->assertStringContainsString('DocumentRoot /var/www/html/public', $vhost);
        $this->assertStringContainsString('X-Forwarded-Proto', $vhost);
    }

    public function testHostedBootstrapIsAdditiveAndHealthRouteIsPublic(): void
    {
        $command = (string) file_get_contents(APPPATH . 'Commands/IbemsHostedBootstrap.php');
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $sessions = (string) file_get_contents(ROOTPATH . 'database/postgresql/011_render_hosted_sessions.sql');

        $this->assertStringContainsString("['010_salary_grade_profiles.sql', '011_render_hosted_sessions.sql']", $command);
        $this->assertStringContainsString("\$routes->get('healthz', 'PageController::health')", $routes);
        $this->assertStringContainsString('create table if not exists public.ci_sessions', strtolower($sessions));
        $this->assertStringNotContainsString('truncate', strtolower($sessions));
        $this->assertStringNotContainsString('drop table', strtolower($sessions));
    }
}
