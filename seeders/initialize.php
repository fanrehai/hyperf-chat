<?php

declare(strict_types=1);

use App\Helper\Hash;
use Hyperf\Database\Seeders\Seeder;
use App\Model\User;
use App\Model\Article\ArticleClass;
use Hyperf\DbConnection\Db;
use App\Model\UsersFriend;

class Initialize extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (User::count() > 0) {
            echo "数据库已存在数据，不能执行初始化数据脚本...\n";
            return;
        }

        $users = [];
        for ($i = 0; $i < 9; $i++) {
            $users[] = [
                'mobile'     => '1879827205' . $i,
                'password'   => Hash::make('admin123'),
                'nickname'   => 'test' . $i,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        User::insert($users);

        $defaultArticleClass = [];
        $usersFriends        = [];
        foreach (User::all() as $user) {
            $defaultArticleClass[] = [
                'user_id'    => $user->id,
                'class_name' => '我的笔记',
                'sort'       => 1,
                'is_default' => 1,
                'created_at' => time(),
            ];
        }

        ArticleClass::insert($defaultArticleClass);

        $list = Db::select('SELECT u1.id as user_id,u2.id as friend_id FROM im_users as u1,im_users as u2 where u1.id != u2.id');

        $friends = [];

        foreach ($list as $item) {
            $friends[] = [
                'user_id'    => $item->user_id,
                'friend_id'  => $item->friend_id,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        UsersFriend::insert($friends);

        $service = new \App\Service\TalkListService();
        foreach ($list as $item) {
            $service->create($item->user_id, $item->friend_id, \App\Constants\TalkModeConstant::PRIVATE_CHAT);
        }
    }
}
