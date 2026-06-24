import redis
import time
import json
import hashlib
import logging
import requests
from io import BytesIO
from PIL import Image
from pathlib import Path

# === Redis配置 ===
r = redis.Redis(host='localhost', port=6379, db=10, decode_responses=True)

# === 风险等级映射及本地保存路径 ===
risk_label_map = {
    "safe": 2,
    "low": 1,
    "medium": 0,
    "high": 3
}
save_root = Path("/www/wwwroot/open.rcwulian.cn/single/api/pop/virus_vuln_inject")
for level in risk_label_map.keys():
    (save_root / level).mkdir(parents=True, exist_ok=True)

# === 日志系统 ===
logging.basicConfig(
    filename='/www/wwwroot/open.rcwulian.cn/single/api/pop/log/download_images.log',
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# === 单图下载函数 ===
def download_image(image_url, save_path):
    try:
        headers = {"User-Agent": "Mozilla/5.0 (compatible; ImageDownloader/1.0)"}
        resp = requests.get(image_url, headers=headers, timeout=8)
        img = Image.open(BytesIO(resp.content)).convert("RGB")
        img.save(save_path)
        return True
    except Exception as e:
        logging.warning(f"下载失败: {image_url} - {e}")
        return False

# === 主循环逻辑 ===
if __name__ == "__main__":
    logging.info("🚀 图片下载服务启动（下载成功即删除 Redis）")

    while True:
        try:
            keys = r.keys("feedback:*")
            for key in keys:
                try:
                    # === 读取数据 ===
                    if r.type(key) == "hash":
                        data = r.hgetall(key)
                    elif r.type(key) == "string":
                        data = json.loads(r.get(key))
                    else:
                        continue

                    if "trained" in data:
                        continue

                    image_url = data.get("image_url", "")
                    risk_level = data.get("risk_level", "safe")

                    if not image_url or risk_level not in risk_label_map:
                        continue

                    # filename = f"{key.replace('feedback:', '')}_{int(time.time())}.jpg"
                    # save_path = save_root / risk_level / filename
                    
                    
                    timestamp = int(time.time())
                    hash_part = hashlib.md5(key.encode()).hexdigest()[:6]
                    filename = f"{timestamp}_{hash_part}.jpg"
                    save_path = save_root / risk_level / filename

                    # === 下载 + 删除逻辑 ===
                    if download_image(image_url, save_path):
                        logging.info(f"✅ 下载成功：{key} -> {save_path}")
                        r.delete(key)
                        logging.info(f"🗑️ 已从 Redis 删除：{key}")
                    else:
                        logging.error(f"❌ 下载失败：{key} -> {image_url}")

                    time.sleep(0.5)  # 节流
                except Exception as e:
                    logging.error(f"处理失败：{key} - {e}")
        except Exception as e:
            logging.error(f"主循环异常: {e}")
        time.sleep(10)
