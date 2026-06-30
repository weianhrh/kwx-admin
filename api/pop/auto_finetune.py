import redis
import json
import torch
import time
from torch.utils.data import Dataset, DataLoader
from torchvision.models import efficientnet_b0, EfficientNet_B0_Weights
from torchvision import transforms
from PIL import Image
import requests
from io import BytesIO

# Redis连接（第10号库）
r = redis.Redis(host='localhost', port=6379, db=10, decode_responses=True)

# 风险标签映射
risk_label_map = {
    "safe": 2,
    "low": 1,
    "medium": 0,
    "high": 3
}

# 预处理
transform = transforms.Compose([
    transforms.Resize((224, 224)),
    transforms.ToTensor(),
    transforms.Normalize([0.485, 0.456, 0.406],
                         [0.229, 0.224, 0.225])
])

# 单条训练函数
def train_one_feedback(key, data):
    label_str = data.get("risk_level")
    image_url = data.get("image_url")
    label = risk_label_map.get(label_str, 2)

    try:
        response = requests.get(image_url, timeout=10)
        image = Image.open(BytesIO(response.content)).convert("RGB")
    except:
        print(f"❌ 下载失败：{image_url}")
        return

    image = transform(image).unsqueeze(0)
    target = torch.tensor([label])

    # 不自动下载
    model = efficientnet_b0(weights=None)
    
    # 手动加载本地权重
    state_dict = torch.load("/www/wwwroot/open.kwxapp.cn/single/api/pop/efficientnet_b0.pth", map_location="cpu")
    model.load_state_dict(state_dict)

    model.classifier[1] = torch.nn.Linear(model.classifier[1].in_features, 4)

    device = torch.device("cpu")
    model.to(device)
    image, target = image.to(device), target.to(device)

    optimizer = torch.optim.Adam(model.parameters(), lr=1e-4)
    criterion = torch.nn.CrossEntropyLoss()

    model.train()
    for epoch in range(2):
        optimizer.zero_grad()
        outputs = model(image)
        loss = criterion(outputs, target)
        loss.backward()
        optimizer.step()
        print(f"✅ [{key}] Epoch {epoch+1} Loss: {loss.item():.4f}")

    torch.save(model.state_dict(), "/www/wwwroot/open.kwxapp.cn/single/api/pop/risk_vuln_b0_finetuned.pt")
    print(f"💾 模型保存完成（已处理 {key}）")

    r.delete(key)

# 主逻辑循环
if __name__ == "__main__":
    print("🚀 实时微调服务已启动（逐条训练 Redis 第10号库）")

    while True:
        keys = r.keys("feedback:*")
        for key in keys:
            key_type = r.type(key)
        
            if key_type == "hash":
                data = r.hgetall(key)
            elif key_type == "string":
                try:
                    data = json.loads(r.get(key))  # 解析字符串为 dict
                except Exception as e:
                    print(f"❌ 键 {key} 的字符串格式不是有效 JSON，跳过：{e}")
                    continue
            else:
                print(f"⚠️ Redis 键 {key} 类型为 {key_type}，不支持，跳过")
                continue
        
            if data and "trained" not in data:
                train_one_feedback(key, data)
                break  # 一次只处理一个 key

        time.sleep(5)
