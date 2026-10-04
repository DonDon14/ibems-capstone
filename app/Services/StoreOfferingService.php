<?php
namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\ProductModel;
use App\Models\StoreCapabilityModel;
use App\Models\StoreDaySessionModel;
use Config\Database;

/** Store locks serialize daily limits, bookings, returns and checkout. Money is stored in cents. */
final class StoreOfferingService
{
    public static function cents(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string) $value)) throw new \InvalidArgumentException('Enter a non-negative amount with at most two decimals.');
        return (int) round((float) $value * 100);
    }

    public static function quantity(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 100000) throw new \InvalidArgumentException('Quantity must be a whole number from 1 to 100000.');
        return (int) $value;
    }

    public static function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Enter a valid date.');
        return $value;
    }

    private function open(int $storeId): void
    {
        if (((new StoreDaySessionModel())->getToday($storeId)['status'] ?? '') !== 'open') throw new \InvalidArgumentException('Open today’s store session in POS first.');
    }

    private function capability(int $storeId, string $capability): void
    {
        if (!in_array($capability, (new StoreCapabilityModel())->getForStore($storeId), true)) throw new \InvalidArgumentException('Enable ' . str_replace('_', ' ', $capability) . ' for this store first.');
    }

    public function data(int $storeId): array
    {
        $db = Database::connect();
        if (!$db->tableExists('rental_bookings')) throw new \RuntimeException('Apply the rental and daily food migration first.');
        return ['status' => 'success', 'store_id' => $storeId, 'business_date' => ibems_business_date(),
            'capabilities' => (new StoreCapabilityModel())->getForStore($storeId),
            'food' => array_map(static fn($product)=>FoodTemplateService::decorate($product,$storeId), $db->table('products')->where('store_id', $storeId)->where('item_type', 'prepared_item')->where('is_active', true)->get()->getResultArray()),
            'batches' => $db->tableExists('food_daily_batches') ? $db->table('food_daily_batches')->where(['store_id'=>$storeId,'business_date'=>ibems_business_date()])->get()->getResultArray() : [],
            'offerings' => $db->table('daily_food_offerings')->where('store_id', $storeId)->where('business_date', ibems_business_date())->get()->getResultArray(),
            'assets' => $db->table('rental_assets')->where('store_id', $storeId)->get()->getResultArray(),
            'packages' => $db->table('rental_packages')->where('store_id', $storeId)->get()->getResultArray(),
            'bookings' => $db->table('rental_bookings')->where('store_id', $storeId)->groupStart()->whereIn('status', ['reserved', 'issued'])->orWhere('collected_cents > refunded_cents', null, false)->groupEnd()->orderBy('id', 'DESC')->get()->getResultArray()];
    }

    public function mutate(string $action, array $input, int $storeId, int $actorId, string $role): array
    {
        $db = Database::connect();
        if (!$db->tableExists('rental_bookings')) throw new \RuntimeException('Apply the rental and daily food migration first.');
        if (in_array($action, ['asset', 'package'], true) && $role !== 'STORE_SUPERVISOR') throw new \InvalidArgumentException('Only the supervisor can configure rental equipment and packages.');
        $db->transBegin();
        try {
            ProductBehaviorService::lockStore($db, $storeId);
            // Returns/refunds must remain possible after rental is disabled.
            if (!in_array($action, ['return', 'refund', 'cancel'], true)) $this->capability($storeId, in_array($action, ['template', 'food', 'batch', 'food-close'], true) ? 'food_service' : 'rental');
            if (in_array($action,['batch','food-close'],true)) $this->open($storeId);
            $result = match ($action) {
                'template' => $this->template($input, $storeId),
                'food' => $this->food($input, $storeId),
                'batch' => (new FoodTemplateService())->batch($input,$storeId),
                'food-close' => (new FoodTemplateService())->close($input,$storeId),
                'asset' => $this->asset($input, $storeId),
                'package' => $this->package($input, $storeId),
                'book' => $this->book($input, $storeId),
                'issue', 'return', 'refund', 'cancel' => $this->transition($action, $input, $storeId, $actorId),
                default => throw new \InvalidArgumentException('Unknown operation.'),
            };
            (new AuditLogModel())->insert(['actor_id' => $actorId, 'action' => 'STORE_OFFERING_' . strtoupper($action), 'entity' => 'stores', 'entity_id' => $storeId,
                'payload_json' => json_encode(['input' => $input, 'result' => $result]), 'created_at' => ibems_now()->format('Y-m-d H:i:s')]);
            if (!$db->transStatus()) throw new \RuntimeException('Operation failed; no changes were saved.');
            $db->transCommit();
            return ['status' => 'success'] + $result;
        } catch (\Throwable $e) { $db->transRollback(); throw $e; }
    }

    private function name(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Name is required (maximum 150 characters).');
        return $name;
    }

    private function template(array $input, int $storeId): array
    {
        if (isset($input['variants'])) {
            $publish = ($input['publish_today'] ?? false) === true;
            if ($publish) $this->open($storeId);
            $template = new FoodTemplateService();
            $result = $template->save($input,$storeId);
            if ($publish) {
                $capacity = self::quantity($input['today_capacity'] ?? 0);
                if (($input['tracking'] ?? 'independent') === 'shared') {
                    $template->batch(['family_id'=>$result['family_id'],'capacity'=>$capacity],$storeId);
                }
                foreach ($result['product_ids'] as $productId) {
                    $product = (new ProductModel())->find($productId);
                    $this->food(['product_id'=>$productId,'enabled'=>true,'capacity'=>$capacity,'price'=>(string)$product['price']],$storeId);
                }
                $result['published_product_ids'] = $result['product_ids'];
            }
            return $result;
        }
        $name = $this->name($input);
        $db = Database::connect();
        if ($db->table('products')->where('store_id', $storeId)->where('item_type', 'prepared_item')->where('name', $name)->countAllResults()) throw new \InvalidArgumentException('This food template already exists. Select it for today.');
        $id = (new ProductModel())->insert(['store_id' => $storeId, 'sku' => 'FOOD-' . bin2hex(random_bytes(8)), 'name' => $name, 'category' => 'Daily food',
            'price' => self::cents($input['price'] ?? '') / 100, 'stock_qty' => 0, 'low_stock_threshold' => 0, 'item_type' => 'prepared_item',
            'stock_policy' => 'untracked', 'unit_code' => 'serving', 'is_active' => true, 'updated_at' => ibems_now()->format('Y-m-d H:i:s')]);
        return ['product_id' => (int) $id];
    }

    private function food(array $input, int $storeId): array
    {
        $this->open($storeId);
        $db = Database::connect();
        $id = self::quantity($input['product_id'] ?? 0);
        $product = (new ProductModel())->find($id);
        if (!$product || (int) $product['store_id'] !== $storeId || $product['item_type'] !== 'prepared_item' || !ibems_bool($product['is_active'])) throw new \InvalidArgumentException('Food template not found in this store.');
        $where = ['store_id' => $storeId, 'product_id' => $id, 'business_date' => ibems_business_date()];
        $old = $db->table('daily_food_offerings')->where($where)->get()->getRowArray();
        $capacity = self::quantity($input['capacity'] ?? 0);
        if ($capacity < (int) ($old['sold'] ?? 0)) throw new \InvalidArgumentException('Daily quantity cannot be lower than servings already sold.');
        $row = ['enabled' => !empty($input['enabled']) ? 1 : 0, 'capacity' => $capacity, 'price_cents' => self::cents($input['price'] ?? '')];
        if ($db->tableExists('food_template_details')) $row += (new FoodTemplateService())->publish($input,$product,$storeId,$old);
        if ($old) $db->table('daily_food_offerings')->where($where)->update($row);
        else $db->table('daily_food_offerings')->insert($where + $row + ['sold' => 0]);
        return ['product_id' => $id];
    }

    private function asset(array $input, int $storeId): array
    {
        $db = Database::connect();
        $db->table('rental_assets')->insert(['store_id' => $storeId, 'name' => $this->name($input), 'quantity' => self::quantity($input['quantity'] ?? 0)]);
        return ['asset_id' => (int) $db->insertID()];
    }

    private function components(mixed $input, int $storeId): array
    {
        if (!is_array($input) || !$input) throw new \InvalidArgumentException('Select at least one equipment component.');
        $result = [];
        foreach ($input as $id => $qty) {
            $id = self::quantity($id);
            $asset = Database::connect()->table('rental_assets')->where(['id' => $id, 'store_id' => $storeId])->get()->getRowArray();
            if (!$asset) throw new \InvalidArgumentException('Equipment does not belong to this store.');
            $result[$id] = self::quantity($qty);
        }
        return $result;
    }

    private function package(array $input, int $storeId): array
    {
        $db = Database::connect();
        if (self::cents($input['fee'] ?? '') <= 0) throw new \InvalidArgumentException('Rental fee must be greater than zero. Complimentary lending is not a paid rental.');
        $db->table('rental_packages')->insert(['store_id' => $storeId, 'name' => $this->name($input),
            'components_json' => json_encode($this->components($input['components'] ?? [], $storeId)),
            'fee_cents' => self::cents($input['fee'] ?? ''), 'deposit_cents' => self::cents($input['deposit'] ?? '0')]);
        return ['package_id' => (int) $db->insertID()];
    }

    /** Inclusive dates. A still-issued item remains unavailable until physically returned. */
    public static function peakReserved(array $bookings, int $assetId, string $start, string $end): int
    {
        $events = [];
        foreach ($bookings as $booking) {
            if (!in_array($booking['status'], ['reserved', 'issued'], true)) continue;
            $components = json_decode($booking['components_json'], true);
            $returned = json_decode($booking['returned_json'], true);
            $qty = (int) ($components[$assetId] ?? 0) - (int) ($returned[$assetId] ?? 0);
            $from = max($start, $booking['starts_on']);
            $to = $booking['status'] === 'issued' ? $end : min($end, $booking['due_on']);
            if ($qty <= 0 || $from > $to) continue;
            $after = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $events[$from] = ($events[$from] ?? 0) + $qty;
            $events[$after] = ($events[$after] ?? 0) - $qty;
        }
        ksort($events);
        $running = 0; $peak = 0;
        foreach ($events as $change) { $running += $change; $peak = max($peak, $running); }
        return $peak;
    }

    private function available(int $storeId, array $components, string $start, string $end, int $exclude = 0): void
    {
        $db = Database::connect();
        $bookings = $db->table('rental_bookings')->where('store_id', $storeId)->where('id !=', $exclude)->whereIn('status', ['reserved', 'issued'])->get()->getResultArray();
        foreach ($components as $assetId => $qty) {
            $asset = $db->table('rental_assets')->where(['id' => $assetId, 'store_id' => $storeId])->get()->getRowArray();
            if (!$asset || self::peakReserved($bookings, (int) $assetId, $start, $end) + $qty > (int) $asset['quantity']) throw new \InvalidArgumentException('Insufficient available equipment: ' . ($asset['name'] ?? $assetId) . '. Check overlapping bookings and overdue returns.');
        }
    }

    private function book(array $input, int $storeId): array
    {
        $db = Database::connect();
        $key = (string) ($input['request_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D', $key)) throw new \InvalidArgumentException('A unique booking request key is required.');
        $existing = $db->table('rental_bookings')->where(['store_id' => $storeId, 'request_key' => $key])->get()->getRowArray();
        if ($existing) return ['booking_id' => (int) $existing['id']];
        $package = $db->table('rental_packages')->where(['store_id' => $storeId, 'id' => (int) ($input['package_id'] ?? 0)])->get()->getRowArray();
        if (!$package) throw new \InvalidArgumentException('Select a rental package from this store.');
        $start = self::date((string) ($input['starts_on'] ?? ''));
        $end = self::date((string) ($input['due_on'] ?? ''));
        if ($start < ibems_business_date() || $end < $start || $end > (new \DateTimeImmutable($start))->modify('+1 year')->format('Y-m-d')) throw new \InvalidArgumentException('Rental dates must start today or later and end within one year.');
        $qty = self::quantity($input['quantity'] ?? 0);
        $components = json_decode($package['components_json'], true);
        foreach ($components as &$count) $count *= $qty;
        unset($count);
        $this->available($storeId, $components, $start, $end);
        $borrower = $this->name(['name' => $input['borrower'] ?? '']);
        $contact = trim((string) ($input['contact'] ?? ''));
        if ($contact === '' || mb_strlen($contact) > 150) throw new \InvalidArgumentException('Borrower contact/reference is required (up to 150 characters).');
        $fee = (int) $package['fee_cents'] * $qty;
        $deposit = (int) $package['deposit_cents'] * $qty;
        if ($fee > 999999999 || $deposit > 999999999) throw new \InvalidArgumentException('Booking total is too large.');
        $productId = (new ProductModel())->insert(['store_id' => $storeId, 'sku' => 'RENT-' . bin2hex(random_bytes(8)), 'name' => mb_substr($package['name'] . ' × ' . $qty . ' (' . $start . '–' . $end . ')', 0, 150),
            'category' => 'Rental bookings', 'price' => $fee / 100, 'stock_qty' => 0, 'low_stock_threshold' => 0,
            'item_type' => 'rental', 'stock_policy' => 'untracked', 'unit_code' => 'service', 'is_active' => true, 'updated_at' => ibems_now()->format('Y-m-d H:i:s')]);
        $db->table('rental_bookings')->insert(['store_id' => $storeId, 'package_id' => $package['id'], 'product_id' => $productId, 'request_key' => $key,
            'borrower' => $borrower, 'contact' => $contact, 'starts_on' => $start, 'due_on' => $end, 'status' => 'reserved',
            'components_json' => json_encode($components), 'returned_json' => '{}', 'fee_cents' => $fee, 'deposit_cents' => $deposit,
            'transaction_id' => 0, 'collected_cents' => 0, 'refunded_cents' => 0]);
        return ['booking_id' => (int) $db->insertID()];
    }

    private function transition(string $action, array $input, int $storeId, int $actorId): array
    {
        $db = Database::connect();
        $id = self::quantity($input['booking_id'] ?? 0);
        $booking = $db->table('rental_bookings')->where(['id' => $id, 'store_id' => $storeId])->get()->getRowArray();
        if (!$booking) throw new \InvalidArgumentException('Booking not found in this store.');
        if (!isset($input['version']) || (int) $input['version'] !== (int) $booking['version']) throw new \InvalidArgumentException('This booking changed. Refresh and check the latest status before retrying.');
        $changes = [];
        $components = json_decode($booking['components_json'], true);
        $returned = json_decode($booking['returned_json'], true);
        if ($action === 'cancel') {
            if ($booking['status'] !== 'reserved' || (int) $booking['transaction_id'] > 0) throw new \InvalidArgumentException('Only unpaid reservations can be cancelled here. Paid bookings require a reviewed fee-refund process.');
            $changes['status'] = 'cancelled';
        } elseif ($action === 'issue') {
            $this->open($storeId);
            if ($booking['status'] !== 'reserved' || !(int) $booking['transaction_id']) throw new \InvalidArgumentException('Pay the rental fee before issuing equipment.');
            if ($booking['starts_on'] > ibems_business_date() || $booking['due_on'] < ibems_business_date()) throw new \InvalidArgumentException('Equipment can only be issued within the booked dates.');
            $this->available($storeId, $components, ibems_business_date(), $booking['due_on'], $id);
            $changes = ['status' => 'issued', 'collected_cents' => (int) $booking['deposit_cents']];
            $this->cash($storeId, $actorId, $id, 'in', (int) $booking['deposit_cents']);
        } elseif ($action === 'return') {
            if ($booking['status'] !== 'issued') throw new \InvalidArgumentException('Only issued equipment can be returned.');
            $items = $input['components'] ?? [];
            if (!is_array($items) || !$items) throw new \InvalidArgumentException('Enter the quantities physically returned in reusable condition.');
            foreach ($items as $assetId => $qty) {
                $qty = self::quantity($qty);
                if (!isset($components[$assetId]) || $qty + (int) ($returned[$assetId] ?? 0) > $components[$assetId]) throw new \InvalidArgumentException('Returned quantity exceeds outstanding equipment.');
                $returned[$assetId] = (int) ($returned[$assetId] ?? 0) + $qty;
            }
            $complete = true;
            foreach ($components as $assetId => $qty) if ((int) ($returned[$assetId] ?? 0) !== $qty) $complete = false;
            $changes = ['returned_json' => json_encode($returned), 'status' => $complete ? 'returned' : 'issued'];
        } elseif ($action === 'refund') {
            $this->open($storeId);
            if ($booking['status'] !== 'returned') throw new \InvalidArgumentException('Return all reusable equipment before refunding the deposit.');
            $amount = (int) $booking['collected_cents'] - (int) $booking['refunded_cents'];
            if ($amount <= 0) throw new \InvalidArgumentException('No deposit remains to refund.');
            $this->cash($storeId, $actorId, $id, 'out', $amount);
            $changes['refunded_cents'] = (int) $booking['collected_cents'];
        }
        $changes['version'] = (int) $booking['version'] + 1;
        $db->table('rental_bookings')->where('id', $id)->update($changes);
        return ['booking_id' => $id];
    }

    private function cash(int $storeId, int $actorId, int $bookingId, string $direction, int $cents): void
    {
        if ($cents === 0) return;
        (new \App\Models\StoreCashMovementModel())->insert(['store_id' => $storeId, 'business_date' => ibems_business_date(), 'channel' => 'cash',
            'movement_type' => 'cash_' . $direction, 'amount' => $cents / 100, 'reason' => 'Refundable rental deposit #' . $bookingId . ($direction === 'in' ? ' collected' : ' refunded'),
            'created_by' => $actorId, 'created_at' => ibems_now()->format('Y-m-d H:i:s'), 'updated_at' => ibems_now()->format('Y-m-d H:i:s')]);
    }

    public static function foodForSale(int $storeId, int $productId): ?array
    {
        $db = Database::connect();
        if (!$db->tableExists('daily_food_offerings')) return null;
        $food = $db->table('daily_food_offerings')->where(['store_id' => $storeId, 'product_id' => $productId, 'business_date' => ibems_business_date(), 'enabled' => 1])->get()->getRowArray();
        if (!$food || !empty($food['closed'])) return null;
        if (!empty($food['batch_id'])) {
            $batch=$db->table('food_daily_batches')->where('id',$food['batch_id'])->get()->getRowArray();
            if (!$batch || $batch['closed']) return null;
            $food['capacity']=min((int)$food['capacity'], (int)$food['sold']+intdiv(max(0,(int)$batch['capacity']-(int)$batch['used']),max(1,(int)$food['portions'])));
        }
        return $food;
    }

    /** Called inside TransactionService's store-locked transaction, before any payment writes. */
    public static function checkout(array $request, int $storeId, array $snapshots): void
    {
        $db = Database::connect();
        if (!empty($request['rental_booking_id']) && (count($snapshots) !== 1 || (reset($snapshots)['product']['item_type'] ?? '') !== 'rental')) throw new \InvalidArgumentException('A rental payment must contain only its booking fee.');
        foreach ($snapshots as $productId => $snapshot) {
            $product = $snapshot['product'];
            if (($product['item_type'] ?? '') === 'prepared_item' && $db->tableExists('daily_food_offerings')) {
                $food = self::foodForSale($storeId, (int) $productId);
                if (!$food || (int) $food['capacity'] - (int) $food['sold'] < $snapshot['qty'] || (int) $food['price_cents'] !== (int) round($snapshot['unit_price'] * 100)) throw new \InvalidArgumentException('Today’s food availability or price changed. Refresh POS before charging.');
                FoodTemplateService::consume($food,(int)$snapshot['qty']);
                $db->table('daily_food_offerings')->where('id', $food['id'])->set('sold', 'sold + ' . (int) $snapshot['qty'], false)->update();
            }
            if (($product['item_type'] ?? '') === 'rental') {
                $booking = $db->table('rental_bookings')->where(['store_id' => $storeId, 'id' => (int) ($request['rental_booking_id'] ?? 0), 'product_id' => $productId])->get()->getRowArray();
                if (!$booking || $booking['status'] !== 'reserved' || (int) $booking['transaction_id'] || $snapshot['qty'] !== 1 || count($snapshots) !== 1 || (int) $booking['fee_cents'] !== (int) round($snapshot['unit_price'] * 100)) throw new \InvalidArgumentException('Use the rental booking checkout to pay the exact fee once.');
                if ($booking['due_on'] < ibems_business_date()) throw new \InvalidArgumentException('This reservation has expired. Cancel it and create a new booking.');
            }
        }
    }
}
