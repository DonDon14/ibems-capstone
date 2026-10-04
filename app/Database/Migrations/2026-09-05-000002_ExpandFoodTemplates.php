<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class ExpandFoodTemplates extends Migration
{
    public function up()
    {
        $id = ['type'=>'INT','unsigned'=>true,'auto_increment'=>true];
        $int = ['type'=>'INT','unsigned'=>true,'default'=>0];
        $this->forge->addField(['id'=>$id,'store_id'=>$int,'family_id'=>$int,'description'=>['type'=>'TEXT'],'allergens'=>['type'=>'TEXT'],'dietary'=>['type'=>'TEXT'],'tracking'=>['type'=>'VARCHAR','constraint'=>20],'version'=>$int]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey(['store_id','family_id']); $this->forge->createTable('food_template_details',true);
        $this->forge->addField(['id'=>$id,'store_id'=>$int,'family_id'=>$int,'business_date'=>['type'=>'DATE'],'capacity'=>$int,'used'=>$int,'closed'=>$int,'leftover'=>$int,'disposition'=>['type'=>'VARCHAR','constraint'=>150,'default'=>'']]);
        $this->forge->addKey('id',true); $this->forge->addUniqueKey(['store_id','family_id','business_date']); $this->forge->createTable('food_daily_batches',true);
        if (!$this->db->fieldExists('food_portions', 'products')) {
            $this->forge->addColumn('products',['food_portions'=>['type'=>'INT','default'=>1]]);
        }
        $columns = ['snapshot_json'=>['type'=>'TEXT','null'=>true], 'batch_id'=>$int,'portions'=>['type'=>'INT','default'=>1], 'closed'=>$int,'leftover'=>$int,'disposition'=>['type'=>'VARCHAR','constraint'=>150,'default'=>'']];
        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'daily_food_offerings')) {
                $this->forge->addColumn('daily_food_offerings', [$name => $definition]);
            }
        }
    }
    public function down()
    {
        $this->forge->dropColumn('daily_food_offerings',['snapshot_json','batch_id','portions','closed','leftover','disposition']);
        $this->forge->dropColumn('products','food_portions');
        $this->forge->dropTable('food_daily_batches',true); $this->forge->dropTable('food_template_details',true);
    }
}
