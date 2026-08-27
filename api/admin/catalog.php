<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/analytics.php';
require_once dirname(__DIR__) . '/lib/db.php';

requireAdmin();
header('Cache-Control: no-store, max-age=0');

$pdo = dbConnection();
if (!$pdo) jsonResponse(['code'=>-1,'message'=>'Database unavailable'], 503);

function catalogSnapshot(PDO $pdo): array
{
    $products = $pdo->query(
        'SELECT product_id,personality_key,name,price_fen,regular_price_fen,active,updated_at
         FROM products ORDER BY product_id'
    )->fetchAll();
    $skuRows = $pdo->query(
        'SELECT sku_id,product_id,color,size,stock_on_hand,stock_reserved,active,updated_at
         FROM skus ORDER BY product_id,FIELD(color,"黑","白","灰"),FIELD(size,"XS","S","M","L","XL","XXL","3XL","4XL")'
    )->fetchAll();
    $byProduct = [];
    foreach ($skuRows as $sku) $byProduct[(string)$sku['product_id']][] = $sku;
    foreach ($products as &$product) {
        $product['price_fen'] = (int)$product['price_fen'];
        $product['regular_price_fen'] = (int)$product['regular_price_fen'];
        $product['active'] = (int)$product['active'];
        $product['skus'] = array_map(static function (array $sku): array {
            $sku['stock_on_hand'] = (int)$sku['stock_on_hand'];
            $sku['stock_reserved'] = (int)$sku['stock_reserved'];
            $sku['available'] = max(0, $sku['stock_on_hand'] - $sku['stock_reserved']);
            $sku['active'] = (int)$sku['active'];
            return $sku;
        }, $byProduct[(string)$product['product_id']] ?? []);
    }
    unset($product);
    return $products;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') jsonResponse(['code'=>0,'products'=>catalogSnapshot($pdo)]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['code'=>-1,'message'=>'Method Not Allowed'], 405);

$body = readJsonBody();
$action = trim((string)($body['action'] ?? ''));

try {
    if ($action === 'save_product') {
        $productId = trim((string)($body['product_id'] ?? ''));
        $priceFen = (int)($body['price_fen'] ?? 0);
        $regularPriceFen = (int)($body['regular_price_fen'] ?? 0);
        $active = !empty($body['active']) ? 1 : 0;
        if (!preg_match('/^tee-0[1-8]$/', $productId)) throw new InvalidArgumentException('商品编号无效');
        if ($priceFen < 1 || $priceFen > 10000000 || $regularPriceFen < $priceFen) throw new InvalidArgumentException('商品价格无效');
        $pdo->prepare('UPDATE products SET price_fen=?,regular_price_fen=?,active=? WHERE product_id=?')
            ->execute([$priceFen,$regularPriceFen,$active,$productId]);
    } elseif ($action === 'save_sku') {
        $skuId = trim((string)($body['sku_id'] ?? ''));
        $stock = (int)($body['stock_on_hand'] ?? -1);
        $active = !empty($body['active']) ? 1 : 0;
        if ($skuId === '' || $stock < 0 || $stock > 999999) throw new InvalidArgumentException('SKU 或库存无效');
        $statement = $pdo->prepare('SELECT stock_reserved FROM skus WHERE sku_id=?');
        $statement->execute([$skuId]);
        $reserved = $statement->fetchColumn();
        if ($reserved === false) throw new InvalidArgumentException('SKU 不存在');
        if ($stock < (int)$reserved) throw new InvalidArgumentException('库存不能小于已预占数量');
        $pdo->prepare('UPDATE skus SET stock_on_hand=?,active=? WHERE sku_id=?')->execute([$stock,$active,$skuId]);
    } elseif ($action === 'bulk_stock') {
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) < 1 || count($items) > 500) throw new InvalidArgumentException('批量库存数据无效');
        $pdo->beginTransaction();
        $check = $pdo->prepare('SELECT stock_reserved FROM skus WHERE sku_id=? FOR UPDATE');
        $save = $pdo->prepare('UPDATE skus SET stock_on_hand=?,active=? WHERE sku_id=?');
        foreach ($items as $item) {
            $skuId = trim((string)($item['sku_id'] ?? ''));
            $stock = (int)($item['stock_on_hand'] ?? -1);
            $active = !empty($item['active']) ? 1 : 0;
            if ($skuId === '' || $stock < 0 || $stock > 999999) throw new InvalidArgumentException('批量数据包含无效 SKU');
            $check->execute([$skuId]);
            $reserved = $check->fetchColumn();
            if ($reserved === false || $stock < (int)$reserved) throw new InvalidArgumentException('SKU 不存在或库存小于预占数量：'.$skuId);
            $save->execute([$stock,$active,$skuId]);
        }
        $pdo->commit();
    } else {
        throw new InvalidArgumentException('Unsupported action');
    }
    jsonResponse(['code'=>0,'products'=>catalogSnapshot($pdo)]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['code'=>-1,'message'=>$error->getMessage()], 400);
}
