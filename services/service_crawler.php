<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\MySQL\Connection;
use GuzzleHttp\Client as HttpClient;
use PHPHtmlParser\Dom;

$worker = new Worker();
$worker->name = pathinfo(__FILE__, PATHINFO_FILENAME);
$worker->count = 21;
$initWorkerId = 0; // 此worker id将不参与数据处理

$worker->onWorkerStart = function(Workerman\Worker $worker) {
    global $initWorkerId;

    $workerCnt = $worker->count - 1; // 需要除去$initWorkerId子进程
    $workerId = $worker->id;
    $db = new Connection('127.0.0.1', 3306, 'root', '', 'phone');
    
    if ($workerId === $initWorkerId) {
        $initTimerId = Timer::add(5, function() use ($workerCnt, $db, $initWorkerId) {
            // 修复意外退出数据
            $compareTime = date('Y-m-d H:i:s', strtotime('-1 minute'));
            $whereSql = "`finished` = 0 AND `working` = 1 AND `update_time` < '{$compareTime}'";
            $rowCnt = $db->update('pointer')->cols(['working' => 0])->where($whereSql)->query();
            if ($rowCnt > 0) {
                echo "fixed {$rowCnt} pointers\n";
            }

            // 单进程初始化
            $unfinishedCntRow = $db->row("select count(*) as `num` from `pointer` where `finished` = 0");
            $unfinishedCnt = intval($unfinishedCntRow['num']);
            if ($unfinishedCnt < $workerCnt) {
                $needCnt = $workerCnt - $unfinishedCnt;
                $lastPointer = $db->row("select * from `pointer` order by `id` desc limit 1");
                $lastAreaId = is_array($lastPointer) && !empty($lastPointer) ? intval($lastPointer['id']) : 0;
                $initAreas = $db->query("select * from `area` where `id` > {$lastAreaId} order by `id` asc limit {$needCnt}");
                foreach ($initAreas as $initArea) {
                    $insertPointer = array(
                        'id' => $initArea['id'],
                        'prefix' => $initArea['prefix'],
                        'number' => $initArea['prefix'] . '0000',
                        'finished' => 0,
                        'update_time' => date('Y-m-d H:i:s')
                    );
                    $db->insert('pointer')->cols($insertPointer)->query();
                }
            }
            echo "init worker id {$initWorkerId} finished initialization\n";
        });
        // $initWorkerId 不参与数据处理
        echo "init worker id {$initWorkerId} will not process data, just for initialization, timer id {$initTimerId}\n";
        return true;
    }
    
    $unfinishedPointers = array();
    
    // 阻塞等待初始化
    $waitTime = 10;
    $sleepTime = 0.2;
    $waitCnt = intval($waitTime / $sleepTime);
    while ($waitCnt--) {
        $unfinishedPointers = $db->query("select * from `pointer` where `finished` = 0 order by `id` asc limit {$workerCnt}");
        if (is_array($unfinishedPointers) && !empty($unfinishedPointers)) {
            echo "worker id {$workerId} fetch unfinished pointers success\n";
            break;
        }
        if ($waitCnt === 0) {
            echo "worker id {$workerId} wait timeout, fetch unfinished pointers failed, rebooting to retry...\n";
            Worker::stopAll();
        }
        usleep($sleepTime * 1000 * 1000);
    }
    
    // 多进程处理
    $eachSleep = 0.02;
    $finishedSleep = 3;
    $http = new HttpClient([
        'base_uri' => 'https://www.baidu.com',
        'timeout' => 5,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/57.0.2987.98 Safari/537.36'
        ]
    ]);
    foreach ($unfinishedPointers as $pointer) {
        $isWorking = 1;
        $intNum = intval(substr($pointer['number'], -4));
        $numStart = $intNum === 0 ? $intNum : ($intNum + 1);
        $numEnd = 9999;
        
        // 子进程抢占任务
        try {
            $db->beginTrans();
            $checkPointer = $db->row("select * from `pointer` where `id` = {$pointer['id']} for update");
            if (intval($checkPointer['working']) === 0) {
                $isWorking = 0;
                $db->update('pointer')->cols(['working' => 1])->where("id = {$pointer['id']}")->query();
            }
            $db->commitTrans();
        } catch (Exception $e) {
            echo "check pointer is working error: {$e->getMessage()}\n";
            $db->rollBackTrans();
        }
        if ($isWorking) {
            //echo "pointer id {$pointer['id']} is working now, worker id {$workerId} find next...\n";
            continue;
        }
        
        echo "worker id {$workerId} is working on pointer id {$pointer['id']} now...\n";
        // 每个进程处理相应的号码前缀
        $fetchErrCnt = 0;
        $maxFetchErrCnt = 20;
        for ($num = $numStart; $num <= $numEnd; $num++) {
            $realNum = $pointer['prefix'] . str_pad($num, 4, '0', STR_PAD_LEFT);
            
            // 处理每个号码
            $uri = "/s?wd={$realNum}%20site%3A58.com";
            try {
                $bodyHtml = $http->get($uri)->getBody()->getContents();
                $dom = new Dom();
                $dom->load($bodyHtml);
            } catch (Exception $e) {
                echo "fetch uri '{$uri}' data error: {$e->getMessage()}\n";
                $fetchErrCnt++;
                if ($fetchErrCnt >= $maxFetchErrCnt) {
                    echo "fetch uri '{$uri}' error count {$fetchErrCnt} reached max, stop fetching now...\n";
                    break;
                } else {
                    continue;
                }
            }
            
            $ele = $dom->find('.c-container[id=1]', 0);
            if ($ele instanceof \PHPHtmlParser\Dom\AbstractNode) {
                $hasError = false;
                try {
                    $eleLink = $ele->find('.t a');
                    $title = $eleLink->text();
                    $link = $eleLink->getAttribute('href');
                    $htmlBrief = $ele->find('.c-abstract')->innerHtml();
                } catch (Exception $e) {
                    $hasError = true;
                    echo "process number {$realNum} failed, error msg: \n";
                    echo $e->getMessage() . "\n";
                }
                if (!$hasError) {
                    $insertWechat = array(
                        'phone_number' => $realNum,
                        'title' => $title,
                        'link' => $link,
                        'html_brief' => $htmlBrief,
                        'create_time' => date('Y-m-d H:i:s')
                    );
                    try {
                        $db->beginTrans();
                        $db->insert('wechat')->cols($insertWechat)->query();
                        $db->commitTrans();
                    } catch (Exception $e) {
                        echo "worker id {$workerId} insert wechat error: {$e->getMessage()}\n";
                        $db->rollBackTrans();
                    }
                }
            }
            // 完成处理
            
            $finished = 0;
            $working = 1;
            if ($num === $numEnd) {
                $finished = 1;
                $working = 0;
            }
            $updatePointer = array(
                'number' => $realNum,
                'finished' => $finished,
                'update_time' => date('Y-m-d H:i:s'),
                'working' => $working
            );
            $db->update('pointer')->cols($updatePointer)->where("`id` = {$pointer['id']}")->query();
            usleep($eachSleep * 1000 * 1000);
        }
        // 每个号码段处理完成后重启相关子进程
        echo "worker id {$workerId} finished pointer id {$pointer['id']}, rebooting for new coming...\n";
        break;
    }
    echo "worker id {$workerId} rebooting now...\n";
    usleep($finishedSleep * 1000 * 1000);
    Worker::stopAll();
};

$worker->onWorkerStop = function(Workerman\Worker $worker) {
    echo "Worker has stopped\n";
};

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}