<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Repair databases migrated before addColumn's Forge FK behavior was corrected. */
class RepairPaymentDestinationForeignKey extends Migration
{
    public function up()
    {
        if (!in_array($this->db->DBDriver, ['MySQLi', 'Postgre'], true)
            || !$this->db->tableExists('transaction_payments')
            || !$this->db->tableExists('payment_destination_accounts')
            || !$this->db->fieldExists('destination_account_id', 'transaction_payments')) {
            return;
        }

        foreach ($this->db->getForeignKeyData('transaction_payments') as $foreignKey) {
            if (in_array('destination_account_id', $foreignKey->column_name, true)
                && $foreignKey->foreign_table_name === $this->db->DBPrefix . 'payment_destination_accounts') {
                return;
            }
        }

        $orphans = $this->db->table('transaction_payments tp')
            ->join('payment_destination_accounts a', 'a.id = tp.destination_account_id', 'left')
            ->where('tp.destination_account_id IS NOT NULL', null, false)
            ->where('a.id IS NULL', null, false)
            ->countAllResults();
        if ($orphans > 0) {
            throw new \RuntimeException("Cannot add the payment-destination foreign key: {$orphans} payment rows reference missing accounts. Resolve those records first.");
        }

        $payments = $this->db->escapeIdentifiers($this->db->DBPrefix . 'transaction_payments');
        $accounts = $this->db->escapeIdentifiers($this->db->DBPrefix . 'payment_destination_accounts');
        $this->db->query("ALTER TABLE {$payments} ADD CONSTRAINT fk_txn_payment_destination FOREIGN KEY (destination_account_id) REFERENCES {$accounts}(id) ON DELETE SET NULL ON UPDATE CASCADE");
    }

    public function down()
    {
        // Retain the integrity constraint on rollback; it may predate this repair.
    }
}
