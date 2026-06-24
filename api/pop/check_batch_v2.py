import os, json, redis, time, requests, pymysql
import numpy as np
from PIL import Image
from queue import Queue
from io import BytesIO
import torch
import torch.multiprocessing as mp
from torchvision.models import efficientnet_b0
from torchvision.transforms import functional as F
from sklearn.metrics.pairwise import cosine_similarity

# ==== 全局配置 ====
REDIS_SRC_DB, REDIS_DST_DB = 4, 14
REDIS_HOST, REDIS_PORT = '127.0.0.1', 6379
CAPTURE_TIMEOUT = 15
SIM_THRESHOLD = 0.49
PROCESS_COUNT = 4  # 多进程数量
FEATURE_NPY_PATH = "/www/wwwroot/open.rcwulian.cn/single/api/pop/features.npy"
LABEL_MAP_PATH = "/www/wwwroot/open.rcwulian.cn/single/api/pop/label_map.json"
MODEL_PATH = "/www/wwwroot/open.rcwulian.cn/single/api/pop/risk_vuln_b0.pt"
USE_CUDA = torch.cuda.is_available()

MYSQL_CONFIG = {
    "host": "localhost", "user": "5grc", "password": "f6eca7806e73cd25",
    "database": "5grc", "port": 3306, "charset": "utf8mb4"
}

# ==== 类与工具函数 ====
class ResizeWithPad:
    def __init__(self, size=224): self.size = size
    def __call__(self, img):
        w, h = img.size
        scale = self.size / max(w, h)
        new_w, new_h = int(w * scale), int(h * scale)
        img = F.resize(img, (new_h, new_w))
        pad = [(self.size - new_w) // 2, (self.size - new_h) // 2]
        return F.pad(img, (pad[0], pad[1], self.size - new_w - pad[0], self.size - new_h - pad[1]), fill=0)

class FeatureExtractor(torch.nn.Module):
    def __init__(self, model):
        super().__init__()
        self.features = model.features
        self.pooling = model.avgpool
    def forward(self, x):
        return torch.flatten(self.pooling(self.features(x)), 1)

def load_model_and_features():
    model = efficientnet_b0(weights=None)
    with open(LABEL_MAP_PATH, 'r', encoding='utf-8') as f:
        idx2label = json.load(f)
    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, len(idx2label))
    model.load_state_dict(torch.load(MODEL_PATH, map_location='cuda' if USE_CUDA else 'cpu'))
    model.eval()
    return model, FeatureExtractor(model), np.load(FEATURE_NPY_PATH), idx2label

def get_device_image_serial(sn):
    try:
        conn = pymysql.connect(**MYSQL_CONFIG)
        with conn.cursor() as cursor:
            cursor.execute("SELECT image_device_serial FROM vehicles WHERE serial_number=%s", (sn,))
            result = cursor.fetchone()
            return result[0] if result else None
    except Exception as e:
        print(f"[MySQL] 查询失败: {e}")
        return None
    finally:
        conn.close()

def get_capture_image_url(sn):
    try:
        resp = requests.get(f"https://open.rcwulian.cn/api/pop/capture.php?sn={sn}", timeout=CAPTURE_TIMEOUT)
        return resp.json().get("image_url", None)
    except:
        return None

# ==== 进程处理函数 ====
def process_worker(sn_list, model, extractor, features, idx2label):
    redis_dst = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DST_DB, decode_responses=True)
    model = model.cuda() if USE_CUDA else model
    extractor = extractor.cuda() if USE_CUDA else extractor

    for sn in sn_list:
        try:
            image_sn = get_device_image_serial(sn)
            if not image_sn:
                continue
            url = get_capture_image_url(image_sn)
            if not url:
                continue

            img = Image.open(BytesIO(requests.get(url, timeout=10).content)).convert("RGB")
            img = ResizeWithPad()(img)
            tensor = F.normalize(F.to_tensor(img), mean=[0.485, 0.456, 0.406],
                                 std=[0.229, 0.224, 0.225]).unsqueeze(0)
            tensor = tensor.cuda() if USE_CUDA else tensor

            with torch.no_grad():
                output = model(tensor)
                pred = torch.softmax(output, 1)[0]
                pred_idx = pred.argmax().item()
                label = idx2label[str(pred_idx)]
                prob = pred[pred_idx].item()
                feat = extractor(tensor).cpu().numpy()
                score = float(np.max(cosine_similarity(feat, features)))

            # 风险判断逻辑
            if label == "低风险": mapped = "low"
            elif label == "中风险" and prob > 0.49: mapped = "medium"
            elif label == "高风险" and prob > 0.28: mapped = "high"
            else: mapped = "unknown"

            if score >= SIM_THRESHOLD and mapped in ("low", "medium", "high"):
                redis_key = f"device_violation:{image_sn}_predicted"
                data = {
                    "risk_level": mapped,
                    "image_url": url,
                    "reason": {"low": "轻度性暗示", "medium": "低俗或性暗示", "high": "严重违规内容"}.get(mapped),
                    "time": time.strftime("%Y-%m-%d %H:%M:%S"),
                    "hit_count": 1,
                    "remark": "自研"
                }
                if not redis_dst.exists(redis_key):
                    redis_dst.set(redis_key, json.dumps(data, ensure_ascii=False), ex=120)
                    print(f"✅ {redis_key} 写入成功 相似度: {score:.3f}, 置信度: {prob:.2f}")
                else:
                    print(f"⏭️ 已存在: {redis_key}")
            else:
                if label != "无风险":
                    print(f"⏩ 跳过: {url} 相似度: {score:.3f}, 标签: {label}, 置信度: {prob:.2f}")
        except Exception as e:
            print(f"❌ 推理失败 {sn}: {e}")

# ==== 主函数入口 ====
def main():
    redis_src = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_SRC_DB, decode_responses=True)
    keys = list(redis_src.keys("*"))
    print(f"🚀 总识别设备数: {len(keys)}")

    # 加载一次模型、特征等（主进程共享）
    model, extractor, features, idx2label = load_model_and_features()

    # 拆分为子任务
    chunk_size = max(1, len(keys) // PROCESS_COUNT)
    chunks = [keys[i:i + chunk_size] for i in range(0, len(keys), chunk_size)]

    ctx = mp.get_context("spawn")
    processes = []

    for chunk in chunks:
        p = ctx.Process(target=process_worker, args=(chunk, model, extractor, features, idx2label))
        p.start()
        processes.append(p)

    for p in processes:
        p.join()

if __name__ == "__main__":
    mp.set_start_method("spawn", force=True)
    main()
