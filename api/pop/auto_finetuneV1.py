import redis
import torch
import time
import json
from torch.utils.data import Dataset, DataLoader
from torchvision.models import efficientnet_b0
from torchvision import transforms
from PIL import Image
import requests
from io import BytesIO
from collections import Counter
from datetime import datetime
import logging
from pathlib import Path

# === 本地 EfficientNet 权重路径 ===
LOCAL_MODEL_PATH = "/www/wwwroot/open.rcwulian.cn/single/api/pop/efficientnet_b0.pth"  # 👈 修改成你实际保存的路径

# === 日志系统初始化 ===
logging.basicConfig(
    filename='finetune.log',
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# === Redis连接 ===
r = redis.Redis(host='localhost', port=6379, db=10, decode_responses=True)

# === 风险等级映射 ===
risk_label_map = {
    "safe": 2,
    "low": 1,
    "medium": 0,
    "high": 3
}
label_order = ["safe", "low", "medium", "high"]

# === 图像预处理 ===
transform = transforms.Compose([
    transforms.Resize((224, 224)),
    transforms.ToTensor(),
    transforms.Normalize([0.485, 0.456, 0.406],
                         [0.229, 0.224, 0.225])
])

# === 类别权重计算 ===
def compute_class_weights(keys):
    labels = []
    for k in keys:
        try:
            if r.type(k) == "hash":
                label = r.hget(k, "risk_level")
            elif r.type(k) == "string":
                value = json.loads(r.get(k))
                label = value.get("risk_level")
            else:
                continue
            if label in risk_label_map:
                labels.append(label)
        except:
            continue

    count = Counter(labels)
    freqs = [count.get(clz, 1) for clz in label_order]
    weights = [1.0 / f for f in freqs]
    return torch.tensor(weights, dtype=torch.float), count

# === Redis 数据集类 ===
class RedisImageDataset(Dataset):
    def __init__(self, redis_conn, keys, transform=None):
        self.redis = redis_conn
        self.keys = keys
        self.transform = transform

    def __len__(self):
        return len(self.keys)

    def __getitem__(self, idx):
        key = self.keys[idx]
        try:
            if r.type(key) == "hash":
                data = self.redis.hgetall(key)
            elif r.type(key) == "string":
                data = json.loads(self.redis.get(key))
            else:
                raise ValueError("不支持的类型")

            image_url = data.get("image_url", "")
            label = risk_label_map.get(data.get("risk_level"), 2)

            for attempt in range(3):
                try:
                    resp = requests.get(image_url, timeout=8)
                    image = Image.open(BytesIO(resp.content)).convert("RGB")
                    break
                except Exception as e:
                    logging.warning(f"下载失败（{attempt+1}/3）：{image_url} - {e}")
                    time.sleep(1)
            else:
                image = Image.new("RGB", (224, 224), (0, 0, 0))
                logging.error(f"黑图代替原图：{image_url}")

            if self.transform:
                image = self.transform(image)
            return image, label
        except Exception as e:
            logging.error(f"❌ 加载样本失败：{key} - {e}")
            return Image.new("RGB", (224, 224), (0, 0, 0)), 2  # 返回默认安全图

# === 微调训练函数 ===
def finetune_on_keys(keys):
    dataset = RedisImageDataset(r, keys, transform=transform)
    dataloader = DataLoader(dataset, batch_size=8, shuffle=True)

    weights, counts = compute_class_weights(keys)
    logging.info(f"类别分布: {dict(counts)}")
    logging.info(f"类别权重: {weights.tolist()}")

    # ✅ 本地加载 EfficientNet 权重
    model = efficientnet_b0(weights=None)
    state_dict = torch.load(LOCAL_MODEL_PATH, map_location="cpu")
    model.load_state_dict(state_dict)

    # ✅ 冻结前面层，仅训练分类器
    for param in model.features.parameters():
        param.requires_grad = False
    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, 4)

    device = torch.device("cpu")
    model.to(device)

    optimizer = torch.optim.Adam(model.parameters(), lr=1e-5)
    criterion = torch.nn.CrossEntropyLoss(weight=weights.to(device))

    model.train()
    for epoch in range(2):
        total_loss = 0
        for imgs, labels in dataloader:
            imgs, labels = imgs.to(device), labels.to(device)
            optimizer.zero_grad()
            outputs = model(imgs)
            loss = criterion(outputs, labels)
            loss.backward()
            optimizer.step()
            total_loss += loss.item()
        logging.info(f"Epoch {epoch+1} Loss: {total_loss:.4f}")

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    model_path = f"/www/wwwroot/open.rcwulian.cn/single/api/pop/risk_vuln_b0_{timestamp}.pt"
    torch.save(model.state_dict(), model_path)
    logging.info(f"✅ 模型保存成功: {model_path}")

    for key in keys:
        r.delete(key)
        logging.info(f"🧹 清理 Redis 数据: {key}")

# === 主循环 ===
if __name__ == "__main__":
    logging.info("🚀 微调服务已启动（支持本地模型 + string 类型样本）")

    while True:
        try:
            all_keys = r.keys("feedback:*")
            valid_keys = []
            for k in all_keys:
                if r.type(k) == "hash" and not r.hexists(k, "trained"):
                    valid_keys.append(k)
                elif r.type(k) == "string":
                    try:
                        val = json.loads(r.get(k))
                        if "trained" not in val:
                            valid_keys.append(k)
                    except:
                        logging.warning(f"⚠️ 非法 JSON：{k}")
                        continue

            if len(valid_keys) >= 5:
                logging.info(f"📦 收集到 {len(valid_keys)} 条新样本，开始训练...")
                finetune_on_keys(valid_keys)
            else:
                logging.info(f"⏸ 样本不足（{len(valid_keys)} 条），等待中...")
        except Exception as e:
            logging.error(f"❌ 主循环异常: {e}")
        time.sleep(60)




