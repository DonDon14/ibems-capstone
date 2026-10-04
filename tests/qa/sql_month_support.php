<?php
declare(strict_types=1);
namespace App\Services;

/** QA-only clock: loaded before autoload, never installed as an application clock. */
final class SqlQaClock
{
    public static function guard(): void
    {
        $schema=(string)getenv('IBEMS_DATABASE_NAME');
        if(ENVIRONMENT!=='development'||getenv('IBEMS_SQL_MONTH_QA')!=='1'||getenv('IBEMS_DATABASE_DRIVER')!=='MySQLi'||getenv('IBEMS_DATABASE_HOSTNAME')!=='127.0.0.1'||preg_match('/^ibems_sqlqa_\d{8}_[a-f0-9]{12}$/D',$schema)!==1)throw new \RuntimeException('Guarded disposable SQL QA schema required.');
        $db=\Config\Database::connect();
        if($db->database!==$schema||$db->DBDriver!=='MySQLi'||$db->hostname!=='127.0.0.1'||!$db->tableExists('ibems_qa_metadata'))throw new \RuntimeException('Resolved SQL QA target mismatch.');
        $marker=$db->table('ibems_qa_metadata')->get()->getRowArray();
        if(($marker['marker']??'')!=='synthetic-full-month-v1'||!hash_equals((string)($marker['nonce']??''),(string)getenv('IBEMS_SQL_QA_NONCE')))throw new \RuntimeException('SQL QA marker mismatch.');
    }
    public static function now(): \DateTimeImmutable
    {
        $raw=(string)getenv('IBEMS_SIMULATION_NOW');if($raw==='')return new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        self::guard();$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new \DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$raw)throw new \RuntimeException('Exact UTC QA timestamp required.');return $date;
    }
    public static function portalLabel(): ?string
    {
        return getenv('IBEMS_SIMULATION_NOW')?self::now()->setTimezone(new \DateTimeZone('Asia/Manila'))->format('F j, Y g:i A'):null;
    }
}
if(class_exists(SimulationClock::class,false))throw new \RuntimeException('Application clock loaded before guarded QA clock.');
class_alias(SqlQaClock::class,SimulationClock::class);

final class SqlQaEvidence
{
    public static function path(string $schema): string
    {
        SqlQaClock::guard();if($schema!==getenv('IBEMS_DATABASE_NAME'))throw new \RuntimeException('Evidence target mismatch.');
        $folder=WRITEPATH.'simulation/sql-month-'.$schema;if(!is_dir($folder))mkdir($folder,0777,true);return $folder.'/core.json';
    }
    public static function save(string $schema,array $summary): string
    {
        $path=self::path($schema);$summary['driver']='MySQLi';$summary['schema']=$schema;$summary['qa_clock']='Guarded QA-only replacement; application SQLite clock unchanged';
        $stream=fopen($path,'xb');if(!$stream)throw new \RuntimeException('Cannot overwrite evidence');fwrite($stream,json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($stream);return $path;
    }
    public static function integrity(): bool
    {
        SqlQaClock::guard();$db=\Config\Database::connect();
        $fks=$db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL',[$db->database])->getResultArray();
        foreach($fks as $fk){foreach($fk as $identifier)if(preg_match('/^[a-zA-Z0-9_]+$/D',$identifier)!==1)throw new \RuntimeException('Unexpected constraint identifier');
            $n=$db->query('SELECT COUNT(*) AS n FROM `'.$fk['TABLE_NAME'].'` c LEFT JOIN `'.$fk['REFERENCED_TABLE_NAME'].'` p ON c.`'.$fk['COLUMN_NAME'].'`=p.`'.$fk['REFERENCED_COLUMN_NAME'].'` WHERE c.`'.$fk['COLUMN_NAME'].'` IS NOT NULL AND p.`'.$fk['REFERENCED_COLUMN_NAME'].'` IS NULL')->getRowArray()['n'];if((int)$n!==0)throw new \RuntimeException('Orphaned SQL QA data in '.$fk['TABLE_NAME']);
        }
        return true;
    }
}
