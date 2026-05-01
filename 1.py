import asyncio
import re
import logging
from typing import Dict, Optional, Tuple, List
from dataclasses import dataclass
import aiohttp
from telethon import TelegramClient, events
from telethon.tl.types import MessageMediaDocument
from config import API_ID, API_HASH, API_AUTH_TOKEN, PHONE_NUMBER, API_BASE_URL, SOURCE_CHANNEL, TARGET_BOT

# 配置日志
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('telegram_bot.log', encoding='utf-8'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

@dataclass
class VideoInfo:
    """视频信息数据类"""
    original_message_id: int
    forwarded_message_id: int
    description: str = ""
    title: str = ""
    season: int = 1
    episode: int = 1
    matched_item_type: str = ""
    matched_item_id: int = 0
    status: str = "pending"  # pending, searching, sending_id, submitting, completed, cancelled
    retry_count: int = 0

class TelegramAutoForwarder:
    def __init__(self):
        """初始化自动转发器"""
        # Telegram客户端
        self.client = TelegramClient(f'session_{PHONE_NUMBER}', API_ID, API_HASH)
        
        # 配置参数
        self.source_channel = SOURCE_CHANNEL  # 源频道
        self.target_bot = TARGET_BOT  # 目标机器人
        
        # API配置
        self.api_base_url = API_BASE_URL
        self.headers = {'Authorization': API_AUTH_TOKEN}
        
        # 存储转发消息的信息
        self.pending_videos: Dict[int, VideoInfo] = {}  # 待处理视频
        self.processing_tasks: Dict[int, asyncio.Task] = {}  # 处理任务
        self.processed_messages: set = set()  # 已处理的消息ID（避免重复处理）
        
        # 状态统计
        self.stats = {
            'total_processed': 0,
            'successful': 0,
            'cancelled': 0,
            'failed': 0,
            'retried': 0,
            'history_processed': 0,
            'new_processed': 0
        }
        
        # 配置参数
        self.wait_interval = 2  # 等待时间（秒）
        self.max_retries = 3    # 最大重试次数
        self.history_limit = 50  # 处理的历史消息数量限制
    
    def is_video_message(self, message) -> bool:
        """
        检测是否为视频消息
        
        Args:
            message: 消息对象
            
        Returns:
            是否为视频消息
        """
        try:
            # 检查是否有媒体
            if not message.media:
                return False
            
            # 检查是否为文档类型（视频属于文档类型）
            if not isinstance(message.media, MessageMediaDocument):
                return False
            
            # 检查是否为视频
            document = message.media.document
            is_video = False
            
            # 遍历文档属性，查找视频属性
            for attr in document.attributes:
                if hasattr(attr, 'video') and attr.video:
                    is_video = True
                    break
            
            return is_video
            
        except Exception as e:
            logger.error(f"检测视频消息时出错: {e}")
            return False
    
    async def extract_video_info(self, description: str) -> Tuple[str, int, int]:
        """
        从描述中提取视频信息
        
        Args:
            description: 视频描述文本
            
        Returns:
            (标题, 季数, 集数)
        """
        try:
            # 如果没有描述，返回空值
            if not description:
                logger.warning("描述文本为空")
                return "", 1, 1
            
            # 提取中文部分
            chinese_pattern = re.compile(r'([\u4e00-\u9fff]+(?:\s+[\u4e00-\u9fff]+)*)')
            chinese_matches = chinese_pattern.findall(description)
            
            if not chinese_matches:
                logger.warning(f"未找到中文标题: {description[:100]}...")
                return "", 1, 1
            
            # 获取主要中文标题（通常是最后一个连续中文部分）
            full_chinese = chinese_matches[-1]
            
            # 提取季数
            season = 1  # 默认第一季
            season_patterns = [
                (r'第\s*(\d+)\s*季', 1),
                (r'第\s*(\d+)\s*期', 1),
                (r'Season\s*(\d+)', 1),
                (r'S(\d+)', 1),
                (r'S(\d+)', 1)  # 英文大写S
            ]
            
            for pattern, group in season_patterns:
                match = re.search(pattern, full_chinese, re.IGNORECASE)
                if match:
                    try:
                        season = int(match.group(group))
                        # 从标题中移除季数信息
                        full_chinese = re.sub(pattern, '', full_chinese, flags=re.IGNORECASE).strip()
                        break
                    except (ValueError, IndexError):
                        continue
            
            # 提取集数
            episode = 1
            episode_patterns = [
                (r'-\s*(\d{1,3})\s*\[', 1),  # 格式: - 09 [
                (r'第\s*(\d{1,3})\s*集', 1),
                (r'EP\s*(\d+)', 1, re.IGNORECASE),
                (r'E(\d+)', 1, re.IGNORECASE),
                (r'-\s*(\d{1,3})\s*$', 1),  # 格式: - 09
                (r'\s+(\d{1,3})\s*\[', 1)   # 格式: 09 [
            ]
            
            for pattern_info in episode_patterns:
                if len(pattern_info) == 3:
                    pattern, group, flags = pattern_info
                else:
                    pattern, group = pattern_info
                    flags = 0
                
                match = re.search(pattern, description, flags)
                if match:
                    try:
                        episode = int(match.group(group))
                        break
                    except (ValueError, IndexError):
                        continue
            
            # 清理标题
            title = re.sub(r'[\[\]【】()（）\-—:：·~～]', ' ', full_chinese)
            title = re.sub(r'\s+', ' ', title).strip()
            
            # 移除常见的后缀
            remove_patterns = [
                r'字幕组$', r'压制组$', r'压制部$', r'奶茶屋$', 
                r'^\s*[|｜]\s*', r'\s*[|｜]\s*$', r'字幕社$'
            ]
            for pattern in remove_patterns:
                title = re.sub(pattern, '', title).strip()
            
            logger.info(f"提取信息: 标题='{title}', 季={season}, 集={episode}")
            
            return title, season, episode
            
        except Exception as e:
            logger.error(f"提取视频信息时出错: {e}")
            return "", 1, 1
    
    async def search_video_item(self, title: str, season: int, episode: int) -> Optional[Tuple[str, int]]:
        """
        通过API搜索视频资源
        
        Args:
            title: 视频标题
            season: 季数
            episode: 集数
            
        Returns:
            (item_type, item_id) 或 None
        """
        try:
            # 如果标题为空，直接返回None
            if not title:
                logger.warning("标题为空，无法搜索")
                return None
                
            search_title = f"{title} 第{season}季" if season > 1 else title
            
            logger.info(f"API搜索: {search_title} (S{season}E{episode})")
            
            async with aiohttp.ClientSession() as session:
                async with session.get(
                    f"{self.api_base_url}/api/video/getItemId",
                    params={"title": search_title},
                    headers=self.headers,
                    timeout=aiohttp.ClientTimeout(total=30)
                ) as response:
                    
                    if response.status != 200:
                        logger.error(f"API请求失败: {response.status}")
                        return None
                    
                    data = await response.json()
                    
                    if not data:
                        logger.warning(f"API返回空数据: {search_title}")
                        return None
                    
                    logger.info(f"API返回 {len(data)} 个结果")
                    
                    # 遍历所有结果
                    for item in data:
                        video_type = item.get("video_type")
                        
                        if video_type == "tv":
                            # 电视剧类型
                            seasons = item.get("seasons", [])
                            for season_data in seasons:
                                season_number = season_data.get("season_number")
                                if season_number == season:
                                    episodes = season_data.get("episodes", [])
                                    for ep_data in episodes:
                                        episode_number = ep_data.get("episode_number")
                                        if episode_number == episode:
                                            item_type = ep_data.get("item_type", "ve")
                                            item_id = ep_data.get("item_id")
                                            if item_type and item_id:
                                                logger.info(f"找到匹配: {item_type}-{item_id}")
                                                return item_type, item_id
                        elif video_type == "movie":
                            # 电影类型，检查是否匹配
                            item_type = item.get("item_type", "vl")
                            item_id = item.get("item_id")
                            if item_type and item_id:
                                logger.info(f"找到电影匹配: {item_type}-{item_id}")
                                return item_type, item_id
                    
                    logger.warning(f"未找到匹配的季/集: {title} S{season}E{episode}")
                    return None
                    
        except asyncio.TimeoutError:
            logger.error("API请求超时")
            return None
        except Exception as e:
            logger.error(f"搜索视频时出错: {e}")
            return None
    
    async def process_video_automatically(self, video_info: VideoInfo):
        """
        自动处理视频流程（不依赖机器人回复）
        
        Args:
            video_info: 视频信息对象
        """
        try:
            logger.info(f"开始自动处理视频: 转发消息ID={video_info.forwarded_message_id}")
            
            # 等待2秒，模拟机器人处理时间
            logger.info(f"等待 {self.wait_interval} 秒，模拟机器人处理...")
            await asyncio.sleep(self.wait_interval)
            
            # 步骤1: 搜索资源ID
            video_info.status = "searching"
            logger.info("步骤1: 搜索资源ID")
            
            result = await self.search_video_item(
                video_info.title, 
                video_info.season, 
                video_info.episode
            )
            
            if result:
                item_type, item_id = result
                video_info.matched_item_type = item_type
                video_info.matched_item_id = item_id
                
                # 步骤2: 发送资源ID给机器人
                video_info.status = "sending_id"
                logger.info(f"步骤2: 发送资源ID {item_type}-{item_id}")
                
                response_text = f"{item_type}-{item_id}"
                try:
                    # 获取原始转发消息
                    message = await self.client.get_messages(self.target_bot, ids=video_info.forwarded_message_id)
                    if message:
                        await message.reply(response_text)
                        logger.info(f"已发送资源ID: {response_text}")
                        
                        # 等待2秒
                        logger.info(f"等待 {self.wait_interval} 秒...")
                        await asyncio.sleep(self.wait_interval)
                        
                        # 步骤3: 发送/submit_video
                        video_info.status = "submitting"
                        logger.info("步骤3: 发送 /submit_video")
                        
                        await self.client.send_message(self.target_bot, "/submit_video", reply_to=video_info.forwarded_message_id)
                        logger.info("已发送 /submit_video")
                        
                        # 等待2秒
                        logger.info(f"等待 {self.wait_interval} 秒...")
                        await asyncio.sleep(self.wait_interval)
                        
                        # 步骤4: 标记为完成
                        video_info.status = "completed"
                        self.stats['successful'] += 1
                        
                        # 清理记录
                        if video_info.forwarded_message_id in self.pending_videos:
                            del self.pending_videos[video_info.forwarded_message_id]
                        if video_info.forwarded_message_id in self.processing_tasks:
                            del self.processing_tasks[video_info.forwarded_message_id]
                        
                        # 记录已处理的消息ID
                        self.processed_messages.add(video_info.original_message_id)
                        
                        logger.info(f"视频处理完成: 转发消息ID={video_info.forwarded_message_id}")
                    else:
                        logger.error(f"无法找到转发消息: {video_info.forwarded_message_id}")
                        self._handle_processing_error(video_info, "找不到转发消息")
                except Exception as e:
                    logger.error(f"发送资源ID时出错: {e}")
                    self._handle_processing_error(video_info, f"发送资源ID失败: {e}")
            else:
                # API没有返回数据，发送/cancel指令
                video_info.status = "cancelled"
                logger.info("未找到匹配资源，发送/cancel指令")
                
                try:
                    # 获取原始转发消息
                    message = await self.client.get_messages(self.target_bot, ids=video_info.forwarded_message_id)
                    if message:
                        await message.reply("/cancel")
                        logger.info("已发送 /cancel 指令")
                        
                        # 等待2秒
                        logger.info(f"等待 {self.wait_interval} 秒...")
                        await asyncio.sleep(self.wait_interval)
                        
                        # 更新统计
                        self.stats['cancelled'] += 1
                        
                        # 清理记录
                        if video_info.forwarded_message_id in self.pending_videos:
                            del self.pending_videos[video_info.forwarded_message_id]
                        if video_info.forwarded_message_id in self.processing_tasks:
                            del self.processing_tasks[video_info.forwarded_message_id]
                        
                        # 记录已处理的消息ID
                        self.processed_messages.add(video_info.original_message_id)
                        
                        logger.info(f"视频已取消: 转发消息ID={video_info.forwarded_message_id}")
                    else:
                        logger.error(f"无法找到转发消息: {video_info.forwarded_message_id}")
                        self._handle_processing_error(video_info, "找不到转发消息")
                except Exception as e:
                    logger.error(f"发送/cancel指令时出错: {e}")
                    self._handle_processing_error(video_info, f"发送/cancel失败: {e}")
                    
        except Exception as e:
            logger.error(f"自动处理视频时出错: {e}")
            self._handle_processing_error(video_info, f"处理异常: {e}")
    
    def _handle_processing_error(self, video_info: VideoInfo, error_msg: str):
        """处理处理过程中的错误"""
        logger.error(f"处理视频失败: {error_msg}")
        
        video_info.retry_count += 1
        self.stats['retried'] += 1
        
        if video_info.retry_count < self.max_retries:
            logger.info(f"准备重试 ({video_info.retry_count}/{self.max_retries})...")
            # 重新启动处理任务
            task = asyncio.create_task(self.process_video_automatically(video_info))
            self.processing_tasks[video_info.forwarded_message_id] = task
        else:
            logger.error(f"达到最大重试次数，放弃处理: {video_info.forwarded_message_id}")
            self.stats['failed'] += 1
            
            # 清理记录
            if video_info.forwarded_message_id in self.pending_videos:
                del self.pending_videos[video_info.forwarded_message_id]
            if video_info.forwarded_message_id in self.processing_tasks:
                del self.processing_tasks[video_info.forwarded_message_id]
    
    async def process_message(self, message, is_history: bool = False):
        """处理消息（用于处理历史消息和新消息）"""
        try:
            # 检查是否为视频消息
            if not self.is_video_message(message):
                logger.debug(f"消息 {message.id} 不是视频消息，跳过")
                return
            
            # 检查是否已处理过
            if message.id in self.processed_messages:
                logger.debug(f"消息 {message.id} 已处理过，跳过")
                return
            
            logger.info(f"处理{'历史' if is_history else '新'}视频消息 ID: {message.id}")
            
            # 获取消息文本
            message_text = message.text or message.message or ""
            logger.info(f"消息内容: {message_text[:200]}...")
            
            # 转发消息到机器人
            forwarded = await message.forward_to(self.target_bot)
            
            # 保存消息信息
            video_info = VideoInfo(
                original_message_id=message.id,
                forwarded_message_id=forwarded.id,
                description=message_text
            )
            
            # 尝试提取视频信息
            title, season, episode = await self.extract_video_info(message_text)
            video_info.title = title
            video_info.season = season
            video_info.episode = episode
            
            self.pending_videos[forwarded.id] = video_info
            self.stats['total_processed'] += 1
            
            if is_history:
                self.stats['history_processed'] += 1
            else:
                self.stats['new_processed'] += 1
            
            logger.info(f"已转发消息 ID: {forwarded.id} 到机器人")
            
            # 启动自动处理任务
            task = asyncio.create_task(self.process_video_automatically(video_info))
            self.processing_tasks[forwarded.id] = task
            
            logger.info(f"已启动处理任务，当前待处理消息数: {len(self.pending_videos)}")
            
        except Exception as e:
            logger.error(f"处理消息时出错: {e}")
            self.stats['failed'] += 1
    
    async def process_existing_messages(self):
        """处理频道中已存在的消息"""
        try:
            logger.info(f"开始获取频道 {self.source_channel} 的历史消息...")
            
            # 获取频道中最近的消息
            messages = []
            async for message in self.client.iter_messages(self.source_channel, limit=self.history_limit):
                messages.append(message)
            
            logger.info(f"获取到 {len(messages)} 条历史消息")
            
            # 处理每条消息
            processed_count = 0
            for message in messages:
                # 使用和新消息相同的处理函数
                await self.process_message(message, is_history=True)
                processed_count += 1
                
                # 每处理5条消息等待1秒，避免请求过快
                if processed_count % 5 == 0:
                    await asyncio.sleep(1)
            
            logger.info(f"历史消息处理完成，共处理 {processed_count} 条消息")
            
        except Exception as e:
            logger.error(f"处理历史消息时出错: {e}")
    
    async def handle_new_message(self, event):
        """处理源频道的所有新消息"""
        try:
            message = event.message
            
            # 使用和歷史消息相同的處理函數
            await self.process_message(message, is_history=False)
            
        except Exception as e:
            logger.error(f"处理新消息时出错: {e}")
            self.stats['failed'] += 1
    
    async def cleanup_finished_tasks(self):
        """清理已完成的任务"""
        while True:
            await asyncio.sleep(60)  # 每60秒清理一次
            
            # 找出已完成的任务并清理
            finished_tasks = []
            for msg_id, task in list(self.processing_tasks.items()):
                if task.done():
                    finished_tasks.append(msg_id)
            
            for msg_id in finished_tasks:
                if msg_id in self.processing_tasks:
                    try:
                        # 获取任务结果，检查是否有异常
                        task = self.processing_tasks[msg_id]
                        if task.exception():
                            logger.error(f"任务 {msg_id} 执行出错: {task.exception()}")
                    except:
                        pass
                    finally:
                        del self.processing_tasks[msg_id]
            
            if finished_tasks:
                logger.debug(f"清理了 {len(finished_tasks)} 个已完成的任务")
    
    async def print_stats_periodically(self):
        """定期打印统计信息"""
        while True:
            await asyncio.sleep(300)  # 每5分钟打印一次
            logger.info(f"统计信息: 处理总数={self.stats['total_processed']}, "
                       f"成功={self.stats['successful']}, "
                       f"取消={self.stats['cancelled']}, "
                       f"失败={self.stats['failed']}, "
                       f"重试={self.stats['retried']}, "
                       f"历史处理={self.stats['history_processed']}, "
                       f"新消息处理={self.stats['new_processed']}, "
                       f"待处理={len(self.pending_videos)}, "
                       f"运行中任务={len(self.processing_tasks)}")
            
            # 显示当前处理状态
            status_counts = {}
            for video_info in self.pending_videos.values():
                status = video_info.status
                status_counts[status] = status_counts.get(status, 0) + 1
            
            if status_counts:
                logger.info(f"状态分布: {status_counts}")
    
    async def start(self):
        """启动客户端和事件处理"""
        try:
            await self.client.start(phone=PHONE_NUMBER)
            
            logger.info("=" * 50)
            logger.info("客户端启动成功")
            logger.info(f"监听频道: {self.source_channel}")
            logger.info(f"目标机器人: {self.target_bot}")
            logger.info(f"等待间隔: {self.wait_interval}秒")
            logger.info(f"最大重试次数: {self.max_retries}")
            logger.info(f"历史消息处理限制: {self.history_limit}条")
            logger.info("=" * 50)
            
            # 启动清理任务
            cleanup_task = asyncio.create_task(self.cleanup_finished_tasks())
            
            # 启动统计任务
            stats_task = asyncio.create_task(self.print_stats_periodically())
            
            # 先处理已存在的历史消息
            await self.process_existing_messages()
            
            # 注册事件处理器 - 监听源频道的所有新消息
            @self.client.on(events.NewMessage(chats=self.source_channel))
            async def new_message_handler(event):
                await self.handle_new_message(event)
            
            logger.info("开始监听新消息...")
            logger.info("注意: 程序将自动处理，不依赖机器人回复")
            
            # 运行直到断开连接
            await self.client.run_until_disconnected()
            
        except Exception as e:
            logger.error(f"客户端运行出错: {e}")
        finally:
            # 取消所有任务
            for task in self.processing_tasks.values():
                task.cancel()
            
            logger.info("客户端停止")
            logger.info(f"最终统计: 处理总数={self.stats['total_processed']}, "
                       f"成功={self.stats['successful']}, "
                       f"取消={self.stats['cancelled']}, "
                       f"失败={self.stats['failed']}, "
                       f"重试={self.stats['retried']}, "
                       f"历史处理={self.stats['history_processed']}, "
                       f"新消息处理={self.stats['new_processed']}")


async def main():
    # 创建转发器实例
    forwarder = TelegramAutoForwarder()
    
    # 启动
    await forwarder.start()


if __name__ == "__main__":
    asyncio.run(main())