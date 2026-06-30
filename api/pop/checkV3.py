
import torch
from torchvision.models import efficientnet_b0
from torchvision.transforms import functional as F
from PIL import Image
import requests
from io import BytesIO
import json
import redis
import time
import pymysql

# === Redis配置 ===
REDIS_SRC_DB = 4   # 在线设备
REDIS_DST_DB = 14  # 抓图及预测输出

REDIS_HOST = '127.0.0.1'
REDIS_PORT = 6379

# === MySQL 配置 ===
MYSQL_CONFIG = {
    "host": "localhost",
    "user": "5grc",
    "password": "f6eca7806e73cd25",
    "database": "5grc",
    "port": 3306,
    "charset": "utf8mb4"
}
def append_log(risk_level, image_url):
    with open("/www/wwwroot/open.kwxapp.cn/single/api/pop/log.txt", "a", encoding="utf-8") as f:
        f.write(f"[{get_current_time()}] {risk_level} {image_url}\n")

# === 自定义 Resize + Padding ===
class ResizeWithPad:
    def __init__(self, size=224):
        self.size = size

    def __call__(self, img):
        w, h = img.size
        scale = self.size / max(w, h)
        new_w, new_h = int(w * scale), int(h * scale)
        img = F.resize(img, (new_h, new_w))
        pad_left = (self.size - new_w) // 2
        pad_top = (self.size - new_h) // 2
        pad_right = self.size - new_w - pad_left
        pad_bottom = self.size - new_h - pad_top
        return F.pad(img, (pad_left, pad_top, pad_right, pad_bottom), fill=0)

# === 加载模型和标签 ===
def load_model():
    model = efficientnet_b0(weights=None)
    with open("/www/wwwroot/open.kwxapp.cn/single/api/pop/label_map.json", 'r', encoding='utf-8') as f:
        idx2label = json.load(f)

    num_classes = len(idx2label)
    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, num_classes)
    model.load_state_dict(torch.load("/www/wwwroot/open.kwxapp.cn/single/api/pop/risk_vuln_b0.pt", map_location='cpu', weights_only=True))
    model.eval()

    return model, idx2label

# === 推理函数 ===
def predict_from_url(url, model, idx2label):
    try:
        resp = requests.get(url, timeout=10)
        resp.raise_for_status()
        img = Image.open(BytesIO(resp.content)).convert('RGB')
        transform = ResizeWithPad(224)
        img = transform(img)
        img = F.to_tensor(img)
        img = F.normalize(img, mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225])
        img = img.unsqueeze(0)

        with torch.no_grad():
            output = model(img)
            probs = torch.softmax(output, dim=1)[0]
            pred_idx = torch.argmax(probs).item()
            label = idx2label[str(pred_idx)]
            prob = probs[pred_idx].item()

            topk = torch.topk(probs, k=4)
            top_results = [
                {
                    "label": idx2label.get(str(topk.indices[i].item()), "未知"),
                    "confidence": round(topk.values[i].item(), 4)
                }
                for i in range(topk.indices.size(0))
            ]

            return label, prob, top_results
    except Exception as e:
        return "加载失败", 0.0, []

# === 映射风险等级 ===
def map_risk_level(label):
    mapping = {
        "中风险": "medium",
        "低风险": "low",
        "高风险": "high"
    }
    return mapping.get(label, "unknown")

# === 时间戳 ===
def get_current_time():
    return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime())

# === 查设备 image_device_serial ===
def get_image_device_serial(sn):
    try:
        conn = pymysql.connect(**MYSQL_CONFIG)
        cursor = conn.cursor()
        cursor.execute("SELECT image_device_serial FROM vehicles WHERE serial_number=%s", (sn,))
        row = cursor.fetchone()
        return row[0] if row else None
    except Exception as e:
        print(f"❌ MySQL查询失败: {e}")
        return None
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()
def get_capture_image_url(sn):
    capture_api = f"https://open.kwxapp.cn/api/pop/capture.php?sn={sn}"
    for attempt in range(2):
        try:
            resp = requests.get(capture_api, timeout=15)
            resp.raise_for_status()
            result = resp.json()
            image_url = result.get("image_url")
            if image_url:
                return image_url
            else:
                time.sleep(1)
        except:
            time.sleep(1)
    return None

# === 主程序入口 ===
if __name__ == "__main__":
    model, idx2label = load_model()
    redis_src = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_SRC_DB, decode_responses=True)
    redis_dst = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DST_DB, decode_responses=True)
    while True:
        keys = redis_src.keys("*")  # 所有键本身就是sn
        for sn in keys:
            try:
                image_serial = get_image_device_serial(sn)
                if not image_serial:
                    continue
        
                image_url = get_capture_image_url(image_serial)
                if not image_url:
                    print(f"⏭️ 无法获取图片链接: {sn}")
                    continue
        
                label, prob, top4 = predict_from_url(image_url, model, idx2label)
                mapped_level = map_risk_level(label)
                if mapped_level in ("unknown", "low"):
                    print(f"⚠️ 忽略未知风险: {sn} => {label}")
                    continue
        
                reason_mapping = {
                    "low": "轻度性暗示",
                    "medium": "低俗或性暗示",
                    "high": "严重违规内容"
                }
        
                data = {
                    "reason": reason_mapping.get(mapped_level, "未知原因"),
                    "image_url": image_url,
                    "risk_level": mapped_level,
                    "time": get_current_time(),
                    "hit_count": 1,
                    "remark": "自研"
                }
        
                append_log(mapped_level, image_url)
        
                redis_key = f"device_violation:{image_serial}_predicted"
                if not redis_dst.exists(redis_key):
                    redis_dst.set(redis_key, json.dumps(data, ensure_ascii=False), ex=120)
                    print(f"✅ {redis_key} 写入成功 - 风险等级: {mapped_level}")
                else:
                    print(f"⏩ 已存在: {redis_key}，跳过")
        
            except Exception as e:
                print(f"❌ 抓图或推理失败: {sn} -> {e}")
        time.sleep(10)
