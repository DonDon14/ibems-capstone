<?php
declare(strict_types=1);
// Only the separately named browser clone may receive synthetic boundary fixtures.
$root = dirname(__DIR__, 2);
$path = realpath($root . '/writable/simulation/ibems-month-simulation-20261021.sqlite');
if (PHP_SAPI !== 'cli' || !$path || dirname($path) !== realpath($root . '/writable/simulation')) throw new RuntimeException('Browser QA clone missing.');
$db = new SQLite3($path);
if ($db->querySingle("SELECT employee_id FROM users WHERE id=501") !== 'SIM-501' || $db->querySingle('SELECT COUNT(*) FROM transactions') !== 251) throw new RuntimeException('Expected completed synthetic month only.');
$db->exec("INSERT INTO users (id,employee_id,name,email,password_hash,role,user_type,qr_token,base_salary,is_active,created_at) SELECT 904,'SIM-904','Simulation Legacy Accounting','legacy-accounting@simulation.ibems.local',password_hash,'ACCOUNTING_OFFICE','staff','SIM-904',40000,1,created_at FROM users WHERE id=3");
$db->exec("INSERT INTO user_roles (user_id,role,created_at) VALUES (904,'ACCOUNTING_OFFICE','2026-11-01 04:00:00')");
// Controlled starting credit is part of the synthetic boundary fixture, never a production decision.
$db->exec('UPDATE balances SET credit_limit=150 WHERE user_id=501');
$db->close();
echo "Prepared synthetic legacy-role and PHP150 boundary fixtures in browser clone.\n";
