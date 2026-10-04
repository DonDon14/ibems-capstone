<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Authorization;

/**
 * @internal
 */
final class RolePermissionMatrixConventionTest extends CIUnitTestCase
{
    public function testDocumentedBusinessPolicyRolesMatchRouteAuthorization(): void
    {
        $document = file_get_contents(ROOTPATH . 'docs/ROLE_PERMISSION_MATRIX.md');
        $this->assertIsString($document);
        preg_match_all('/^\| `([a-z][a-z._]+)` \| ([^|]+) \|/m', $document, $rows, PREG_SET_ORDER);

        $documented = [];
        foreach ($rows as $row) {
            preg_match_all('/`([A-Z_]+)`/', $row[2], $roleMatches);
            $roles = $roleMatches[1];
            sort($roles);
            $documented[$row[1]] = $roles;
        }

        $authorization = new Authorization();
        $businessPolicies = array_diff(array_keys($authorization->policies), ['portal.enter', 'account.self']);
        sort($businessPolicies);
        $documentedPolicies = array_keys($documented);
        sort($documentedPolicies);
        $this->assertSame($businessPolicies, $documentedPolicies);

        foreach ($businessPolicies as $policy) {
            $roles = $authorization->rolesFor($policy);
            $this->assertIsArray($roles, $policy);
            sort($roles);
            $this->assertSame($roles, $documented[$policy], $policy);
        }
    }
}
