import os
import torch
import json
import redis
import time
import pymysql
import requests
import numpy as np
from io import BytesIO
from PIL import Image
from sklearn.metrics.pairwise import cosine_similarity
from torchvision.models import efficientnet_b0
from torchvision.transforms import functional as F

# === Redis配置 ===
REDIS_SRC_DB = 4
REDIS_DST_DB = 14
REDIS_HOST = '127.0.0.1'
REDIS_PORT = 6379

# === MySQL配置 ===
MYSQL_CONFIG = {
    "host": "localhost",
    "user": "5grc",
    "password": "f6eca7806e73cd25",
    "database": "5grc",
    "port": 3306,
    "charset": "utf8mb4"
}

# === Resize + Padding 预处理 ===
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

# === 时间戳工具 ===
def get_current_time():
    return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime())

# === 写入日志 ===
def append_log(risk_level, image_url):
    with open("/www/wwwroot/open.kwxapp.cn/single/api/pop/log/check_log.txt", "a", encoding="utf-8") as f:
        f.write(f"[{get_current_time()}] {risk_level} {image_url}\n")

# === 风险标签映射 ===
def map_risk_level(label):
    return {
        "中风险": "medium",
        "低风险": "low",
        "高风险": "high"
    }.get(label, "unknown")

# === 获取抓拍图 URL ===
def get_capture_image_url(sn):
    capture_api = f"https://open.kwxapp.cn/api/pop/capture.php?sn={sn}"
    for _ in range(2):
        try:
            resp = requests.get(capture_api, timeout=15)
            resp.raise_for_status()
            result = resp.json()
            if result.get("image_url"):
                return result["image_url"]
            time.sleep(1)
        except:
            time.sleep(1)
    return None

# === 获取 image_device_serial ===
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

# === 加载模型、映射、特征库 ===
def load_model():
    model = efficientnet_b0(weights=None)
    with open("/www/wwwroot/open.kwxapp.cn/single/api/pop/label_map.json", 'r', encoding='utf-8') as f:
        idx2label = json.load(f)

    num_classes = len(idx2label)
    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, num_classes)
    model.load_state_dict(torch.load("/www/wwwroot/open.kwxapp.cn/single/api/pop/risk_vuln_b0.pt", map_location='cpu', weights_only=True))
    model.eval()

    # === 提取器 ===
    class FeatureExtractor(torch.nn.Module):
        def __init__(self, model):
            super().__init__()
            self.features = model.features
            self.pooling = model.avgpool
        def forward(self, x):
            x = self.features(x)
            x = self.pooling(x)
            return torch.flatten(x, 1)
    extractor = FeatureExtractor(model)

    # === 加载特征库 ===
    feature_path = "/www/wwwroot/open.kwxapp.cn/single/api/pop/features.npy"
    feature_library = np.load(feature_path) if os.path.exists(feature_path) else None

    return model, extractor, feature_library, idx2label

# === 预测主函数 ===
def predict_from_url(url, model, extractor, feature_library, idx2label):
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

            # 提取特征
            feat = extractor(img).cpu().numpy()  # shape (1, 1280)
            sim_score = 0.0
            if feature_library is not None:
                sims = cosine_similarity(feat, feature_library)[0]
                sim_score = float(np.max(sims))  # 取最大相似度

            return label, prob, sim_score
    except Exception as e:
        print(f"❌ 推理失败: {e}")
        return "加载失败", 0.0, 0.0

# === 主程序 ===
if __name__ == "__main__":
    SIM_THRESHOLD = 0.85  # 相似度阈值
    model, extractor, feature_library, idx2label = load_model()
    redis_src = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_SRC_DB, decode_responses=True)
    redis_dst = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DST_DB, decode_responses=True)

    while True:
        keys = redis_src.keys("*")
        for sn in keys:
            try:
                image_serial = get_image_device_serial(sn)
                if not image_serial:
                    continue
                image_url = get_capture_image_url(image_serial)
                if not image_url:
                    print(f"⏭️ 无法获取图片链接: {sn}")
                    continue

                label, prob, sim_score = predict_from_url(image_url, model, extractor, feature_library, idx2label)
                mapped_level = map_risk_level(label)

                # 联合判断：分类为中高风险 或者 相似度高
                # if mapped_level in ("unknown", "low") and sim_score < SIM_THRESHOLD:
                #     print(f"⚠️ 忽略: {sn} => label={label}, 相似度={sim_score:.3f}")
                #     continue
                if sim_score < SIM_THRESHOLD:
                    print(f"⚠️ 相似度不足: {sn} => label={label}, 相似度={sim_score:.3f}")
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
                    print(f"✅ 写入: {redis_key} - 风险: {mapped_level} - 相似度: {sim_score:.3f}")
                else:
                    print(f"⏩ 已存在: {redis_key}，跳过")
            except Exception as e:
                print(f"❌ 错误: {sn} -> {e}")
        time.sleep(10)
