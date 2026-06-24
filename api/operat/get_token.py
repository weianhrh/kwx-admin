import jwt
import time
from cryptography.hazmat.primitives import serialization

# ====== 替换你自己的信息 ======
PRIVATE_KEY_PEM = b"""-----BEGIN PRIVATE KEY-----
MC4CAQAwBQYDK2VwBCIEIOQfsVSiq2/2tPFncymDWyg8m45a+LNkTDY9FitJ6E5c
-----END PRIVATE KEY-----
"""
KID = "T4WMJVYBCJ"         # 凭据ID
PROJECT_ID = "3AE3T6KKMC"  # 项目ID（sub）

# ====== 生成当前时间戳 ======
now = int(time.time())
payload = {
    "sub": PROJECT_ID,
    "iat": now - 30,
    "exp": now + 86400  # 10分钟有效期
}

headers = {
    "alg": "EdDSA",
    "kid": KID
}

# ====== 加载 Ed25519 私钥 ======
private_key = serialization.load_pem_private_key(
    PRIVATE_KEY_PEM,
    password=None,
)

# ====== 生成 JWT ======
token = jwt.encode(
    payload,
    private_key,
    algorithm="EdDSA",
    headers=headers
)

print("你的 JWT token 是：")
print(token)

