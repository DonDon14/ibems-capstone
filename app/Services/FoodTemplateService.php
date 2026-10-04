<?php
namespace App\Services;
use Config\Database;
use App\Models\ProductModel;

/** Invoked under the parent service's store lock and audit transaction. */
final class FoodTemplateService
{
    public static function text(mixed $value, int $limit, bool $required = false): string
    {
        if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Invalid text field.');
        $value = trim((string) $value);
        if (($required && $value === '') || mb_strlen($value) > $limit) throw new \InvalidArgumentException('Complete required fields and respect their maximum lengths.');
        return $value;
    }

    public function save(array $input, int $storeId): array
    {
        $db = Database::connect();
        $familyId = (int) ($input['family_id'] ?? 0);
        $old = $familyId ? $db->table('food_template_details')->where(['store_id'=>$storeId,'family_id'=>$familyId])->get()->getRowArray() : null;
        if ($familyId && (!$old || (int) ($input['version'] ?? -1) !== (int) $old['version'])) throw new \InvalidArgumentException('Template changed or belongs to another store. Reload before editing.');
        $name = self::text($input['name'] ?? '',150,true);
        $category = self::text($input['category'] ?? 'Daily food',100,true);
        $image = self::text($input['image_url'] ?? '',255);
        $bundledImage = preg_match('~^/assets/demo/[a-z0-9-]+\.svg$~D',$image) && is_file(FCPATH . ltrim($image,'/'));
        if ($image !== '' && !$bundledImage && !preg_match('~^(https://[^\s]+|/uploads/[^\s]+)$~D',$image)) throw new \InvalidArgumentException('Use an uploaded image or a valid HTTPS image URL.');
        if (!$familyId && $db->table('product_families')->where(['store_id'=>$storeId,'name'=>$name])->countAllResults()) throw new \InvalidArgumentException('A dish with this name already exists. Edit its template instead.');
        $tracking = $input['tracking'] ?? 'independent';
        if (!in_array($tracking,['independent','shared'],true)) throw new \InvalidArgumentException('Select a valid quantity tracking method.');
        $variants = $input['variants'] ?? [];
        if (!is_array($variants) || !$variants || count($variants)>30) throw new \InvalidArgumentException('Add between 1 and 30 variants.');
        $existing = $familyId ? $db->table('products')->where(['store_id'=>$storeId,'family_id'=>$familyId])->get()->getResultArray() : [];
        if (!$familyId && !empty($input['legacy_product_id'])) {
            $legacy=$db->table('products')->where(['id'=>(int)$input['legacy_product_id'],'store_id'=>$storeId,'item_type'=>'prepared_item'])->get()->getRowArray();
            if (!$legacy || !empty($legacy['family_id'])) throw new \InvalidArgumentException('This legacy dish is already grouped or unavailable. Reload before editing.');
            $existing=[$legacy];
        }
        $labels = []; $ids = []; $normalized = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) throw new \InvalidArgumentException('Invalid variant.');
            $label = self::text($variant['label'] ?? '',100,true);
            if (isset($labels[mb_strtolower($label)])) throw new \InvalidArgumentException('Variant names must be unique.');
            $labels[mb_strtolower($label)] = true;
            $id = (int) ($variant['id'] ?? 0);
            if ($id && (!in_array($id,array_map('intval',array_column($existing,'id')),true) || isset($ids[$id]))) throw new \InvalidArgumentException('Invalid or repeated variant identity.');
            $ids[$id] = true;
            $unit = (string) ($variant['unit'] ?? 'serving');
            if (!in_array($unit,ProductBehaviorService::UNIT_CODES,true)) throw new \InvalidArgumentException('Invalid selling unit.');
            $price = StoreOfferingService::cents($variant['price'] ?? '');
            if ($price <= 0) throw new \InvalidArgumentException('Variant prices must be greater than zero.');
            $normalized[] = ['id'=>$id,'label'=>$label,'price'=>$price/100,'unit'=>$unit,'portions'=> $tracking === 'shared' ? StoreOfferingService::quantity($variant['portions'] ?? 0) : 1];
        }
        foreach ($existing as $variant) if (!isset($ids[(int)$variant['id']])) throw new \InvalidArgumentException('Keep existing variants to preserve history. Disable their daily availability instead.');
        if ($old && $old['tracking'] !== $tracking && $db->table('daily_food_offerings')->where('store_id',$storeId)->where('business_date',ibems_business_date())->whereIn('product_id',array_column($existing,'id'))->countAllResults()) throw new \InvalidArgumentException('Change tracking before publishing today’s variants, or on a new business day.');
        $family = ['store_id'=>$storeId,'name'=>$name,'category'=>$category,'image_url'=>$image ?: null,'option_name'=>'Serving','updated_at'=>ibems_now()->format('Y-m-d H:i:s')];
        if ($familyId) $db->table('product_families')->where('id',$familyId)->update($family);
        else { $db->table('product_families')->insert($family + ['created_at'=>ibems_now()->format('Y-m-d H:i:s')]); $familyId=(int)$db->insertID(); }
        $details = ['description'=>self::text($input['description'] ?? '',2000),'allergens'=>self::text($input['allergens'] ?? '',500),'dietary'=>self::text($input['dietary'] ?? '',500),'tracking'=>$tracking,'version'=>(int)($old['version'] ?? 0)+1];
        if ($old) $db->table('food_template_details')->where('id',$old['id'])->update($details);
        else $db->table('food_template_details')->insert(['store_id'=>$storeId,'family_id'=>$familyId]+$details);
        $productIds=[];
        foreach ($normalized as $variant) {
            $row=['store_id'=>$storeId,'family_id'=>$familyId,'name'=>$name,'category'=>$category,'image_url'=>$image ?: null,'variant_label'=>$variant['label'],'price'=>$variant['price'],'unit_code'=>$variant['unit'],'food_portions'=>$variant['portions'],'updated_at'=>ibems_now()->format('Y-m-d H:i:s')];
            if ($variant['id']) { $db->table('products')->where('id',$variant['id'])->update($row); $productIds[]=$variant['id']; }
            else { $db->table('products')->insert($row+['sku'=>'FOOD-'.bin2hex(random_bytes(8)),'item_type'=>'prepared_item','stock_policy'=>'untracked','stock_qty'=>0,'low_stock_threshold'=>0,'is_active'=>true]); $productIds[]=(int)$db->insertID(); }
        }
        return ['family_id'=>$familyId,'product_id'=>$productIds[0],'product_ids'=>$productIds];
    }

    public static function decorate(array $product, int $storeId): array
    {
        $db=Database::connect();
        if (!$db->tableExists('food_template_details')) return $product;
        $meta=$db->table('food_template_details')->where(['store_id'=>$storeId,'family_id'=>(int)($product['family_id']??0)])->get()->getRowArray();
        return $product + ['food_details'=>$meta];
    }

    public function publish(array $input, array $product, int $storeId, ?array $old): array
    {
        $db=Database::connect();
        $product=self::decorate($product,$storeId);
        $meta=$product['food_details'];
        if ($old && (int)$old['closed']) throw new \InvalidArgumentException('This daily offering is closed. Start a fresh selection tomorrow.');
        $snapshot=$old['snapshot_json'] ?? json_encode($product);
        $batchId=(int)($old['batch_id'] ?? 0);
        $portions=(int)($old['portions'] ?? $product['food_portions'] ?? 1);
        if (($meta['tracking']??'')==='shared') {
            $batch=$db->table('food_daily_batches')->where(['store_id'=>$storeId,'family_id'=>$product['family_id'],'business_date'=>ibems_business_date()])->get()->getRowArray();
            if (!$batch || $batch['closed']) throw new \InvalidArgumentException('Prepare an open shared batch first.');
            $batchId=(int)$batch['id'];
        }
        return ['snapshot_json'=>$snapshot,'batch_id'=>$batchId,'portions'=>$portions];
    }

    public function batch(array $input,int $storeId): array
    {
        $db=Database::connect(); $familyId=(int)($input['family_id']??0);
        $meta=$db->table('food_template_details')->where(['store_id'=>$storeId,'family_id'=>$familyId,'tracking'=>'shared'])->get()->getRowArray();
        if (!$meta) throw new \InvalidArgumentException('Shared-batch template not found in this store.');
        $where=['store_id'=>$storeId,'family_id'=>$familyId,'business_date'=>ibems_business_date()];
        $old=$db->table('food_daily_batches')->where($where)->get()->getRowArray();
        $capacity=StoreOfferingService::quantity($input['capacity']??0);
        if ($old && ($old['closed'] || $capacity<(int)$old['used'])) throw new \InvalidArgumentException('Batch is closed or quantity is below portions already sold.');
        if ($old) $db->table('food_daily_batches')->where('id',$old['id'])->update(['capacity'=>$capacity]);
        else $db->table('food_daily_batches')->insert($where+['capacity'=>$capacity,'used'=>0,'closed'=>0,'leftover'=>0]);
        return ['family_id'=>$familyId];
    }

    public function close(array $input,int $storeId): array
    {
        $db=Database::connect(); $batch=!empty($input['batch_id']); $table=$batch?'food_daily_batches':'daily_food_offerings';
        $id=(int)($input[$batch?'batch_id':'offering_id']??0);
        $row=$db->table($table)->where(['id'=>$id,'store_id'=>$storeId,'business_date'=>ibems_business_date()])->get()->getRowArray();
        if (!$row || $row['closed'] || (!$batch && $row['batch_id'])) throw new \InvalidArgumentException('Select an open batch or independent offering.');
        $remaining=(int)$row['capacity']-(int)$row[$batch?'used':'sold'];
        if (filter_var($input['leftover']??null,FILTER_VALIDATE_INT)===false || (int)$input['leftover']!==$remaining) throw new \InvalidArgumentException('Leftover must match remaining quantity ('.$remaining.'). Reconcile prepared quantity first.');
        $db->table($table)->where('id',$id)->update(['closed'=>1,'leftover'=>$remaining,'disposition'=>self::text($input['disposition']??'',150,true)]);
        return ['closed_id'=>$id];
    }

    public static function consume(array $food,int $qty): void
    {
        if (!empty($food['closed'])) throw new \InvalidArgumentException('Daily offering is closed.');
        if (empty($food['batch_id'])) return;
        $db=Database::connect();
        $batch=$db->table('food_daily_batches')->where('id',$food['batch_id'])->get()->getRowArray();
        $used=$qty*(int)$food['portions'];
        if (!$batch || $batch['closed'] || (int)$batch['capacity']-(int)$batch['used']<$used) throw new \InvalidArgumentException('Insufficient shared food portions. Refresh today’s menu.');
        $db->table('food_daily_batches')->where('id',$batch['id'])->set('used','used + '.$used,false)->update();
    }
}
