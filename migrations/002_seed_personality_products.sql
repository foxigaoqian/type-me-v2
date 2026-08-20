-- Product catalog is real V2 config; stock intentionally starts at 0.
-- Set actual stock counts before enabling DB_DSN in production.

INSERT INTO products (product_id, personality_key, name, price_fen, regular_price_fen, active) VALUES
('tee-01','periodic','TYPE 01 · 间歇卷王人格 T 恤',12900,13900,1),
('tee-02','suiyuan','TYPE 02 · 随缘体人格 T 恤',12900,13900,1),
('tee-03','zuiying','TYPE 03 · 嘴硬体人格 T 恤',12900,13900,1),
('tee-04','night','TYPE 04 · 夜行体人格 T 恤',12900,13900,1),
('tee-05','boundary','TYPE 05 · 边界守卫者人格 T 恤',12900,13900,1),
('tee-06','rebellious','TYPE 06 · 反骨体人格 T 恤',12900,13900,1),
('tee-07','crazy','TYPE 07 · 发疯体人格 T 恤',12900,13900,1),
('tee-08','awake','TYPE 08 · 清醒体人格 T 恤',12900,13900,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),price_fen=VALUES(price_fen),regular_price_fen=VALUES(regular_price_fen),active=VALUES(active);

INSERT INTO skus (sku_id, product_id, color, size, stock_on_hand, stock_reserved, active)
SELECT CONCAT(p.product_id,'-',c.color,'-',s.size), p.product_id, c.color, s.size, 0, 0, 1
FROM products p
CROSS JOIN (SELECT '黑' color UNION ALL SELECT '白' UNION ALL SELECT '灰') c
CROSS JOIN (SELECT 'S' size UNION ALL SELECT 'M' UNION ALL SELECT 'L' UNION ALL SELECT 'XL') s
WHERE p.product_id LIKE 'tee-%'
ON DUPLICATE KEY UPDATE active=VALUES(active);
