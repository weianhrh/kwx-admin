import os, json, redis, time, requests, pymysql
import threading
import numpy as np
from queue import Queue
from io import BytesIO
from PIL import Image
from sklearn.metrics.pairwise import cosine_similarity
import torch
from torchvision.models import efficientnet_b0
from torchvision.transforms import functional as F

# 配置项
REDIS_SRC_DB, REDIS_DST_DB = 4, 14
REDIS_HOST, REDIS_PORT = '127.0.0.1', 6379
CAPTURE_TIMEOUT = 15
SIM_THRESHOLD = 0.49
THREAD_COUNT = 10
FEATURE_NPY_PATH = "/www/wwwroot/open.kwxapp.cn/single/api/pop/features.npy"
LABEL_MAP_PATH = "/www/wwwroot/open.kwxapp.cn/single/api/pop/label_map.json"
MODEL_PATH = "/www/wwwroot/open.kwxapp.cn/single/api/pop/risk_vuln_b0.pt"
DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")
print(f"🚀 模型将在 {DEVICE} 上运行")


MYSQL_CONFIG = {
    "host": "localhost", "user": "5grc", "password": "f6eca7806e73cd25",
    "database": "5grc", "port": 3306, "charset": "utf8mb4"
}

# 图像预处理
class ResizeWithPad:
    def __init__(self, size=224): self.size = size
    def __call__(self, img):
        w, h = img.size
        scale = self.size / max(w, h)
        new_w, new_h = int(w * scale), int(h * scale)
        img = F.resize(img, (new_h, new_w))
        pad = [(self.size - new_w) // 2, (self.size - new_h) // 2]
        return F.pad(img, (pad[0], pad[1], self.size - new_w - pad[0], self.size - new_h - pad[1]), fill=0)

# 加载模型和特征提取器
def load_model_and_features():
    model = efficientnet_b0(weights=None)
    with open(LABEL_MAP_PATH, 'r', encoding='utf-8') as f: idx2label = json.load(f)
    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, len(idx2label))
    # model.load_state_dict(torch.load(MODEL_PATH, map_location='cpu', weights_only=True))
    model.load_state_dict(torch.load(MODEL_PATH, map_location=DEVICE, weights_only=True))
    model = model.to(DEVICE)

    model.eval()

    class FeatureExtractor(torch.nn.Module):
        def __init__(self, model): super().__init__(); self.features = model.features; self.pooling = model.avgpool
        def forward(self, x): return torch.flatten(self.pooling(self.features(x)), 1)

    # return model, FeatureExtractor(model), np.load(FEATURE_NPY_PATH), idx2label
    return model, FeatureExtractor(model).to(DEVICE), np.load(FEATURE_NPY_PATH), idx2label

# 数据获取
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
        resp = requests.get(f"https://open.kwxapp.cn/api/pop/capture.php?sn={sn}", timeout=CAPTURE_TIMEOUT)
        return resp.json().get("image_url", None)
    except:
        return None

# 抓图线程
def capture_worker(redis_keys, captured_images):
    while True:
        try:
            sn = redis_keys.get(timeout=2)
        except:
            break
        image_sn = get_device_image_serial(sn)
        if not image_sn: continue
        url = get_capture_image_url(image_sn)
        if url:
            captured_images.put((sn, image_sn, url))
        redis_keys.task_done()

# 推理线程
def infer_worker(model, extractor, features, idx2label, captured_images, redis_dst):
    while True:
        try:
            sn, image_sn, url = captured_images.get(timeout=2)
        except:
            break
        try:
            img = Image.open(BytesIO(requests.get(url, timeout=10).content)).convert("RGB")
            img = ResizeWithPad()(img)
            tensor = F.normalize(F.to_tensor(img), mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225]).unsqueeze(0)
            tensor = tensor.to(DEVICE)
            with torch.no_grad():
                output = model(tensor)
                pred = torch.softmax(output, 1)[0]
                pred_idx = pred.argmax().item()
                label = idx2label[str(pred_idx)]
                feat = extractor(tensor).numpy()
                score = float(np.max(cosine_similarity(feat, features))) if features is not None else 0.0

                mapped = {"低风险": "low", "中风险": "medium", "高风险": "high"}.get(label, "unknown")
                allow_write = False

                if mapped == "medium" and score > 0.49:
                    allow_write = True
                elif mapped == "high" and score > 0.28:
                    allow_write = True

                if allow_write:
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
                        print(f"✅ {redis_key} 写入成功 相似度: {score:.3f}")
                else:
                    print(f"⏩ 跳过: {url} 标签: {label}, 相似度: {score:.3f}")

        except Exception as e:
            print(f"❌ 推理失败 {sn}: {e}")
        finally:
            captured_images.task_done()


# 主执行函数
def main():
    redis_src = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_SRC_DB, decode_responses=True)
    redis_dst = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, db=REDIS_DST_DB, decode_responses=True)

    print("🚀 正在加载模型与特征库...")
    model, extractor, feature_library, idx2label = load_model_and_features()

    while True:
        redis_keys = Queue()
        for key in redis_src.keys("*"):
            redis_keys.put(key)

        captured_images = Queue()

        # === 每轮统计 ===
        stats = {
            "low": 0,
            "medium": 0,
            "high": 0,
            "unknown": 0,
            "skip": 0,
            "fail": 0,
        }

        # 修改后的 infer_worker 用于写入统计
        def infer_worker_stats():
            while True:
                try:
                    sn, image_sn, url = captured_images.get(timeout=2)
                except:
                    break
                try:
                    img = Image.open(BytesIO(requests.get(url, timeout=10).content)).convert("RGB")
                    img = ResizeWithPad()(img)
                    tensor = F.normalize(F.to_tensor(img), mean=[0.485, 0.456, 0.406], std=[0.229, 0.224, 0.225]).unsqueeze(0)
                    tensor = tensor.to(DEVICE)
                    with torch.no_grad():
                        output = model(tensor)
                        pred = torch.softmax(output, 1)[0]
                        pred_idx = pred.argmax().item()
                        label = idx2label[str(pred_idx)]
                        feat = extractor(tensor).numpy()
                        score = float(np.max(cosine_similarity(feat, feature_library))) if feature_library is not None else 0.0
        
                        mapped = {"低风险": "low", "中风险": "medium", "高风险": "high"}.get(label, "unknown")
        
                        allow_write = False
                        if mapped == "medium" and score > 0.49:
                            allow_write = True
                        elif mapped == "high" and score > 0.28:
                            allow_write = True
        
                        if allow_write:
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
                                print(f"✅ {redis_key} 写入成功 相似度: {score:.3f}")
                                stats[mapped] += 1
                            else:
                                print(f"⏭️ 已存在: {redis_key}")
                        else:
                            print(f"⏩ 跳过: {url} 标签: {label}, 相似度: {score:.3f}")
                            stats["skip"] += 1
        
                except Exception as e:
                    print(f"❌ 推理失败 {sn}: {e}")
                    stats["fail"] += 1
                finally:
                    captured_images.task_done()

        # 启动抓图线程
        for _ in range(THREAD_COUNT):
            threading.Thread(target=capture_worker, args=(redis_keys, captured_images), daemon=True).start()
        redis_keys.join()

        # 启动识别线程（带统计）
        for _ in range(THREAD_COUNT):
            threading.Thread(target=infer_worker_stats, daemon=True).start()
        captured_images.join()

        # 输出统计信息
        print(f"""
📊 本轮识别完成:
  🟩 低风险:   {stats['low']}
  🟨 中风险:   {stats['medium']}
  🟥 高风险:   {stats['high']}
  ⚪️ 未知:     {stats['unknown']}
  ⏩ 跳过:     {stats['skip']}
  ❌ 失败:     {stats['fail']}
==============================
""")

        time.sleep(10)


if __name__ == "__main__":
    main()
