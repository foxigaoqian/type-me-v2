-- Customer inventory received 2026-08-25.
-- Apply only before sales are enabled and when no SKU has stock_reserved > 0.
START TRANSACTION;

CREATE TEMPORARY TABLE actual_inventory (
  product_id VARCHAR(32) NOT NULL,
  color VARCHAR(16) NOT NULL,
  size VARCHAR(16) NOT NULL,
  stock_on_hand INT UNSIGNED NOT NULL,
  PRIMARY KEY (product_id, color, size)
);

INSERT INTO actual_inventory (product_id, color, size, stock_on_hand) VALUES
('tee-01','白','XS',2),('tee-01','白','S',3),('tee-01','白','M',3),('tee-01','白','L',3),('tee-01','白','XL',3),('tee-01','白','XXL',2),('tee-01','白','3XL',2),
('tee-02','黑','XS',2),('tee-02','黑','S',3),('tee-02','黑','M',3),('tee-02','黑','L',3),('tee-02','黑','XL',3),('tee-02','黑','XXL',2),('tee-02','黑','3XL',2),
('tee-03','白','XS',5),('tee-03','白','S',10),('tee-03','白','M',10),('tee-03','白','L',10),('tee-03','白','XL',10),('tee-03','白','XXL',5),('tee-03','白','3XL',5),
('tee-04','黑','XS',5),('tee-04','黑','S',10),('tee-04','黑','M',10),('tee-04','黑','L',10),('tee-04','黑','XL',10),('tee-04','黑','XXL',5),('tee-04','黑','3XL',5),
('tee-05','灰','XS',5),('tee-05','灰','S',10),('tee-05','灰','M',10),('tee-05','灰','L',10),('tee-05','灰','XL',10),('tee-05','灰','XXL',5),('tee-05','灰','3XL',5),
('tee-06','灰','XS',2),('tee-06','灰','S',3),('tee-06','灰','M',3),('tee-06','灰','L',5),('tee-06','灰','XL',5),('tee-06','灰','XXL',3),('tee-06','灰','3XL',2),
('tee-07','白','XS',2),('tee-07','白','S',3),('tee-07','白','M',3),('tee-07','白','L',5),('tee-07','白','XL',5),('tee-07','白','XXL',3),('tee-07','白','3XL',2),
('tee-08','黑','XS',5),('tee-08','黑','S',5),('tee-08','黑','M',10),('tee-08','黑','L',10),('tee-08','黑','XL',10),('tee-08','黑','XXL',10),('tee-08','黑','3XL',5);

UPDATE skus s
LEFT JOIN actual_inventory a
  ON a.product_id = s.product_id
 AND a.color = s.color
 AND a.size = s.size
SET s.stock_on_hand = COALESCE(a.stock_on_hand, 0),
    s.active = IF(a.product_id IS NULL, 0, 1);

DROP TEMPORARY TABLE actual_inventory;
COMMIT;
