#!/usr/bin/env python3
"""集邮记 - Emby监控程序(安全重构同步版)"""
import os, sys, json, time, logging, argparse, socket
import requests
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict, field
from concurrent.futures import ThreadPoolExecutor, as_completed

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s', handlers=[logging.FileHandler('monitor.log', encoding='utf-8'), logging.StreamHandler()])
logger = logging.getLogger(__name__)
USER_AGENT = 'Hills/1.0.0'

@dataclass
class EmbyServer:
    id: int; name: str; url: str; token: str; check_interval: int = 60; use_custom_interval: int = 0
    _last_check_time: float = field(default=0.0, repr=False)

@dataclass
class MonitorConfig:
    monitor_id: int; api_url: str; token: str; servers: List[EmbyServer]; config: Dict[str, Any]

class EmbyMonitor:
    def __init__(self, config_path: str = "config.json"):
        self.config_path = config_path; self.config = None; self.running = True; self.hostname = socket.gethostname(); self.ip = self._get_local_ip()

    def _get_local_ip(self) -> str:
        try: s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.connect(('8.8.8.8', 80)); ip = s.getsockname()[0]; s.close(); return ip
        except: return '127.0.0.1'

    def load_config(self) -> bool:
        if not os.path.exists(self.config_path): logger.error(f"配置文件不存在"); return False
        try:
            with open(self.config_path, 'r', encoding='utf-8') as f: data = json.load(f)
            servers = [EmbyServer(**{k: v for k, v in s.items() if not k.startswith('_')}) for s in data.get('servers', [])]
            self.config = MonitorConfig(monitor_id=data.get('monitor_id', 0), api_url=data.get('api_url', ''), token=data.get('token', ''), servers=servers, config=data.get('config', {}))
            return True
        except Exception as e: logger.error(f"加载配置失败: {e}"); return False

    def fetch_remote_config(self) -> bool:
        if not self.config: return False
        try:
            r = requests.get(f"{self.config.api_url}?action=config", headers={'X-Monitor-Token': self.config.token, 'User-Agent': USER_AGENT}, timeout=30)
            if r.status_code != 200: logger.error(f"拉取配置失败: HTTP {r.status_code}"); return False
            data = r.json()
            if not data.get('success'): logger.error(f"拉取配置失败: {data.get('error')}"); return False
            servers = []
            for s in data.get('servers', []):
                old = next((x for x in self.config.servers if x.id == s.get('id')), None)
                server = EmbyServer(**{k: v for k, v in s.items() if not k.startswith('_')})
                if old: server._last_check_time = old._last_check_time
                servers.append(server)
            self.config.servers = servers; self.config.config = data.get('config', {})
            self.save_config(); logger.info(f"拉取配置成功，{len(servers)}个服务器"); return True
        except Exception as e: logger.error(f"拉取配置异常: {e}"); return False

    def save_config(self):
        if not self.config: return
        servers_data = [{k: v for k, v in asdict(s).items() if not k.startswith('_')} for s in self.config.servers]
        data = {'monitor_id': self.config.monitor_id, 'api_url': self.config.api_url, 'token': self.config.token, 'servers': servers_data, 'config': self.config.config}
        with open(self.config_path, 'w', encoding='utf-8') as f: json.dump(data, f, ensure_ascii=False, indent=2)

    def check_server(self, server: EmbyServer) -> Dict[str, Any]:
        result = {'server_id': server.id, 'is_online': False, 'response_time': None, 'library_count': None, 'library_details': None, 'item_counts': None, 'error_message': None}
        if not server.token: result['error_message'] = "主站未下发有效 Token"; return result
        start = time.time()
        try:
            # 核心变更：不再调用密码登录接口，直接使用主站下发的官方通信令牌建立请求
            headers = {'X-Emby-Token': server.token, 'User-Agent': USER_AGENT}
            r = requests.get(f"{server.url.rstrip('/')}/System/Info", headers=headers, timeout=30)
            result['response_time'] = int((time.time() - start) * 1000)
            if r.status_code == 200:
                result['is_online'] = True
                try:
                    cr = requests.get(f"{server.url.rstrip('/')}/Items/Counts", headers=headers, timeout=30)
                    if cr.status_code == 200:
                        counts = cr.json(); result['item_counts'] = json.dumps(counts)
                        types = ['MovieCount','SeriesCount','EpisodeCount','ArtistCount','AlbumCount','SongCount','MusicVideoCount','BoxSetCount','BookCount']
                        result['library_count'] = sum(1 for t in types if counts.get(t, 0) > 0)
                        details = []
                        if counts.get('MovieCount',0): details.append(f"电影:{counts['MovieCount']}")
                        if counts.get('SeriesCount',0): details.append(f"剧集:{counts['SeriesCount']}")
                        if counts.get('EpisodeCount',0): details.append(f"单集:{counts['EpisodeCount']}")
                        result['library_details'] = ', '.join(details)
                except Exception as e: logger.warning(f"获取计数失败: {e}")
            elif r.status_code == 401: result['error_message'] = "Token失效"
            else: result['error_message'] = f"HTTP {r.status_code}"
        except requests.exceptions.Timeout: result['error_message'] = "超时"; result['response_time'] = 30000
        except requests.exceptions.ConnectionError: result['error_message'] = "连接失败"
        except Exception as e: result['error_message'] = str(e)
        return result

    def report_result(self, result: Dict[str, Any]) -> bool:
        if not self.config: return False
        try:
            r = requests.post(f"{self.config.api_url}?action=report", headers={'X-Monitor-Token': self.config.token, 'Content-Type': 'application/json', 'User-Agent': USER_AGENT}, json=result, timeout=30)
            return r.status_code == 200 and r.json().get('success', False)
        except Exception as e: logger.error(f"上报异常: {e}"); return False

    def run_check(self):
        if not self.config or not self.config.servers: return
        current_time = time.time()
        global_interval = int(self.config.config.get('default_check_interval', 60))
        servers_to_check = []
        for server in self.config.servers:
            interval = server.check_interval if server.use_custom_interval else global_interval
            if current_time - server._last_check_time >= interval: servers_to_check.append(server)
        if not servers_to_check: return
        logger.info(f"检查 {len(servers_to_check)}/{len(self.config.servers)} 个服务器 (全局间隔:{global_interval}s)")
        oc = of = 0
        with ThreadPoolExecutor(max_workers=5) as ex:
            futures = {ex.submit(self.check_server, s): s for s in servers_to_check}
            for f in as_completed(futures):
                s = futures[f]
                try:
                    r = f.result(); s._last_check_time = time.time()
                    if r['is_online']: oc += 1
                    else: of += 1
                    if self.report_result(r): logger.info(f"[{s.name}] {'在线' if r['is_online'] else '离线'}, {r['response_time']}ms, 媒体库:{r['library_count']}")
                except Exception as e: logger.error(f"检查 {s.name} 失败: {e}"); of += 1
        if servers_to_check: logger.info(f"完成: 在线{oc} 离线{of}")

    def run(self):
        logger.info(f"集邮记 启动 - {self.hostname}")
        if not self.load_config(): return
        last_fetch = 0
        while self.running:
            try:
                if time.time() - last_fetch > 300:
                    if self.fetch_remote_config(): last_fetch = time.time()
                self.run_check()
                for _ in range(10):
                    if not self.running: break
                    time.sleep(1)
            except KeyboardInterrupt: break
            except Exception as e: logger.error(f"循环异常: {e}"); time.sleep(60)
        logger.info("已停止")

def setup_wizard():
    print("集邮记 - 配置向导")
    config = {}
    config['api_url'] = input("API地址: ").strip()
    config['token'] = input("Token: ").strip()
    print("测试连接...")
    try:
        r = requests.get(f"{config['api_url']}?action=config", headers={'X-Monitor-Token': config['token'], 'User-Agent': USER_AGENT}, timeout=30)
        if r.status_code == 200 and r.json().get('success'):
            d = r.json(); config['monitor_id'] = d.get('monitor_id', 0); config['servers'] = d.get('servers', []); config['config'] = d.get('config', {})
            print(f"成功! ID:{config['monitor_id']} 服务器:{len(config['servers'])}")
        else: print("失败")
    except Exception as e: print(f"异常: {e}")
    with open('config.json', 'w', encoding='utf-8') as f: json.dump(config, f, ensure_ascii=False, indent=2)
    print("已保存")

def main():
    p = argparse.ArgumentParser(description='集邮记')
    p.add_argument('cmd', choices=['setup','run','check','status']); p.add_argument('-c','--config', default='config.json')
    args = p.parse_args()
    m = EmbyMonitor(args.config)
    if args.cmd == 'setup': setup_wizard()
    elif args.cmd == 'run': m.run()
    elif args.cmd == 'check':
        if m.load_config(): m.run_check()
    elif args.cmd == 'status':
        if m.load_config():
            print(f"ID:{m.config.monitor_id} 服务器:{len(m.config.servers)}")
            gi = int(m.config.config.get('default_check_interval', 60))
            for s in m.config.servers:
                it = s.check_interval if s.use_custom_interval else gi
                print(f"  {s.name}: {s.url} (间隔:{it}s)")

if __name__ == '__main__': main()