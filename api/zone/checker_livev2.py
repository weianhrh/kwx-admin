# -*- coding: utf-8 -*-
"""
仅用 https://live.kuaishou.com/live_api/follow/living 判定是否直播（快手，原逻辑保持不变）
+ 新增：哔哩哔哩直播间检测（不影响快手）
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
    "did=web_343cf8021ef246cf0ece5066db96c104; "
    "clientid=3; "
    "client_key=65890b29; "
    "kpn=GAME_ZONE; "
    "apdid=cb8f9a55-d1a9-4183-9e52-5b22a343dde3bcbaec08ce4cd92b6092d472b36717c7:1745238508:1; "
    "userId=1690988111; "
    "kuaishou.live.bfb1s=3e261140b0cf7444a0ba411c6f227d88; "
    "didv=1761271869182; "
    "showFollowRedIcon=1; "
    "kwpsecproductname=PCLive; "
    "kuaishou.live.web_st=ChRrdWFpc2hvdS5saXZlLndlYi5zdBKgAaqBdtPRvTK-7Fw7M0KkfmwwYHTI2mBtIsRJ0p9GPbi9FBMJG-6y3GJ7gdr_jCbvmbazvS1rl7oXbUvrGTYlXQ_g8agcQBX0bWiEyxVjRLgvQx23bYNhuIHAHIeh-uTtc13S5bl6B-VFYBz0PKr2zOMi70NxUS1NycIG6h127-T5SdxJ0VY_DEpyD4gS7tBi-IQsLqPry0dFlZiY-tdVtqcaEpwewD8CakIZrC7t8JEKqqml3iIgw8s04c8kCKxPyG-3l7SO4MvNtsjx8h9jWeHSf1T2xFQoBTAB; "
    "kuaishou.live.web_ph=7eb7d3458d3d4e1bf7e867ec75c4958c0fb4; "
    "kwssectoken=OeW/WqaESDMAWaEWuYvU7/AHt+K96KUg1jZO5ue2mHBkeO56TeLcxjNam0oW+Ry1CT2MqIHFHSoflNdx3iNr5A==; "
    "kwscode=44f52808927f2e0c07c9b448cd2a6c5a85dbd3211fa1cc694bacb26346fa59af; "
    "kwfv1=PnGU+9+Y8008S+nH0U+0mjPf8fP08f+98f+nLlwnrIP9+Sw/ZFGfzY+eGlGf+f+e4SGfbYP0QfGnLFwBLU80mYGAYYGfPhwBpS+9pD8ncIGAQS+/SDG0WFPnpfG/pf80pjP0q9+fGI+nGAGfPU+0HMGnP9PBHMwBPhP9chP9zYGAW="
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

# =============== 3) HTTP（保持你原来的快手头部不变） ===============
UA_HEADERS = {
    "User-Agent": ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                   "AppleWebKit/537.36 (KHTML, like Gecko) "
                   "Chrome/141.0.0.0 Safari/537.36"),
    "Accept-Language": "zh-CN,zh;q=0.9",
    "Referer": "https://live.kuaishou.com/",
}
FOLLOW_LIVING_URL = "https://live.kuaishou.com/live_api/follow/living"

# ==== BILI ADD: API 端点（只用于 B 站） ====
BILI_GET_INFO = "https://api.live.bilibili.com/room/v1/Room/get_info?room_id={rid}"
BILI_ROOM_INIT = "https://api.live.bilibili.com/room/v1/Room/room_init?id={rid}"

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

def _http_get_text(session: requests.Session, url: str) -> Optional[str]:
    try:
        r = session.get(url, timeout=(5, 10), allow_redirects=True)
        if r.status_code == 200:
            return r.text
    except Exception:
        pass
    return None

# ==== BILI ADD: 通用 GET（需要拿到响应对象时用） ====
def _http_get(session: requests.Session, url: str, allow_redirects: bool = True) -> Optional[requests.Response]:
    try:
        return session.get(url, timeout=(5, 10), allow_redirects=allow_redirects)
    except Exception:
        return None

# =============== 4) 获取两套集合：id & originUserId（快手原逻辑） ===============
def get_following_live_sets(session: requests.Session) -> Tuple[Set[str], Set[str], str]:
    text = _http_get_text(session, FOLLOW_LIVING_URL)
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

# =============== 5) DB（原样） ===============
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

# =============== 6) URL -> slug（快手原样） ===============
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
    last = s.rstrip("/").split("/")[-1]
    return last or None

# ==== BILI ADD: 识别/解析/查询 ====
def is_bilibili_link(s: str) -> bool:
    s_low = (s or "").lower().strip()
    return ("bilibili://" in s_low) or ("live.bilibili.com" in s_low) or ("b23.tv" in s_low)

def extract_bili_roomid_any(session: requests.Session, url: str) -> Optional[str]:
    """支持 bilibili://live/{id}、https://live.bilibili.com/{id}、b23.tv 短链；返回房间号"""
    if not url:
        return None
    s = url.strip()

    m = re.search(r'^bilibili://live/(\d+)$', s, re.I)
    if m:
        return m.group(1)

    m = re.search(r'https?://(?:www\.)?live\.bilibili\.com/(\d+)', s, re.I)
    if m:
        return m.group(1)

    if "b23.tv" in s.lower():
        r = _http_get(session, s, allow_redirects=True)
        if r is not None:
            final = r.url or ""
            m2 = re.search(r'https?://(?:www\.)?live\.bilibili\.com/(\d+)', final, re.I)
            if m2:
                return m2.group(1)

    last = s.rstrip("/").split("/")[-1]
    return last if last.isdigit() else None

def bili_resolve_roomid(session: requests.Session, rid: str) -> Optional[str]:
    r = _http_get(session, BILI_ROOM_INIT.format(rid=rid))
    if r and r.status_code == 200:
        try:
            data = r.json().get("data") or {}
            real_id = data.get("room_id")
            if real_id:
                return str(real_id)
        except Exception:
            pass
    return None

def bili_is_live(session: requests.Session, rid: str) -> Tuple[bool, Optional[int]]:
    r = _http_get(session, BILI_GET_INFO.format(rid=rid))
    if not r or r.status_code != 200:
        return False, None
    try:
        data = r.json().get("data") or {}
        status = int(data.get("live_status", -1))
        return (status == 1), status
    except Exception:
        return False, None

# =============== 7) 主流程（保持快手原流程；仅在非快手链接时做 B站分支） ===============
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
                row_id = r["id"]; raw = (r["url"] or ""); last_id = row_id

                # 只在有在线/占用车辆时才判定
                if not has_online_vehicle(conn, row_id):
                    print(f"[{row_id}] 无在线/占用车辆 -> 跳过")
                    continue

                # ==== BILI ADD: 若是 B 站链接，单独检测 ====
                if is_bilibili_link(raw):
                    room_id = extract_bili_roomid_any(sess, raw)
                    if not room_id:
                        print(f"[{row_id}] Bili 解析 room_id 失败 -> 跳过：{raw}")
                        continue
                    real_id = bili_resolve_roomid(sess, room_id) or room_id
                    is_live, status = bili_is_live(sess, real_id)
                    set_live_flag(conn, row_id, is_live)
                    print(f"[{row_id}] Bili room_id={real_id} -> live_status={status} -> 直播={is_live}")
                    processed += 1
                    time.sleep(0.05 + random.random() * 0.1)
                    continue  # 不影响快手逻辑

                # ====== 原来的快手逻辑（未改动）======
                slug = extract_slug_any(raw)
                if not slug:
                    print(f"[{row_id}] 非快手/无法解析 -> 跳过：{raw}")
                    continue

                # 标准化回写（仍只处理快手）
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
