# -*- coding: utf-8 -*-
"""
仅用 https://live.kuaishou.com/live_api/follow/living 判定是否直播
- 同时使用 author.id 与 author.originUserId 两套集合
- slug 为纯数字 -> 用 originUserId；否则 -> 用 id
- 忽略抖音 snssdk1128:// 开头的链接
Python 3.8 兼容
"""

import re
import time
import random
import pymysql
import requests
from typing import Optional, Tuple, Set

# =============== 1) Cookie ===============
COOKIE_STRING = (
    "_did=web_417868182434A173; "
    "bUserId=1000445358101; "
    "client_key=65890b29; "
    "clientid=3; "
    "did=web_8098361fd837d45039f62b3a8e41dc1c; "
    "did=web_9968567e94ac468962f1625ff4937fdb; "
    "didv=1761292845000; "
    "kpn=GAME_ZONE; "
    "kuaishou.live.web_ph=878553f5c948e8150b24abf15c0d274d7cbb; "
    "kuaishou.live.web_st=ChRrdWFpc2hvdS5saXZlLndlYi5zdBKgAeOUnsKl8bytKW9cQ5xkel_kcTTvdheHM0hSbYg4HHhN3taqLKmYVvhauak6CY2nFvVNd1FhFxOiOL9tWT33FN-KlZujFrZ6yiJ4BsNJf5T0qdREz4jDlv1Zs9W8t96s4zYlSYahxZS_eZgz4pMadxcESW8PPPU-dBxme5SGXSm6ZGPtn0-lwlw7CJClamXKWqY2vE_JMjVjwk4pDUWDQSQaEtjNYCqMvUVZmp6WeyTUfct3aCIgZOSYCIcZIu1cLZMcx8KRWyp4xWWDTuLgzwDXI6NyP70oBTAB; "
    "kwfv1=PeDA80mSG00ZF8e400wnrU+fr78fLAwn+f+erh8nz0Pfbf+fbS8e8f+erEGA40+epf+nbS8emSP0cMGfb08Bbf8eq9GAG98/rIPeqMPn+Y8/WF8/mDG/rMPeDMG/LhGfP7+0qMGfP9w/rAwBrAPADlP/zjPAcM+0c7GA80wBrFPeG=; "
    "kwpsecproductname=PCLive; "
    "userId=1690988111; "
    "showFollowRedIcon=1"
)


# =============== 2) MySQL ===============
DB_CFG = dict(
    host="localhost",
    user="5grc",
    password="f6eca7806e73cd25",
    database="5grc",
    port=3306,
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
)

VENUES_TBL = "venues"
VEHICLES_TBL = "vehicles"
URL_FIELD = "live_stream_url"
SHOW_FIELD = "show_live_stream"
BIND_FIELD = "bind_site"
VEHICLE_STATUS_FIELD = "status"

ONLINE_STATUSES = (
    "在线", "占有", "占用", "使用中", "忙碌", "运行中",
    "online", "occupied", "busy", "in_use", "running"
)

# =============== 3) HTTP ===============
UA_HEADERS = {
    "User-Agent": ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                   "AppleWebKit/537.36 (KHTML, like Gecko) "
                   "Chrome/141.0.0.0 Safari/537.36"),
    "Accept-Language": "zh-CN,zh;q=0.9",
    "Referer": "https://live.kuaishou.com/",
}
FOLLOW_LIVING_URL = "https://live.kuaishou.com/live_api/follow/living"

def build_session(cookie_string: Optional[str] = None) -> requests.Session:
    from urllib3.util.retry import Retry
    from requests.adapters import HTTPAdapter
    s = requests.Session()
    s.trust_env = False
    s.headers.update(UA_HEADERS)
    s.cookies.set("kpn", "GAME_ZONE", domain=".kuaishou.com")
    if cookie_string:
        for kv in cookie_string.split(";"):
            if "=" in kv:
                k, v = kv.split("=", 1)
                s.cookies.set(k.strip(), v.strip(), domain=".kuaishou.com")
    try:
        retries = Retry(total=2, connect=2, read=2, backoff_factor=0.3,
                        status_forcelist=[429, 500, 502, 503, 504],
                        allowed_methods=frozenset(["HEAD", "GET", "OPTIONS"]))
    except TypeError:
        retries = Retry(total=2, connect=2, read=2, backoff_factor=0.3,
                        status_forcelist=[429, 500, 502, 503, 504],
                        method_whitelist=frozenset(["HEAD", "GET", "OPTIONS"]))
    s.mount("https://", HTTPAdapter(max_retries=retries))
    s.mount("http://", HTTPAdapter(max_retries=retries))
    return s

def _http_get(session: requests.Session, url: str) -> Optional[str]:
    try:
        r = session.get(url, timeout=(5, 10), allow_redirects=True)
        if r.status_code == 200:
            return r.text
    except Exception:
        pass
    return None

# =============== 4) 获取两套集合：id & originUserId ===============
def get_following_live_sets(session: requests.Session) -> Tuple[Set[str], Set[str], str]:
    """
    返回 (live_ids, live_origin_ids, reason)
      - live_ids:        author.id  （字母/数字混合，如 'spll83869'）
      - live_origin_ids: originUserId（纯数字，转为 str）
    """
    text = _http_get(session, FOLLOW_LIVING_URL)
    if not text:
        return set(), set(), "follow/living 访问失败或未登录/被风控"

    ids: Set[str] = set()
    oids: Set[str] = set()

    # 1) JSON 解析
    try:
        data = requests.models.complexjson.loads(text)
        lst = None
        for key in ("livingList", "list", "feeds", "items", "result"):
            if isinstance(data.get(key), list):
                lst = data[key]; break
        if isinstance(lst, list):
            for item in lst:
                author = (item or {}).get("author") or {}
                _id = (author.get("id") or "").strip()
                _oid = author.get("originUserId")
                if _id:
                    ids.add(_id)
                if _oid is not None:
                    oids.add(str(_oid))
            if ids or oids:
                return ids, oids, "JSON 命中 {} / {} 个".format(len(ids), len(oids))
    except Exception:
        pass

    # 2) 正则兜底
    for m in re.finditer(r'"author"\s*:\s*{[^}]*?"id"\s*:\s*"([^"]+)"', text, re.S):
        ids.add(m.group(1))
    for m in re.finditer(r'"originUserId"\s*:\s*(\d+)', text):
        oids.add(m.group(1))
    if ids or oids:
        return ids, oids, "REGEX 命中 {} / {} 个".format(len(ids), len(oids))

    return set(), set(), "已登录但当前无人直播或结构未命中"

# =============== 5) DB ===============
def has_online_vehicle(conn, venue_id: int) -> bool:
    placeholders = ", ".join(["%s"] * len(ONLINE_STATUSES))
    sql = f"""
        SELECT 1
        FROM {VEHICLES_TBL} t
        WHERE t.{BIND_FIELD} = CAST(%s AS CHAR)
          AND t.{VEHICLE_STATUS_FIELD} IN ({placeholders})
        LIMIT 1
    """
    with conn.cursor() as cur:
        cur.execute(sql, (venue_id, *ONLINE_STATUSES))
        return cur.fetchone() is not None

def fetch_batch(conn, last_id: int, limit: int = 200):
    sql = f"""
      SELECT v.id, v.{URL_FIELD} AS url
      FROM {VENUES_TBL} v
      WHERE v.id > %s
      ORDER BY v.id ASC
      LIMIT %s
    """
    with conn.cursor() as cur:
        cur.execute(sql, (last_id, limit))
        return cur.fetchall()

def update_url_to_kwai_schema(conn, row_id: int, slug: str):
    new_url = f"kwai://profile/{slug}"
    sql = f"UPDATE {VENUES_TBL} SET {URL_FIELD}=%s WHERE id=%s"
    with conn.cursor() as cur:
        cur.execute(sql, (new_url, row_id))
    conn.commit()

def set_live_flag(conn, row_id: int, is_live: bool):
    sql = f"UPDATE {VENUES_TBL} SET {SHOW_FIELD}=%s WHERE id=%s"
    with conn.cursor() as cur:
        cur.execute(sql, (1 if is_live else 0, row_id))
    conn.commit()

# =============== 6) URL -> slug（同时支持数字/非数字；排除抖音） ===============
def is_kuaishou_link(s: str) -> bool:
    s_low = (s or "").lower().strip()
    if s_low.startswith("snssdk1128://"):  # 抖音，直接忽略
        return False
    return ("kwai://" in s_low) or ("kuaishou.com" in s_low)

def extract_slug_any(url: str) -> Optional[str]:
    """支持 kwai://profile/<slug>、/u/<slug>、/profile/<slug>；slug 可为数字或字母数字。"""
    if not url:
        return None
    s = url.strip()
    if not is_kuaishou_link(s):
        return None
    m = re.search(r'^kwai://profile/([^/?#]+)$', s, re.I)
    if m: return m.group(1)
    m = re.search(r'https?://(?:www\.)?live\.kuaishou\.com/u/([^/?#]+)', s, re.I)
    if m: return m.group(1)
    m = re.search(r'https?://(?:www\.)?kuaishou\.com/profile/([^/?#]+)', s, re.I)
    if m: return m.group(1)
    # 兜底：最后一段
    last = s.rstrip("/").split("/")[-1]
    return last or None

# =============== 7) 主流程 ===============
def main(batch_size: int = 200, cookie_string: Optional[str] = None):
    sess = build_session(cookie_string)
    live_ids, live_origin_ids, why = get_following_live_sets(sess)
    print(f"[follow/living] {why}；id集合={len(live_ids)}，origin集合={len(live_origin_ids)}")

    if not (live_ids or live_origin_ids) and "访问失败" in why:
        print("⚠️ follow/living 访问失败，可能 Cookie 失效/风控。本次不写库避免误判。")
        return

    conn = pymysql.connect(**DB_CFG)
    last_id, processed = 0, 0
    try:
        while True:
            rows = fetch_batch(conn, last_id, batch_size)
            if not rows:
                print("完成：没有更多需要检测的记录。")
                break

            for r in rows:
                row_id = r["id"]; raw = r["url"]; last_id = row_id

                # 只在有在线/占用车辆时才判定
                if not has_online_vehicle(conn, row_id):
                    print(f"[{row_id}] 无在线/占用车辆 -> 跳过")
                    continue

                slug = extract_slug_any(raw)
                if not slug:
                    print(f"[{row_id}] 非快手/无法解析 -> 跳过：{raw}")
                    continue

                # 标准化回写
                if not (raw or "").startswith("kwai://profile/"):
                    update_url_to_kwai_schema(conn, row_id, slug)
                    print(f"[{row_id}] 标准化为 kwai://profile/{slug}")

                # 判定：数字 -> origin 集合；非数字 -> id 集合
                if slug.isdigit():
                    is_live = slug in live_origin_ids
                    print(f"[{row_id}] {slug} -> 直播={is_live}（基于 originUserId 集合）")
                else:
                    is_live = slug in live_ids
                    print(f"[{row_id}] {slug} -> 直播={is_live}（基于 author.id 集合）")

                set_live_flag(conn, row_id, is_live)

                processed += 1
                time.sleep(0.05 + random.random() * 0.1)
    finally:
        conn.close()
    print(f"共处理 {processed} 条。")

if __name__ == "__main__":
    main(batch_size=200, cookie_string=COOKIE_STRING or None)
