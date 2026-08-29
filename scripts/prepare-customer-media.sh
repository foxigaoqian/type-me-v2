#!/usr/bin/env bash
set -euo pipefail

source_root="${1:?usage: prepare-customer-media.sh /absolute/path/to/material}"
output_root="${2:-assets/media}"

optimize() {
  local destination="$1"
  local source="$2"
  mkdir -p "$(dirname "$destination")"
  convert "$source" -auto-orient -strip -resize '1200x1500>' -quality 80 "$destination"
}

new_root="$source_root/source"
old_root="$source_root/previous/extracted"

# TYPE 01 · 间歇卷王
optimize "$output_root/type01/main.webp"  "$new_root/CHOICE (6)(1)/CHOICE/HERO主视频女1.png"
optimize "$output_root/type01/front.webp" "$new_root/CHOICE (6)(1)/CHOICE/正面全身男2.png"
optimize "$output_root/type01/back.webp"  "$new_root/CHOICE (6)(1)/CHOICE/HERO著视频男5.png"
optimize "$output_root/type01/scene.webp" "$new_root/CHOICE (6)(1)/CHOICE/群体互动女3.png"

# TYPE 02 · 随缘体
optimize "$output_root/type02/main.webp"  "$new_root/CHOICE (7)(1)/CHOICE/群体互动男2.png"
optimize "$output_root/type02/front.webp" "$new_root/CHOICE (7)(1)/CHOICE/正面全身女2.png"
optimize "$output_root/type02/back.webp"  "$new_root/CHOICE (7)(1)/CHOICE/人格情绪男1.png"
optimize "$output_root/type02/scene.webp" "$new_root/CHOICE (7)(1)/CHOICE/群体互动女2.png"

# TYPE 03 · 嘴硬体
optimize "$output_root/type03/main.webp"  "$old_root/CHOICE (2)/CHOICE/HERO主视觉女1.png"
optimize "$output_root/type03/front.webp" "$old_root/CHOICE (2)/CHOICE/正面全身女1.png"
optimize "$output_root/type03/back.webp"  "$old_root/CHOICE (2)/CHOICE/群体互动3.png"
optimize "$output_root/type03/scene.webp" "$old_root/CHOICE (2)/CHOICE/HERO主视觉女3.png"

# TYPE 04 · 夜行体
optimize "$output_root/type04/main.webp"  "$old_root/CHOICE/CHOICE/45度动态女1.png"
optimize "$output_root/type04/front.webp" "$old_root/CHOICE/CHOICE/正面全身2.png"
optimize "$output_root/type04/back.webp"  "$old_root/CHOICE/CHOICE/HERO主视觉图4.png"
optimize "$output_root/type04/scene.webp" "$old_root/CHOICE/CHOICE/背面全身2.png"

# TYPE 05 · 边界守卫者
optimize "$output_root/type05/main.webp"  "$old_root/CHOICE (1)/CHOICE/HERO主视频女1.png"
optimize "$output_root/type05/front.webp" "$old_root/CHOICE (1)/CHOICE/HERO主视频图3.png"
optimize "$output_root/type05/back.webp"  "$old_root/CHOICE (1)/CHOICE/正面全身女2.png"
optimize "$output_root/type05/scene.webp" "$old_root/CHOICE (1)/CHOICE/45度动态女3.png"

# TYPE 06 · 反骨体
optimize "$output_root/type06/main.webp"  "$new_root/CHOICE (5)(1)/CHOICE/HERO主视频女1.png"
optimize "$output_root/type06/front.webp" "$new_root/CHOICE (5)(1)/CHOICE/HERO主视频男1.png"
optimize "$output_root/type06/back.webp"  "$new_root/CHOICE (5)(1)/CHOICE/背面全身男1.png"
optimize "$output_root/type06/scene.webp" "$new_root/CHOICE (5)(1)/CHOICE/人格情绪女1.png"

# TYPE 07 · 发疯体
optimize "$output_root/type07/main.webp"  "$new_root/CHOICE (4)(1)/CHOICE/HERO主视频男1.png"
optimize "$output_root/type07/front.webp" "$new_root/CHOICE (4)(1)/CHOICE/正面全身男1.png"
optimize "$output_root/type07/back.webp"  "$new_root/CHOICE (4)(1)/CHOICE/HERO主视频女2.png"
optimize "$output_root/type07/scene.webp" "$new_root/CHOICE (4)(1)/CHOICE/群体互动女1.png"

# TYPE 08 · 清醒体
optimize "$output_root/type08/main.webp"  "$new_root/CHOICE (3)(1)/CHOICE/人格情绪男2.png"
optimize "$output_root/type08/front.webp" "$new_root/CHOICE (3)(1)/CHOICE/正面全身男1.png"
optimize "$output_root/type08/back.webp"  "$new_root/CHOICE (3)(1)/CHOICE/群体互动女1.png"
optimize "$output_root/type08/scene.webp" "$new_root/CHOICE (3)(1)/CHOICE/群体互动男2.png"

# 真实基础成衣与洗标
optimize "$output_root/product/base-front.webp" "$new_root/细节(1)/细节/蓝底平面图正1.png"
optimize "$output_root/product/base-back.webp"  "$new_root/细节(1)/细节/蓝底平面背面2.png"
optimize "$output_root/product/care-label.webp" "$new_root/细节(1)/细节/洗标-洗涤说明.png"

find "$output_root" -type f -name '*.webp' -print | sort
