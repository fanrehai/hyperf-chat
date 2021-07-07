<?php
declare(strict_types=1);
/**
 * This is my open source code, please do not use it for commercial applications.
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code
 *
 * @author Yuandong<837215079@qq.com>
 * @link   https://github.com/gzydong/hyperf-chat
 */

namespace App\Amqp\Consumer;

use App\Constants\TalkMsgType;
use App\Constants\TalkType;
use App\Model\UsersFriendsApply;
use App\Service\UserService;
use Hyperf\Amqp\Annotation\Consumer;
use Hyperf\Amqp\Result;
use Hyperf\Amqp\Message\ConsumerMessage;
use Hyperf\Amqp\Message\Type;
use Hyperf\Amqp\Builder\QueueBuilder;
use PhpAmqpLib\Message\AMQPMessage;
use App\Model\User;
use App\Model\Chat\TalkRecords;
use App\Model\Chat\TalkRecordsCode;
use App\Model\Chat\TalkRecordsFile;
use App\Model\Chat\TalkRecordsInvite;
use App\Model\Chat\TalkRecordsForward;
use App\Model\Group\Group;
use App\Service\SocketClientService;
use App\Constants\SocketConstants;
use App\Cache\Repository\LockRedis;
use App\Cache\SocketRoom;

/**
 * 消息推送消费者队列
 * @Consumer(name="ConsumerChat",enable=false)
 */
class ChatMessageConsumer extends ConsumerMessage
{
    /**
     * 交换机名称
     *
     * @var string
     */
    public $exchange = SocketConstants::CONSUMER_MESSAGE_EXCHANGE;

    /**
     * 交换机类型
     *
     * @var string
     */
    public $type = Type::FANOUT;

    /**
     * 路由key
     *
     * @var string
     */
    public $routingKey = 'consumer:im:message';

    /**
     * @var SocketClientService
     */
    private $socketClientService;

    /**
     * 消息事件与回调事件绑定
     *
     * @var array
     */
    const EVENTS = [
        // 聊天消息事件
        SocketConstants::EVENT_TALK          => 'onConsumeTalk',

        // 键盘输入事件
        SocketConstants::EVENT_KEYBOARD      => 'onConsumeKeyboard',

        // 用户在线状态事件
        SocketConstants::EVENT_ONLINE_STATUS => 'onConsumeOnlineStatus',

        // 聊天消息推送事件
        SocketConstants::EVENT_REVOKE_TALK   => 'onConsumeRevokeTalk',

        // 好友申请相关事件
        SocketConstants::EVENT_FRIEND_APPLY  => 'onConsumeFriendApply'
    ];

    /**
     * ChatMessageConsumer constructor.
     *
     * @param SocketClientService $socketClientService
     */
    public function __construct(SocketClientService $socketClientService)
    {
        $this->socketClientService = $socketClientService;

        // 动态设置 Rabbit MQ 消费队列名称
        $this->setQueue('queue:im_message:' . SERVER_RUN_ID);
    }

    /**
     * 重写创建队列生成类
     * 注释：设置自动删除队列
     *
     * @return QueueBuilder
     */
    public function getQueueBuilder(): QueueBuilder
    {
        return parent::getQueueBuilder()->setAutoDelete(true);
    }

    /**
     * 消费队列消息
     *
     * @param             $data
     * @param AMQPMessage $message
     * @return string
     */
    public function consumeMessage($data, AMQPMessage $message): string
    {
        if (isset($data['event'])) {
            // [加锁]防止消息重复消费
            $lockName = sprintf('ws-message:%s-%s', SERVER_RUN_ID, $data['uuid']);
            if (!LockRedis::getInstance()->lock($lockName, 60)) {
                return Result::ACK;
            }

            // 调用对应事件绑定的回调方法
            return $this->{self::EVENTS[$data['event']]}($data, $message);
        }

        return Result::ACK;
    }

    /**
     * 对话聊天消息
     *
     * @param array       $data 队列消息
     * @param AMQPMessage $message
     * @return string
     */
    public function onConsumeTalk(array $data, AMQPMessage $message): string
    {
        $talk_type   = $data['data']['talk_type'];
        $sender_id   = $data['data']['sender_id'];
        $receiver_id = $data['data']['receiver_id'];
        $record_id   = $data['data']['record_id'];

        $fds       = [];
        $groupInfo = null;

        if ($talk_type == TalkType::PRIVATE_CHAT) {
            $fds = array_merge(
                $this->socketClientService->findUserFds($sender_id),
                $this->socketClientService->findUserFds($receiver_id)
            );
        } else if ($talk_type == TalkType::GROUP_CHAT) {
            foreach (SocketRoom::getInstance()->getRoomMembers(strval($receiver_id)) as $uid) {
                $fds = array_merge($fds, $this->socketClientService->findUserFds(intval($uid)));
            }

            $groupInfo = Group::where('id', $receiver_id)->first(['group_name', 'avatar']);
        }

        // 客户端ID去重
        if (!$fds = array_unique($fds)) {
            return Result::ACK;
        }

        $result = TalkRecords::leftJoin('users', 'users.id', '=', 'talk_records.user_id')
            ->where('talk_records.id', $record_id)
            ->first([
                'talk_records.id',
                'talk_records.talk_type',
                'talk_records.msg_type',
                'talk_records.user_id',
                'talk_records.receiver_id',
                'talk_records.content',
                'talk_records.is_revoke',
                'talk_records.created_at',
                'users.nickname',
                'users.avatar',
            ]);

        if (!$result) return Result::ACK;

        $file = $code_block = $forward = $invite = [];

        switch ($result->msg_type) {
            case TalkMsgType::FILE_MESSAGE:
                $file = TalkRecordsFile::where('record_id', $result->id)->first([
                    'id', 'record_id', 'user_id', 'file_source', 'file_type',
                    'save_type', 'original_name', 'file_suffix', 'file_size', 'save_dir'
                ]);

                $file = $file ? $file->toArray() : [];
                $file && $file['file_url'] = get_media_url($file['save_dir']);
                break;

            case TalkMsgType::FORWARD_MESSAGE:
                $forward     = ['num' => 0, 'list' => []];
                $forwardInfo = TalkRecordsForward::where('record_id', $result->id)->first(['records_id', 'text']);
                if ($forwardInfo) {
                    $forward = [
                        'num'  => count(parse_ids($forwardInfo->records_id)),
                        'list' => json_decode($forwardInfo->text, true) ?? []
                    ];
                }
                break;

            case TalkMsgType::CODE_MESSAGE:
                $code_block = TalkRecordsCode::where('record_id', $result->id)->first(['record_id', 'code_lang', 'code']);
                $code_block = $code_block ? $code_block->toArray() : [];
                break;

            case TalkMsgType::GROUP_INVITE_MESSAGE:
                $notifyInfo = TalkRecordsInvite::where('record_id', $result->id)->first([
                    'record_id', 'type', 'operate_user_id', 'user_ids'
                ]);

                $userInfo = User::where('id', $notifyInfo->operate_user_id)->first(['nickname', 'id']);
                $invite   = [
                    'type'         => $notifyInfo->type,
                    'operate_user' => ['id' => $userInfo->id, 'nickname' => $userInfo->nickname],
                    'users'        => User::whereIn('id', parse_ids($notifyInfo->user_ids))->get(['id', 'nickname'])->toArray()
                ];

                unset($notifyInfo, $userInfo);
                break;
        }

        $notify = [
            'sender_id'   => $sender_id,
            'receiver_id' => $receiver_id,
            'talk_type'   => $talk_type,
            'data'        => $this->formatTalkMessage([
                'id'           => $result->id,
                'talk_type'    => $result->talk_type,
                'msg_type'     => $result->msg_type,
                "user_id"      => $result->user_id,
                "receiver_id"  => $result->receiver_id,
                'avatar'       => $result->avatar,
                'nickname'     => $result->nickname,
                'group_name'   => $groupInfo ? $groupInfo->group_name : '',
                'group_avatar' => $groupInfo ? $groupInfo->avatar : '',
                "created_at"   => $result->created_at,
                "content"      => $result->content,
                "file"         => $file,
                "code_block"   => $code_block,
                'forward'      => $forward,
                'invite'       => $invite
            ])
        ];

        $this->socketPushNotify($fds, json_encode([SocketConstants::EVENT_TALK, $notify]));

        return Result::ACK;
    }

    /**
     * 键盘输入事件消息
     *
     * @param array       $data 队列消息
     * @param AMQPMessage $message
     * @return string
     */
    public function onConsumeKeyboard(array $data, AMQPMessage $message): string
    {
        $fds = $this->socketClientService->findUserFds($data['data']['receiver_id']);

        $this->socketPushNotify($fds, json_encode([SocketConstants::EVENT_KEYBOARD, $data['data']]));

        return Result::ACK;
    }

    /**
     * 用户上线或下线消息
     *
     * @param array       $data 队列消息
     * @param AMQPMessage $message
     * @return string
     */
    public function onConsumeOnlineStatus(array $data, AMQPMessage $message): string
    {
        $user_id = (int)$data['data']['user_id'];
        $status  = (int)$data['data']['status'];

        $fds = [];

        $ids = container()->get(UserService::class)->getFriendIds($user_id);
        foreach ($ids as $friend_id) {
            $fds = array_merge($fds, $this->socketClientService->findUserFds(intval($friend_id)));
        }

        $this->socketPushNotify(array_unique($fds), json_encode([
            SocketConstants::EVENT_ONLINE_STATUS, [
                'user_id' => $user_id,
                'status'  => $status
            ]
        ]));

        return Result::ACK;
    }

    /**
     * 撤销聊天消息
     *
     * @param array       $data 队列消息
     * @param AMQPMessage $message
     * @return string
     */
    public function onConsumeRevokeTalk(array $data, AMQPMessage $message): string
    {
        $record = TalkRecords::where('id', $data['data']['record_id'])->first(['id', 'talk_type', 'user_id', 'receiver_id']);

        $fds = [];
        if ($record->talk_type == TalkType::PRIVATE_CHAT) {
            $fds = array_merge($fds, $this->socketClientService->findUserFds($record->user_id));
            $fds = array_merge($fds, $this->socketClientService->findUserFds($record->receiver_id));
        } else if ($record->talk_type == TalkType::GROUP_CHAT) {
            $userIds = SocketRoom::getInstance()->getRoomMembers(strval($record->receiver_id));
            foreach ($userIds as $uid) {
                $fds = array_merge($fds, $this->socketClientService->findUserFds((int)$uid));
            }
        }

        $fds = array_unique($fds);
        $this->socketPushNotify($fds, json_encode([SocketConstants::EVENT_REVOKE_TALK, [
            'talk_type'   => $record->talk_type,
            'sender_id'   => $record->user_id,
            'receiver_id' => $record->receiver_id,
            'record_id'   => $record->id,
        ]]));

        return Result::ACK;
    }

    /**
     * 好友申请消息
     *
     * @param array       $data 队列消息
     * @param AMQPMessage $message
     * @return string
     */
    public function onConsumeFriendApply(array $data, AMQPMessage $message): string
    {
        $data = $data['data'];

        $applyInfo = UsersFriendsApply::where('id', $data['apply_id'])->first();
        if (!$applyInfo) return Result::ACK;

        $fds = $this->socketClientService->findUserFds($data['type'] == 1 ? $applyInfo->friend_id : $applyInfo->user_id);

        if ($data['type'] == 1) {
            $msg = [
                'sender_id'   => $applyInfo->user_id,
                'receiver_id' => $applyInfo->friend_id,
                'remark'      => $applyInfo->remark,
            ];
        } else {
            $msg = [
                'sender_id'   => $applyInfo->friend_id,
                'receiver_id' => $applyInfo->user_id,
                'status'      => $applyInfo->status,
                'remark'      => $applyInfo->remark,
            ];
        }

        $friendInfo = User::select(['id', 'avatar', 'nickname', 'mobile', 'motto'])->find($data['type'] == 1 ? $applyInfo->user_id : $applyInfo->friend_id);

        $msg['friend'] = [
            'user_id'  => $friendInfo->id,
            'avatar'   => $friendInfo->avatar,
            'nickname' => $friendInfo->nickname,
            'mobile'   => $friendInfo->mobile,
        ];

        $this->socketPushNotify(array_unique($fds), json_encode([SocketConstants::EVENT_FRIEND_APPLY, $msg]));

        return Result::ACK;
    }

    /**
     * WebSocket 消息推送
     *
     * @param $fds
     * @param $message
     */
    private function socketPushNotify($fds, $message)
    {
        $server = server();
        foreach ($fds as $fd) {
            $server->exist(intval($fd)) && $server->push(intval($fd), $message);
        }
    }

    /**
     * 格式化对话的消息体
     *
     * @param array $data 对话的消息
     * @return array
     */
    private function formatTalkMessage(array $data): array
    {
        $message = [
            "id"           => 0, // 消息记录ID
            "talk_type"    => 1, // 消息来源[1:好友私信;2:群聊]
            "msg_type"     => 1, // 消息类型
            "user_id"      => 0, // 发送者用户ID
            "receiver_id"  => 0, // 接收者ID[好友ID或群ID]

            // 发送消息人的信息
            "nickname"     => "",// 用户昵称
            "avatar"       => "",// 用户头像
            "group_name"   => "",// 群组名称
            "group_avatar" => "",// 群组头像

            // 不同的消息类型
            "file"         => [],
            "code_block"   => [],
            "forward"      => [],
            "invite"       => [],

            // 消息创建时间
            "content"      => '',// 文本消息
            "created_at"   => "",

            // 消息属性
            "is_revoke"    => 0, // 消息是否撤销
        ];

        return array_merge($message, array_intersect_key($data, $message));
    }
}
