import torch
import os
import shutil
import time
import logging
from torchvision import datasets, transforms
from torch.utils.data import DataLoader
from torchvision.models import efficientnet_b0
from torch.nn import CrossEntropyLoss, Linear
from torch.optim import Adam
from datetime import datetime
from pathlib import Path

# === 路径配置 ===
LOCAL_DATA_DIR = "/www/wwwroot/open.kwxapp.cn/single/api/pop/virus_vuln_inject/"
LOCAL_MODEL_PATH = "/www/wwwroot/open.kwxapp.cn/single/api/pop/efficientnet_b0.pth"

# === 日志系统初始化 ===
logging.basicConfig(
    filename='/www/wwwroot/open.kwxapp.cn/single/api/pop/log/finetune_local.log',
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# === 标签顺序 ===
label_order = ["safe", "low", "medium", "high"]

# === 图像预处理 ===
transform = transforms.Compose([
    transforms.Resize((224, 224)),
    transforms.ToTensor(),
    transforms.Normalize([0.485, 0.456, 0.406],
                         [0.229, 0.224, 0.225])
])

# === 加载本地数据集 ===
def load_dataset():
    dataset = datasets.ImageFolder(root=LOCAL_DATA_DIR, transform=transform)
    label_count = {}
    for _, label in dataset.samples:
        class_name = label_order[label]
        label_count[class_name] = label_count.get(class_name, 0) + 1
    logging.info(f"📊 本地数据分布: {label_count}")
    return dataset

# === 微调训练函数 ===
def finetune_local_dataset():
    dataset = load_dataset()
    dataloader = DataLoader(dataset, batch_size=8, shuffle=True)

    # === 计算类别权重（防止不均衡） ===
    targets = [label for _, label in dataset.samples]
    counts = torch.bincount(torch.tensor(targets), minlength=4).float()
    weights = 1.0 / torch.where(counts == 0, torch.ones_like(counts), counts)
    logging.info(f"⚖️ 类别权重: {weights.tolist()}")

    # === 加载模型 ===
    model = efficientnet_b0(weights=None)
    state_dict = torch.load(LOCAL_MODEL_PATH, map_location="cpu")
    model.load_state_dict(state_dict)

    for param in model.features.parameters():
        param.requires_grad = False
    model.classifier[1] = Linear(model.classifier[1].in_features, 4)

    device = torch.device("cpu")
    model.to(device)
    model.train()

    optimizer = Adam(model.parameters(), lr=1e-5)
    criterion = CrossEntropyLoss(weight=weights)

    # === 训练 2 轮 ===
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
        logging.info(f"🧪 Epoch {epoch+1}: Loss = {total_loss:.4f}")

    # === 保存模型 ===
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_path = f"{LOCAL_DATA_DIR}/risk_vuln_b0_{timestamp}.pt"
    torch.save(model.state_dict(), output_path)
    logging.info(f"✅ 模型保存成功：{output_path}")

    # === 删除所有已训练图片 ===
    for label in label_order:
        folder = Path(LOCAL_DATA_DIR) / label
        for img_file in folder.glob("*.jpg"):
            try:
                img_file.unlink()
                logging.info(f"🗑️ 删除已训练图片: {img_file}")
            except Exception as e:
                logging.error(f"⚠️ 删除失败: {img_file} - {e}")

if __name__ == "__main__":
    logging.info("🚀 本地微调任务启动...")
    try:
        finetune_local_dataset()
    except Exception as e:
        logging.error(f"❌ 微调异常: {e}")
