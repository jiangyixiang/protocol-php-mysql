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
$host = $conf['rabbit']['HOST'];
$port = $conf['rabbit']['PORT'];

//手写rabbit
function rabbit_hand() {
    global $host;
    global $port;

    //tcp_nodelay was added in 7.1.0 设置允许小包发送 不开启就是Nagle数据只有在写缓存中累积到一定量之后或40ms后才会被发送出去
    $context = stream_context_create();
    stream_context_set_option($context, 'socket', 'tcp_nodelay', true);
    //ssl://127.0.0.1:8080
    $remote = sprintf('%s://%s:%s', 'tcp', $host, $port);
    $sock = stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

    //设置阻塞模式 true阻塞 false不阻塞  阻塞模式下fread，fget将会一直等到从资源流里面获取到数据才能返回。
    stream_set_blocking($sock, true);


    /**
     * [第一部分 start 10,10 ] -------------------------------
     */

    // OpenSSL's C library function SSL_write() can balk on buffers > 8192
    // bytes in length, so we're limiting the write size here. On both TLS
    // and plaintext connections, the write loop will continue until the
    // buffer has been fully written.
    $buffer = mb_substr("AMQP\x00\x00\x09\x01", 0, 8192, 'ASCII');
    //var_dump($buffer);
    $result = fwrite($sock, $buffer);
    var_dump("写入:" . $buffer);
    $buffer = fread($sock, 8192);
    var_dump('读到的数据:' . $buffer);

    //所有帧都以７个字节的头开始，
    //其中包括一个type字段(0-1),octet  Type=1,"METHOD"方法帧  Type=2,"HEADER"内容头帧  Type=3,"BODY"内容体帧  Type=4,"HEARTBEAT"心跳帧
    //一个channel字段(1-3),short
    //一个size字段(3-7),long
    list(, $type) = unpack('C', mb_substr($buffer, 0, 1, 'ASCII'));
    list(, $channel) = unpack('n', mb_substr($buffer, 1, 2, 'ASCII'));
    list(, $size) = unpack('N', mb_substr($buffer, 3, 4, 'ASCII'));

    //按size读取payload
    $payload = mb_substr($buffer, 7, $size, 'ASCII');

    //读取结尾数据 $frame_end == 0xce 即206为正确
    list(, $frame_end) = unpack('C', mb_substr($buffer, 7 + $size, 1, 'ASCII'));
    var_dump("Type:$type", "Channel:$channel", "Size:$size", "Payload:$payload", "Frame_end:$frame_end");

    //方法帧 0-2是class-id(short)  2-4是method-id(short) 剩余的是其他参数
    $method_sig_array = unpack('n2', mb_substr($payload, 0, 4, 'ASCII'));
    $args = mb_substr($payload, 4, mb_strlen($payload, 'ASCII') - 4, 'ASCII');
    var_dump($method_sig_array, $args);

    /**
     * [第一部分 start_ok 10,11 ] -------------------------------
     */
//    //frame-head
//    $pkt->write_octet(1);
//    $pkt->write_short($channel);
//    $pkt->write_long(mb_strlen($args, 'ASCII') + 4); // 4 = length of class_id and method_id
//
//    //payload
//    $pkt->write_short($method_sig[0]); // class_id
//    $pkt->write_short($method_sig[1]); // method_id
//    //具体内容 https://www.rabbitmq.com/resources/specs/amqp-xml-doc0-9-1.pdf
//    $pkt->write($args);
//
//    //frame-end
//    $pkt->write_octet(0xCE);


    /**
     * [第二部分 secure 10,20 ] -------------------------------
     */

    /**
     * [第二部分 secure_ok 10,21 ] -------------------------------
     */

    /**
     * [第三部分 tune 10,30 ] -------------------------------
     */

    /**
     * [第三部分 tune_ok 10,31 ] -------------------------------
     */

    /**
     * [第四部分 open 10,40 ] -------------------------------
     */

    /**
     * [第四部分 open_ok 10,41 ] -------------------------------
     */

    /**
     * [第六部分 close 10,50 ] -------------------------------
     */

    /**
     * [第六部分 close_ok 10,51 ] -------------------------------
     */


    //关闭
    fclose($sock);
}

rabbit_hand();
exit();