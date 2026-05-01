#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
集邮记 - Emby监控程序
使用用户名密码获取 Token，UA: Hills/1.0.0
"""

import os
import sys
import json
import time
import logging
import argparse
import socket
import requests
from datetime import datetime
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor, as_completed

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('monitor.log', encoding='utf-8'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

USER_AGENT = 'Hills/1.0.0'


@dataclass
class EmbyServer:
    id: int
    name: str
    url: str
    username: str
    password: str
    check_interval: int = 60
    _token: Optional[str] = None


@dataclass
class MonitorConfig:
    monitor_id: int
    api_url: str
    token: str
    servers: List[EmbyServer]
    config: Dict[str, Any]


class EmbyMonitor:
    
    def __init__(self, config_path: str = "config.json"):
        self.config_path = config_path
        self.config: Optional[MonitorConfig] = None
        self.running = True
        self.hostname = socket.gethostname()
        self.ip = self._get_local_ip()
        
    def _get_local_ip(self) -> str:
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
            s.close()
            return ip
        except:
            return '127.0.0.1'
    
    def load_config(self) -> bool:
        if not os.path.exists(self.config_path):
            logger.error(f"配置文件不存在: {self.config_path}")
            return False
        try:
            with open(self.config_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            servers = [EmbyServer(**s) for s in data.get('servers', [])]
            self.config = MonitorConfig(
                monitor_id=data.get('monitor_id', 0),
                api_url=data.get('api_url', ''),
                token=data.get('token', ''),
                servers=servers,
                config=data.get('config', {})
            )
            return True
        except Exception as e:
            logger.error(f"加载配置失败: {e}")
            return False
    
    def fetch_remote_config(self) -> bool:
        if not self.config:
            return False
        try:
            response = requests.get(
                f"{self.config.api_url}?action=config",
                headers={'X-Monitor-Token': self.config.token, 'User-Agent': USER_AGENT},
                timeout=30
            )
            if response.status_code != 200:
                logger.error(f"拉取配置失败: HTTP {response.status_code}")
                return False
            data = response.json()
            if not data.get('success'):
                logger.error(f"拉取配置失败: {data.get('error')}")
                return False
            servers = []
            for s in data.get('servers', []):
                old_server = next((x for x in self.config.servers if x.id == s.get('id')), None)
                server = EmbyServer(**s)
                if old_server and old_server._token:
                    server._token = old_server._token
                servers.append(server)
            self.config.monitor_id = data.get('monitor_id', self.config.monitor_id)
            self.config.servers = servers
            self.config.config = data.get('config', {})
            self.save_config()
            logger.info(f"成功拉取配置，共 {len(servers)} 个服务器")
            return True
        except Exception as e:
            logger.error(f"拉取配置异常: {e}")
            return False
    
    def save_config(self):
        if not self.config:
            return
        servers_data = []
        for s in self.config.servers:
            s_dict = asdict(s)
            if '_token' in s_dict:
                del s_dict['_token']
            servers_data.append(s_dict)
        data = {
            'monitor_id': self.config.monitor_id,
            'api_url': self.config.api_url,
            'token': self.config.token,
            'servers': servers_data,
            'config': self.config.config
        }
        with open(self.config_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
    
    def _get_access_token(self, server: EmbyServer) -> Optional[str]:
        if server._token:
            return server._token
        try:
            auth_url = f"{server.url.rstrip('/')}/Users/AuthenticateByName"
            auth_header = 'Emby UserId="", Client="Hills", Device="Hills Monitor", DeviceId="HillsMonitor", Version="1.0.0"'
            payload = {"Username": server.username, "Pw": server.password}
            headers = {
                'Content-Type': 'application/json',
                'X-Emby-Authorization': auth_header,
                'User-Agent': USER_AGENT
            }
            response = requests.post(auth_url, json=payload, headers=headers, timeout=30)
            if response.status_code == 200:
                data = response.json()
                token = data.get('AccessToken')
                if token:
                    server._token = token
                    logger.info(f"服务器 [{server.name}] 获取 Token 成功")
                    return token
            else:
                logger.error(f"服务器 [{server.name}] 获取 Token 失败: HTTP {response.status_code}")
        except Exception as e:
            logger.error(f"服务器 [{server.name}] 获取 Token 异常: {e}")
        return None
    
    def check_server(self, server: EmbyServer) -> Dict[str, Any]:
        result = {
            'server_id': server.id,
            'is_online': False,
            'response_time': None,
            'library_count': None,
            'library_details': None,
            'item_counts': None,
            'error_message': None
        }
        token = self._get_access_token(server)
        if not token:
            result['error_message'] = "获取访问令牌失败"
            return result
        
        start_time = time.time()
        try:
            system_url = f"{server.url.rstrip('/')}/System/Info"
            headers = {'X-Emby-Token': token, 'User-Agent': USER_AGENT}
            response = requests.get(system_url, headers=headers, timeout=30)
            result['response_time'] = int((time.time() - start_time) * 1000)
            
            if response.status_code == 200:
                result['is_online'] = True
                try:
                    counts_url = f"{server.url.rstrip('/')}/Items/Counts"
                    counts_response = requests.get(counts_url, headers=headers, timeout=30)
                    if counts_response.status_code == 200:
                        counts_data = counts_response.json()
                        result['item_counts'] = json.dumps(counts_data)
                        library_types = ['MovieCount', 'SeriesCount', 'EpisodeCount', 'ArtistCount', 'AlbumCount', 'SongCount', 'MusicVideoCount', 'BoxSetCount', 'BookCount']
                        total_libraries = sum(1 for t in library_types if counts_data.get(t, 0) > 0)
                        result['library_count'] = total_libraries
                        details = []
                        if counts_data.get('MovieCount', 0) > 0: details.append(f"电影:{counts_data['MovieCount']}")
                        if counts_data.get('SeriesCount', 0) > 0: details.append(f"剧集:{counts_data['SeriesCount']}")
                        if counts_data.get('EpisodeCount', 0) > 0: details.append(f"单集:{counts_data['EpisodeCount']}")
                        if counts_data.get('SongCount', 0) > 0: details.append(f"歌曲:{counts_data['SongCount']}")
                        result['library_details'] = ', '.join(details)
                except Exception as e:
                    logger.warning(f"服务器 [{server.name}] 获取媒体计数失败: {e}")
            elif response.status_code == 401:
                result['error_message'] = "Token 失效"
                server._token = None
            else:
                result['error_message'] = f"HTTP {response.status_code}"
        except requests.exceptions.Timeout:
            result['error_message'] = "连接超时"
            result['response_time'] = 30000
        except requests.exceptions.ConnectionError:
            result['error_message'] = "连接失败"
        except Exception as e:
            result['error_message'] = str(e)
        return result
    
    def report_result(self, result: Dict[str, Any]) -> bool:
        if not self.config:
            return False
        try:
            response = requests.post(
                f"{self.config.api_url}?action=report",
                headers={'X-Monitor-Token': self.config.token, 'Content-Type': 'application/json', 'User-Agent': USER_AGENT},
                json=result,
                timeout=30
            )
            return response.status_code == 200 and response.json().get('success', False)
        except Exception as e:
            logger.error(f"上报异常: {e}")
            return False
    
    def run_check(self):
        if not self.config or not self.config.servers:
            return
        logger.info(f"开始检查 {len(self.config.servers)} 个服务器")
        online_count = offline_count = 0
        with ThreadPoolExecutor(max_workers=5) as executor:
            futures = {executor.submit(self.check_server, s): s for s in self.config.servers}
            for future in as_completed(futures):
                server = futures[future]
                try:
                    result = future.result()
                    if result['is_online']: online_count += 1
                    else: offline_count += 1
                    if self.report_result(result):
                        status = "在线" if result['is_online'] else "离线"
                        logger.info(f"服务器 [{server.name}] {status}, 响应: {result['response_time']}ms, 媒体库: {result['library_count']}个")
                except Exception as e:
                    logger.error(f"检查 {server.name} 失败: {e}")
                    offline_count += 1
        logger.info(f"检查完成: 在线 {online_count} 台, 离线 {offline_count} 台")
    
    def run(self):
        logger.info("=" * 50)
        logger.info("集邮记 Emby监控程序")
        logger.info(f"主机名: {self.hostname}, IP: {self.ip}")
        logger.info("=" * 50)
        if not self.load_config():
            logger.error("配置加载失败")
            return
        last_fetch = 0
        while self.running:
            try:
                if time.time() - last_fetch > 300:
                    if self.fetch_remote_config():
                        last_fetch = time.time()
                self.run_check()
                interval = int(self.config.config.get('default_check_interval', 60))
                for _ in range(interval):
                    if not self.running: break
                    time.sleep(1)
            except KeyboardInterrupt:
                break
            except Exception as e:
                logger.error(f"循环异常: {e}")
                time.sleep(60)
        logger.info("监控程序已停止")


def setup_wizard():
    print("=" * 50)
    print("集邮记 Emby监控程序 - 配置向导")
    print("=" * 50)
    config = {}
    config['api_url'] = input("管理面板API地址: ").strip()
    config['token'] = input("监控机认证令牌: ").strip()
    print("\n测试连接...")
    try:
        r = requests.get(f"{config['api_url']}?action=config", headers={'X-Monitor-Token': config['token'], 'User-Agent': USER_AGENT}, timeout=30)
        if r.status_code == 200 and r.json().get('success'):
            data = r.json()
            config['monitor_id'] = data.get('monitor_id', 0)
            config['servers'] = data.get('servers', [])
            config['config'] = data.get('config', {})
            print(f"✓ 成功! 监控机ID: {config['monitor_id']}, 服务器: {len(config['servers'])} 个")
        else:
            print(f"✗ 失败: {r.json().get('error', 'HTTP ' + str(r.status_code))}")
            return
    except Exception as e:
        print(f"✗ 连接异常: {e}")
        return
    with open('config.json', 'w', encoding='utf-8') as f:
        json.dump(config, f, ensure_ascii=False, indent=2)
    print("配置已保存到 config.json")


def main():
    parser = argparse.ArgumentParser(description='集邮记 Emby监控程序')
    parser.add_argument('command', choices=['setup', 'run', 'check', 'status'])
    parser.add_argument('-c', '--config', default='config.json')
    args = parser.parse_args()
    monitor = EmbyMonitor(args.config)
    if args.command == 'setup': setup_wizard()
    elif args.command == 'run': monitor.run()
    elif args.command == 'check':
        if monitor.load_config(): monitor.run_check()
    elif args.command == 'status':
        if monitor.load_config():
            print(f"监控机ID: {monitor.config.monitor_id}, 服务器: {len(monitor.config.servers)} 个")
            for s in monitor.config.servers:
                print(f"  {s.name}: {s.url}")


if __name__ == '__main__':
    main()
