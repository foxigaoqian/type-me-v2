-- 首次迁移现有 8 种人格内容。重复执行不会覆盖后台已保存或已发布的内容。

INSERT IGNORE INTO personality_types (type_id, personality_key, name, active) VALUES
('TYPE01','periodic','间歇卷王',1),
('TYPE02','suiyuan','随缘体',1),
('TYPE03','zuiying','嘴硬体',1),
('TYPE04','night','夜行体',1),
('TYPE05','boundary','边界守卫者',1),
('TYPE06','rebellious','反骨体',1),
('TYPE07','crazy','发疯体',1),
('TYPE08','awake','清醒体',1);

INSERT INTO personality_content_revisions
  (type_id,version,status,name,main_meme,identity_card_meme,friend_meme,tshirt_copy,share_copy,content_json,created_by,updated_by,published_at)
SELECT seed.type_id,1,'PUBLISHED',seed.name,seed.main_meme,seed.main_meme,seed.main_meme,seed.main_meme,seed.main_meme,
       seed.content_json,'migration','migration',CURRENT_TIMESTAMP(3)
FROM (
  SELECT 'TYPE01' type_id,'间歇卷王' name,'努力具有明显的周期性。' main_meme,
    JSON_OBJECT('en','PERIODIC ACHIEVER','description','你不是不努力。你只是擅长在“我要改变人生”和“算了明天再说”之间反复横跳。你可以突然爆发出惊人的执行力，然后迅速进入休眠。','metrics',JSON_ARRAY('冲刺能力','持续努力','Deadline 爆发','战略性休息'),'metric_bias',JSON_ARRAY(12,-8,15,4),'skill','能在最后期限逼近时突然进入高性能模式。','weakness','计划做得像人生重启，执行常常等到最后一刻。','accent','#f3ff38') content_json
  UNION ALL SELECT 'TYPE02','随缘体','尽人事，剩下爱咋咋地。',
    JSON_OBJECT('en','LET IT HAPPEN','description','你不是没有目标。你只是比很多人更早理解：有些事情控制不了。努力可以。内耗就算了。','metrics',JSON_ARRAY('随遇而安','抗内耗','临场发挥','计划依赖'),'metric_bias',JSON_ARRAY(12,15,7,-10),'skill','计划临时变化时，依然能快速进入“那就这样吧”模式。','weakness','有时把真正该处理的问题也一起交给了缘分。','accent','#b5fffb')
  UNION ALL SELECT 'TYPE03','嘴硬体','“没事。”通常意味着事情很大。',
    JSON_OBJECT('en','HARD MOUTH / SOFT HEART','description','你不是不在乎。你只是觉得主动表达情绪是一件非常没有面子的事情。别人问：“你是不是生气了？”你：“没有啊。”然后记很久。','metrics',JSON_ARRAY('嘴硬指数','心软指数','主动认错','深夜复盘'),'metric_bias',JSON_ARRAY(16,10,-18,14),'skill','能用一句“没事”把复杂情绪压缩成两个字。','weakness','别人一句“我们聊聊”，就足够触发脑内连续剧。','accent','#ff8fb1')
  UNION ALL SELECT 'TYPE04','夜行体','00:00 以后才真正属于自己。',
    JSON_OBJECT('en','NIGHT CREATURE','description','白天：上课、消息、任务、社交。晚上：终于没人管你。所以每次告诉自己：“今晚早点睡。”最后都会变成：“再刷五分钟。”','metrics',JSON_ARRAY('熬夜指数','深夜活跃','白天电量','凌晨思考'),'metric_bias',JSON_ARRAY(15,13,-12,10),'skill','零点以后自动解锁第二套精神系统。','weakness','第二天的自己经常要替昨晚的自己买单。','accent','#a7a6ff')
  UNION ALL SELECT 'TYPE05','边界守卫者','我不是讨厌人。我只是喜欢人离我远一点。',
    JSON_OBJECT('en','BOUNDARY KEEPER','description','你需要空间。不是冷漠。只是社交会消耗电量。耳机有时候不是为了听歌。是为了告诉世界：暂时不要和我说话。','metrics',JSON_ARRAY('边界感','耳机防御','无效社交耐受','独处回血'),'metric_bias',JSON_ARRAY(16,12,-14,15),'skill','能在食堂准确找到最不容易被拼桌的位置。','weakness','微信突然弹出：“方便接电话吗？”','accent','#9cff75')
  UNION ALL SELECT 'TYPE06','反骨体','你越催。我越慢。',
    JSON_OBJECT('en','DO NOT PUSH','description','你本来已经准备做了。直到有人说：“你怎么还没做？”于是事情发生了微妙变化。你的人生动力之一：不要让别人觉得他成功催动了你。','metrics',JSON_ARRAY('反催促','自主意识','规则接受','拖延反击'),'metric_bias',JSON_ARRAY(15,13,-12,10),'skill','别人越想替你安排，你越能瞬间确认自己真正想做什么。','weakness','有时为了证明没人能催你，顺便把自己也耽误了。','accent','#ffb75f')
  UNION ALL SELECT 'TYPE07','发疯体','情绪稳定仅供参考。',
    JSON_OBJECT('en','SYSTEM OVERLOAD','description','大多数时候：正常。有礼貌。好沟通。直到系统负载达到 100%。然后你会突然：“算了，全部毁灭吧。”五分钟以后继续上课。','metrics',JSON_ARRAY('稳定表象','压力容量','突然崩溃','快速恢复'),'metric_bias',JSON_ARRAY(8,5,15,12),'skill','崩完还能继续上课，系统恢复速度相当惊人。','weakness','压力条平时看不见，一出现往往已经 99%。','accent','#ff6b57')
  UNION ALL SELECT 'TYPE08','清醒体','我都知道。只是不说。',
    JSON_OBJECT('en','I KNOW.','description','很多事情你不是没看出来。你只是懒得拆穿。你知道谁在演。谁在装。谁其实不想去。但你通常选择：看破不说破。','metrics',JSON_ARRAY('观察力','情绪控制','吐槽欲','拆穿概率'),'metric_bias',JSON_ARRAY(16,10,8,-9),'skill','群聊里不说话，也能迅速搞清楚现在到底发生了什么。','weakness','知道得太多之后，偶尔会对无效沟通失去耐心。','accent','#75c9ff')
) seed
WHERE NOT EXISTS (
  SELECT 1 FROM personality_content_revisions current_revision
  WHERE current_revision.type_id=seed.type_id
);

UPDATE personality_types personality
JOIN personality_content_revisions revision
  ON revision.type_id=personality.type_id AND revision.version=1 AND revision.status='PUBLISHED'
SET personality.published_revision_id=revision.revision_id
WHERE personality.published_revision_id IS NULL;
