<?php
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class AuditNotificationConnectionTest extends CIUnitTestCase
{
    public function testInjectedAuditConnectionOwnsNotificationAndRollback(): void
    {
        $db=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $db->query('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,is_active INTEGER)');
        $db->query('CREATE TABLE user_roles(user_id INTEGER,role TEXT)');
        $db->query('CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER,action TEXT,entity TEXT,entity_id INTEGER,payload_json TEXT,created_at TEXT)');
        $db->query('CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,txn_id INTEGER,audit_log_id INTEGER,notification_type TEXT,title TEXT,message TEXT,link_url TEXT,icon TEXT,severity TEXT,read_at TEXT,channel TEXT,status TEXT,email_status TEXT,email_attempts INTEGER,email_next_attempt_at TEXT,dedupe_key TEXT,created_at TEXT)');
        $db->table('users')->insert(['id'=>2,'role'=>'ADMIN','is_active'=>1]);
        $default=Database::connect();$before=$default->tableExists('notifications')?$default->table('notifications')->countAllResults():0;
        $db->transBegin();
        try{
            $audit=(new \App\Models\AuditLogModel($db))->insert(['actor_id'=>1,'action'=>'ADMIN_UPDATE_USER','entity'=>'users','entity_id'=>2,'payload_json'=>'{}','created_at'=>'2026-10-04 00:00:00']);
            $this->assertNotFalse($audit);
            $this->assertSame(1,$db->table('notifications')->countAllResults());
            $this->assertSame((int)$audit,(int)$db->table('notifications')->get()->getRow('audit_log_id'));
        }finally{$db->transRollback();}
        $this->assertSame(0,$db->table('audit_logs')->countAllResults());
        $this->assertSame(0,$db->table('notifications')->countAllResults());
        $this->assertSame($before,$default->tableExists('notifications')?$default->table('notifications')->countAllResults():0);
        $db->close();
    }
}
