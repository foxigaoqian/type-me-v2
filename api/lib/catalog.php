<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function catalogResolvePath(string $path): string
{
    if ($path === '') return '';
    if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) return $path;
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

function catalogPaymentReady(): bool
{
    $config = getConfig();
    foreach (['mchid', 'appid', 'api_v3_key', 'serial_no', 'notify_url'] as $key) {
        if (trim((string)($config[$key] ?? '')) === '') return false;
    }
    if (!is_file((string)($config['private_key_path'] ?? ''))) return false;

    $publicKeyReady = trim((string)($config['wechat_pay_public_key_id'] ?? '')) !== ''
        && is_file(catalogResolvePath((string)($config['wechat_pay_public_key_path'] ?? '')));
    $platformCertReady = is_file(catalogResolvePath((string)($config['platform_cert_path'] ?? '')));
    return $publicKeyReady || $platformCertReady;
}

function catalogPublicSnapshot(): array
{
    $pdo = dbConnection();
    if (!$pdo) return ['sales_enabled'=>false, 'sales_status'=>'商品暂未开放购买', 'products'=>[]];

    $products = $pdo->query(
        'SELECT product_id,personality_key,name,price_fen,regular_price_fen,active
         FROM products ORDER BY product_id'
    )->fetchAll();
    $skus = $pdo->query(
        'SELECT sku_id,product_id,color,size,stock_on_hand,stock_reserved,active
         FROM skus ORDER BY product_id,FIELD(color,"黑","白","灰"),FIELD(size,"XS","S","M","L","XL","XXL","3XL","4XL")'
    )->fetchAll();

    $skusByProduct = [];
    foreach ($skus as $sku) $skusByProduct[(string)$sku['product_id']][] = $sku;
    $paymentReady = catalogPaymentReady();
    $result = [];
    $hasPurchasableProduct = false;

    foreach ($products as $product) {
        $stockTotal = 0;
        $stockBySize = [];
        $inventory = [];
        foreach ($skusByProduct[(string)$product['product_id']] ?? [] as $sku) {
            if (!(int)$sku['active']) continue;
            $available = max(0, (int)$sku['stock_on_hand'] - (int)$sku['stock_reserved']);
            if ($available < 1) continue;
            $color = (string)$sku['color'];
            $size = (string)$sku['size'];
            $inventory[$color][$size] = $available;
            $stockBySize[$size] = ($stockBySize[$size] ?? 0) + $available;
            $stockTotal += $available;
        }
        $active = (bool)$product['active'];
        $purchasable = $paymentReady && $active && $stockTotal > 0;
        if ($purchasable) $hasPurchasableProduct = true;
        $result[(string)$product['personality_key']] = [
            'product_id'=>(string)$product['product_id'],
            'name'=>(string)$product['name'],
            'price_fen'=>(int)$product['price_fen'],
            'regular_price_fen'=>(int)$product['regular_price_fen'],
            'active'=>$active,
            'purchasable'=>$purchasable,
            'stock_total'=>$stockTotal,
            'stock_by_size'=>$stockBySize,
            'inventory'=>$inventory,
            'available_colors'=>array_keys($inventory),
            'sales_status'=>$purchasable ? '' : ($stockTotal < 1 ? '该款暂时缺货' : '商品暂未开放购买'),
        ];
    }

    return [
        'sales_enabled'=>$paymentReady && $hasPurchasableProduct,
        'sales_status'=>$paymentReady ? ($hasPurchasableProduct ? '' : '商品暂时缺货') : '商品暂未开放购买',
        'products'=>$result,
    ];
}

function catalogResolveOrderItem(string $personality, string $color, string $size): array
{
    $pdo = dbConnection();
    if (!$pdo) throw new RuntimeException('商品数据库暂不可用');
    $statement = $pdo->prepare(
        'SELECT p.product_id,p.name,p.price_fen,s.sku_id,s.stock_on_hand,s.stock_reserved
         FROM products p JOIN skus s ON s.product_id=p.product_id
         WHERE p.personality_key=? AND s.color=? AND s.size=? AND p.active=1 AND s.active=1
         LIMIT 1'
    );
    $statement->execute([$personality, $color, $size]);
    $row = $statement->fetch();
    if (!$row) throw new InvalidArgumentException('该颜色或尺码已下架');
    if ((int)$row['stock_on_hand'] - (int)$row['stock_reserved'] < 1) {
        throw new RuntimeException('所选颜色或尺码库存不足');
    }
    return $row;
}
