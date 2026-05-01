#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
集邮记 - Emby监控程序
"""

import os
import sys
import json
import time
import logging
import argparse
import platform
import socket
import requests
from datetime import datetime
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict
from concurrent.futures import ThreadPoolExecutor, as_completed

# 配置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('monitor.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

@dataclass
class EmbyServer:
    """Emby服务器配置"""
    id: int
    name: str
    url: str
    api_key: str
    check_interval: int = 60

@dataclass
class MonitorConfig:
    """监控配置"""
    monitor_id: int
    api_url: str
    token: str
    servers: List[EmbyServer]
    config: Dict[str, Any]

class EmbyMonitor:
    """Emby监控器"""
    
    def __init__(self, config_path: str = "config.json"):
        self.config_path = config_path
        self.config: Optional[MonitorConfig] = None
        self.running = True
        self.hostname = socket.gethostname()
        self.ip = self._get_local_ip()
        
    def _get_local_ip(self) -> str:
        """获取本机IP"""
        try:
            s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            s.connect(('8.8.8.8', 80))
            ip = s.getsockname()[0]
            s.close()
            return ip
        except:
            return '127.0.0.1'
    
    def load_config(self) -> bool:
        """加载本地配置"""
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
        """从服务端拉取配置"""
        if not self.config:
            return False
            
        try:
            response = requests.get(
                f"{self.config.api_url}?action=config",
                headers={'X-Monitor-Token': self.config.token},
                timeout=30
            )
            
            if response.status_code != 200:
                logger.error(f"拉取配置失败: HTTP {response.status_code}")
                return False
                
            data = response.json()
            if not data.get('success'):
                logger.error(f"拉取配置失败: {data.get('error')}")
                return False
                
            # 更新配置
            servers = [EmbyServer(**s) for s in data.get('servers', [])]
            self.config.monitor_id = data.get('monitor_id', self.config.monitor_id)
            self.config.servers = servers
            self.config.config = data.get('config', {})
            
            # 保存到本地
            self.save_config()
            
            logger.info(f"成功拉取配置，共 {len(servers)} 个服务器")
            return True
            
        except Exception as e:
            logger.error(f"拉取配置异常: {e}")
            return False
    
    def save_config(self):
        """保存配置到本地"""
        if not self.config:
            return
            
        data = {
            'monitor_id': self.config.monitor_id,
            'api_url': self.config.api_url,
            'token': self.config.token,
            'servers': [asdict(s) for s in self.config.servers],
            'config': self.config.config
        }
        
        with open(self.config_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
    
    def check_server(self, server: EmbyServer) -> Dict[str, Any]:
        """检查单个Emby服务器"""
        result = {
            'server_id': server.id,
            'is_online': False,
            'response_time': None,
            'library_count': None,
            'library_details': None,
            'error_message': None
        }
        
        start_time = time.time()
        
        try:
            # 检查服务器状态
            system_url = f"{server.url.rstrip('/')}/System/Info"
            headers = {'X-Emby-Token': server.api_key}
            
            response = requests.get(
                system_url,
                headers=headers,
                timeout=30
            )
            
            result['response_time'] = int((time.time() - start_time) * 1000)
            
            if response.status_code == 200:
                result['is_online'] = True
                data = response.json()
                
                # 获取媒体库信息
                try:
                    library_url = f"{server.url.rstrip('/')}/Library/VirtualFolders"
                    lib_response = requests.get(library_url, headers=headers, timeout=30)
                    
                    if lib_response.status_code == 200:
                        libraries = lib_response.json()
                        result['library_count'] = len(libraries)
                        result['library_details'] = json.dumps([
                            {
                                'name': lib.get('Name'),
                                'type': lib.get('CollectionType'),
                                'locations': lib.get('Locations', [])
                            }
                            for lib in libraries
                        ])
                except Exception as e:
                    logger.warning(f"获取媒体库信息失败: {e}")
                    
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
        """上报监控结果"""
        if not self.config:
            return False
            
        try:
            response = requests.post(
                f"{self.config.api_url}?action=report",
                headers={
                    'X-Monitor-Token': self.config.token,
                    'Content-Type': 'application/json'
                },
                json=result,
                timeout=30
            )
            
            if response.status_code == 200:
                data = response.json()
                return data.get('success', False)
            else:
                logger.error(f"上报失败: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            logger.error(f"上报异常: {e}")
            return False
    
    def run_check(self):
        """执行一轮检查"""
        if not self.config:
            logger.error("配置未加载")
            return
            
        if not self.config.servers:
            logger.warning("没有配置要监控的服务器")
            return
            
        logger.info(f"开始检查 {len(self.config.servers)} 个服务器")
        
        # 使用线程池并发检查
        with ThreadPoolExecutor(max_workers=5) as executor:
            futures = {
                executor.submit(self.check_server, server): server
                for server in self.config.servers
            }
            
            for future in as_completed(futures):
                server = futures[future]
                try:
                    result = future.result()
                    
                    # 上报结果
                    if self.report_result(result):
                        status = "在线" if result['is_online'] else "离线"
                        lib_info = f", 媒体库: {result['library_count']}" if result['library_count'] else ""
                        logger.info(
                            f"服务器 [{server.name}] {status}, "
                            f"响应: {result['response_time']}ms{lib_info}"
                        )
                    else:
                        logger.error(f"上报结果失败: {server.name}")
                        
                except Exception as e:
                    logger.error(f"检查服务器 {server.name} 失败: {e}")
    
    def run(self):
        """运行监控循环"""
        logger.info("=" * 50)
        logger.info("集邮记 Emby监控程序启动")
        logger.info(f"主机名: {self.hostname}")
        logger.info(f"IP地址: {self.ip}")
        logger.info("=" * 50)
        
        # 加载配置
        if not self.load_config():
            logger.error("无法加载配置，请先运行 setup 命令")
            return
            
        last_config_fetch = 0
        config_fetch_interval = 300  # 5分钟拉取一次配置
        
        while self.running:
            try:
                current_time = time.time()
                
                # 定期拉取远程配置
                if current_time - last_config_fetch > config_fetch_interval:
                    logger.info("拉取远程配置...")
                    if self.fetch_remote_config():
                        last_config_fetch = current_time
                
                # 执行检查
                self.run_check()
                
                # 获取检查间隔
                interval = int(self.config.config.get('default_check_interval', 60))
                
                logger.info(f"等待 {interval} 秒后进行下一次检查...")
                
                # 分段等待，便于响应中断
                for _ in range(interval):
                    if not self.running:
                        break
                    time.sleep(1)
                    
            except KeyboardInterrupt:
                logger.info("收到中断信号，正在退出...")
                break
            except Exception as e:
                logger.error(f"监控循环异常: {e}")
                time.sleep(60)
                
        logger.info("监控程序已停止")

def setup_wizard():
    """配置向导"""
    print("=" * 50)
    print("集邮记 Emby监控程序 - 配置向导")
    print("=" * 50)
    
    config = {}
    
    # API地址
    config['api_url'] = input("请输入管理面板API地址 (例如: http://your-domain.com/api.php): ").strip()
    if not config['api_url']:
        print("API地址不能为空")
        return
        
    # 认证令牌
    config['token'] = input("请输入监控机认证令牌: ").strip()
    if not config['token']:
        print("认证令牌不能为空")
        return
        
    # 测试连接
    print("\n正在测试连接...")
    try:
        response = requests.get(
            f"{config['api_url']}?action=config",
            headers={'X-Monitor-Token': config['token']},
            timeout=30
        )
        
        if response.status_code == 200:
            data = response.json()
            if data.get('success'):
                print("✓ 连接成功!")
                config['monitor_id'] = data.get('monitor_id', 0)
                config['servers'] = data.get('servers', [])
                config['config'] = data.get('config', {})
                
                print(f"  监控机ID: {config['monitor_id']}")
                print(f"  服务器数量: {len(config['servers'])}")
            else:
                print(f"✗ 认证失败: {data.get('error')}")
                return
        else:
            print(f"✗ 连接失败: HTTP {response.status_code}")
            return
            
    except Exception as e:
        print(f"✗ 连接异常: {e}")
        return
        
    # 保存配置
    with open('config.json', 'w', encoding='utf-8') as f:
        json.dump(config, f, ensure_ascii=False, indent=2)
        
    print("\n配置已保存到 config.json")
    print("\n运行 'python monitor.py run' 启动监控程序")

def main():
    parser = argparse.ArgumentParser(description='集邮记 Emby监控程序')
    parser.add_argument('command', choices=['setup', 'run', 'check', 'status'],
                        help='命令: setup=配置向导, run=运行监控, check=执行一次检查, status=查看状态')
    parser.add_argument('-c', '--config', default='config.json',
                        help='配置文件路径 (默认: config.json)')
    
    args = parser.parse_args()
    
    monitor = EmbyMonitor(args.config)
    
    if args.command == 'setup':
        setup_wizard()
        
    elif args.command == 'run':
        monitor.run()
        
    elif args.command == 'check':
        if monitor.load_config():
            monitor.run_check()
            
    elif args.command == 'status':
        if monitor.load_config():
            print(f"监控机ID: {monitor.config.monitor_id}")
            print(f"API地址: {monitor.config.api_url}")
            print(f"服务器数量: {len(monitor.config.servers)}")
            print("\n监控的服务器:")
            for s in monitor.config.servers:
                print(f"  - {s.name}: {s.url}")

if __name__ == '__main__':
    main()
