<?php
declare(strict_types=1);
// Read-only reconciliation of a completed, marked synthetic fixture.
$boot=file_get_contents(__DIR__.'/sql_month_core.php');
$prefix=substr($boot,5,strpos($boot,"\$source=file_get_contents")-5);
eval($prefix);
$db=Config\Database::connect();$db->query('SET TRANSACTION READ ONLY');$db->query('START TRANSACTION');
$checks=[];
$check=function(string $name,bool $valid)use(&$checks):void{$checks[]=['check'=>$name,'passed'=>$valid];if(!$valid)throw new RuntimeException($name);};
$rows=fn(string $sql):array=>$db->query($sql)->getResultArray();
$n=fn(string $sql):float=>(float)$db->query($sql)->getRowArray()['n'];
try{
 $window="created_at >= '2026-09-30 16:00:00' AND created_at < '2026-10-31 16:00:00'";
 $check('Manila October sale count', $n("SELECT COUNT(*) n FROM transactions WHERE $window")===251.0);
 $check('Manila October sales 17185', $n("SELECT SUM(amount) n FROM transactions WHERE $window")===17185.0);
 $paymentWindow=str_replace('created_at','t.created_at',$window);
 foreach(['cash'=>12760,'debt'=>3185,'gcash'=>1240] as $method=>$amount){$actual=$n("SELECT SUM(p.amount) n FROM transaction_payments p JOIN transactions t ON t.id=p.transaction_id WHERE $paymentWindow AND p.payment_method='$method'");$check('October '.$method.' payment total',$actual===$amount*1.0);}
 $check('Every sale equals its payment rows',$n('SELECT COUNT(*) n FROM transactions t LEFT JOIN (SELECT transaction_id,SUM(amount) a FROM transaction_payments GROUP BY transaction_id) p ON p.transaction_id=t.id WHERE p.a IS NULL OR ABS(t.amount-p.a)>0.001')===0.0);
 $last=[];
 foreach($rows('SELECT * FROM debt_cashbook_entries ORDER BY id') as $row){$id=(int)$row['user_id'];$before=(float)$row['debt_before'];$after=(float)$row['debt_after'];$delta=(float)$row['amount']*($row['direction']==='debit'?1:-1);$check('cashbook arithmetic '.$row['id'],abs($after-$before-$delta)<0.001);if(isset($last[$id]))$check('cashbook chain '.$row['id'],abs($before-$last[$id])<0.001);$last[$id]=$after;}
 foreach($rows('SELECT user_id,current_debt FROM balances') as $row){$id=(int)$row['user_id'];$check('balance matches final cashbook '.$id,abs((float)$row['current_debt']-($last[$id]??0))<0.001);}
 $check('faculty carryover 80',$n('SELECT current_debt n FROM balances WHERE user_id=501')===80.0);$check('staff carryover 100',$n('SELECT current_debt n FROM balances WHERE user_id=503')===100.0);
 $items=$rows('SELECT user_id,requested_amount,confirmed_amount,reason_code,result_status FROM deduction_batch_items WHERE batch_id=4 ORDER BY user_id');
 $check('partial and zero recorded exactly',count($items)===2&&(int)$items[0]['user_id']===501&&(float)$items[0]['requested_amount']===100.0&&(float)$items[0]['confirmed_amount']===20.0&&$items[0]['reason_code']==='insufficient_salary'&&(int)$items[1]['user_id']===503&&(float)$items[1]['confirmed_amount']===0.0&&$items[1]['reason_code']==='not_deducted');
 $check('batch finalized',$rows('SELECT status FROM deduction_batches WHERE id=4')[0]['status']==='finalized');
 $check('department outstanding separate and settled',$n('SELECT SUM(outstanding_amount) n FROM department_debt_periods')===0.0);
 $check('department settlement exactly once',$n("SELECT COUNT(*) n FROM department_debt_entries WHERE reference_no='SQL-BROWSER-DEPT-25' AND amount=25")===1.0);
 $check('browser report received and furnished',$n("SELECT COUNT(*) n FROM daily_store_reports WHERE id=187 AND status='cashier_received' AND cashier_received_at IS NOT NULL AND accounting_furnished_at IS NOT NULL")===1.0);
 $check('independent investigation closed without reversal',$n("SELECT COUNT(*) n FROM debt_investigations WHERE id=2 AND status='closed' AND recommended_by=904 AND approved_by=3 AND reversal_cashbook_entry_id IS NULL")===1.0);
 $check('both browser rentals returned and refunded',$n("SELECT COUNT(*) n FROM rental_bookings WHERE id IN (63,64) AND status='returned' AND refunded_cents=collected_cents")===2.0);
 $audit=$rows("SELECT actor_id,payload_json FROM audit_logs WHERE action='ACCOUNTING_UPDATE_FINANCIAL_PROFILE' ORDER BY id DESC LIMIT 1");$payload=json_decode($audit[0]['payload_json']??'{}',true);
 $check('authorized Accounting audit reason and snapshots',count($audit)===1&&(int)$audit[0]['actor_id']===3&&($payload['reason']??'')==='Synthetic client-policy credit review'&&(float)($payload['previous_credit_limit']??0)===150.0&&(float)($payload['new_credit_limit']??0)===12800.0);
 $check('foreign keys contain no orphans',App\Services\SqlQaEvidence::integrity());
 $db->query('ROLLBACK');$out=['status'=>'passed','schema'=>$db->database,'read_only'=>true,'checks'=>count($checks),'results'=>$checks];file_put_contents(ROOTPATH.'build/logs/mariadb-browser-reconciliation.json',json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode(['status'=>'passed','checks'=>count($checks)]).PHP_EOL;
}catch(Throwable $e){$db->query('ROLLBACK');throw $e;}
