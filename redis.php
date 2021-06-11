<?php
/**
 * 背锅人          gushi Jiang
 * create_date    2020/10/26
 * create_time    9:11 上午
 */
//ini_set('error_reporting',E_ALL);
//ini_set('display_errors','On');

include_once './tools.php';
$conf = include_once './conf_local.php';
$host = $conf['redis']['HOST'];
$port = $conf['redis']['PORT'];

function redis() {
    global $host;
    global $port;
    $r = new \Redis();
    $r->connect($host, $port);
    $r->select(2);
    dump($r->set('a', 33));
    dump($r->get('a'));
}

//redis2();exit();


//手写redis
function redis_hand() {
    global $host;
    global $port;
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_connect($socket, $host, $port);

    //执行操作
    echo "————————auth认证————————\r\n";
    $cmd = "*2\r\n$4\r\nauth\r\n$6\r\n123456\r\n"; //登录auth 123456
    socket_write($socket, $cmd, strlen($cmd));
    $ret = socket_read($socket, 4096);
    echo $ret;

    echo "————————选择仓库————————\r\n";
    $cmd = "*2\r\n$6\r\nselect\r\n$1\r\n3\r\n"; //select 3
    socket_write($socket, $cmd, strlen($cmd));
    $ret = socket_read($socket, 4096);
    echo $ret;

    echo "————————设置值————————\r\n";
    $cmd = "*3\r\n$3\r\nset\r\n$1\r\na\r\n$2\r\n77\r\n"; //set a 77
    socket_write($socket, $cmd, strlen($cmd));
    $ret = socket_read($socket, 4096);
    echo $ret;

    echo "————————获取值————————\r\n";
    $cmd = "*2\r\n$3\r\nget\r\n$1\r\na\r\n"; //get a
    socket_write($socket, $cmd, strlen($cmd));
    $ret = socket_read($socket, 4096);
    echo $ret;

    socket_close($socket);
}

redis_hand();
exit();