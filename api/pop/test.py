import redis
import json

r = redis.Redis(host='localhost', port=6379, db=10, decode_responses=True)

valid_keys = r.keys("feedback:*")
print("===== 当前 Redis 样本内容预览（JSON解析后） =====")
for k in valid_keys:
    try:
        t = r.type(k)
        
        if t == "string":
            raw = r.get(k)
            try:
                val = json.loads(raw)

                # ✅ 跳过 risk_level 为 "none" 的记录
                if val.get("risk_level") == "none":
                    continue

                print(f"🔑 key: {k}")
                print(f"  📦 raw: {raw}")  # 原始字符串内容
                for field, value in val.items():
                    print(f"    - {field}: {value}")
                print("-" * 40)

            except json.JSONDecodeError as e:
                print(f"🔑 key: {k}")
                print(f"  ❌ JSON解析失败: {e}")
                print("-" * 40)

        elif t == "hash":
            val = r.hgetall(k)
            # 如需跳过 hash 类型的 none，可加同样判断
            if val.get("risk_level") == "none":
                continue
            print(f"🔑 key: {k}")
            for field, value in val.items():
                print(f"    - {field}: {value}")
            print("-" * 40)
        else:
            print(f"🔑 key: {k}")
            print(f"⚠️ 不支持的数据类型：{t}")
            print("-" * 40)

    except Exception as e:
        print(f"❌ 解析失败: {k} - {e}")

print("=========================================")
# import redis
# import json

# # Redis 连接信息
# r = redis.Redis(host='localhost', port=6379, db=10, decode_responses=True)

# # 查找所有 feedback:* 键
# keys = r.keys("feedback:*")
# deleted = 0

# for key in keys:
#     try:
#         if r.type(key) != "string":
#             continue
#         raw = r.get(key)
#         val = json.loads(raw)

#         if val.get("risk_level") == "none":
#             r.delete(key)
#             print(f"🗑️ 已删除键: {key}")
#             deleted += 1

#     except Exception as e:
#         print(f"❌ 键解析失败: {key} - {e}")

# print(f"\n✅ 共删除 {deleted} 个 risk_level 为 none 的键。")
