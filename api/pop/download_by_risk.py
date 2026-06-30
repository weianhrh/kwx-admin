import os
import requests
from pathlib import Path
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor

# 路径配置
log_path = Path("/www/wwwroot/open.kwxapp.cn/single/api/pop/log.txt")
save_root = Path("/www/wwwroot/open.kwxapp.cn/single/api/pop/downloads")

# 风险等级对应目录
risk_dirs = {
    "low": save_root / "low",
    "medium": save_root / "medium",
    "high": save_root / "high"
}

# 创建保存目录
for path in risk_dirs.values():
    path.mkdir(parents=True, exist_ok=True)

# 下载单个记录
def download_entry(entry):
    try:
        parts = entry.strip().split(" ")
        time_str = parts[0].replace("[", "") + " " + parts[1].replace("]", "")
        risk_level = parts[2]
        image_url = parts[3]
        log_time = datetime.strptime(time_str, "%Y-%m-%d %H:%M:%S")

        if log_time > datetime.now():
            return None  # 不处理未来的

        target_dir = risk_dirs.get(risk_level)
        if not target_dir:
            print(f"⚠️ 无效风险等级: {risk_level}")
            return None

        response = requests.get(image_url, timeout=15)
        if response.status_code == 200:
            filename = image_url.split("/")[-1].split("?")[0]
            save_path = target_dir / filename
            with open(save_path, "wb") as f:
                f.write(response.content)
            print(f"✅ 下载成功: {risk_level} -> {filename}")
            return entry  # 返回已处理的 entry
        else:
            print(f"❌ 下载失败 {response.status_code}: {image_url}")
            return None
    except Exception as e:
        print(f"❌ 异常: {e}")
        return None

def main():
    if not log_path.exists():
        print("❌ log.txt 不存在")
        return

    # 读取全部记录
    with open(log_path, "r", encoding="utf-8") as f:
        lines = [line.strip() for line in f if line.strip()]

    if not lines:
        print("⚠️ 日志为空")
        return

    # 使用线程池并发下载
    with ThreadPoolExecutor(max_workers=8) as executor:
        results = list(executor.map(download_entry, lines))

    # 剩余未处理的记录（未返回的就是未下载或跳过的）
    remaining = [line for line in lines if line not in results]

    # 重写 log.txt
    with open(log_path, "w", encoding="utf-8") as f:
        for line in remaining:
            f.write(line + "\n")

    print(f"🧹 已处理 {len(results) - results.count(None)} 条，剩余 {len(remaining)} 条")

if __name__ == "__main__":
    main()
