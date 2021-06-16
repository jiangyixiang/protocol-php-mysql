<?php
/**
 * 背锅人          gushi Jiang
 * create_date    2020/10/26
 * create_time    9:11 上午
 */

include_once './tools.php';
$conf = include_once './conf_local.php';
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 'On');
$host = $conf['mysql']['HOST'];
$port = $conf['mysql']['PORT'];
$user = $conf['mysql']['USER'];
$pass = $conf['mysql']['PASS'];
$database = $conf['mysql']['DATABASE'];

//只实现了查询语句(建议使用少量数据 多会内存溢出 暂时没有优化)
$sql = 'select * from act_unit limit 1';

//mysql原版
function mysql() {
    global $host;
    global $user;
    global $pass;
    global $database;
    global $sql;
    $conn = mysqli_connect($host, $user, $pass, $database);
    dump("连接信息:");
    dump($conn);
    mysqli_select_db($conn, $database);
    $row_obj = mysqli_query($conn, $sql);
    dump("数据:");
    $rows = [];
    while ($row = $row_obj->fetch_assoc()) {
        $rows[] = $row;
    }
    dump($rows);
}
//mysql();exit();

//手写mysql连接 （注意：MySQL报文中整型值分别有1、2、3、4、8字节长度，使用【小端字节序】传输！！！！）
function mysqlHand() {
    global $host;
    global $sql;
    global $port;

    //创建tcp套接字
    //第一个参数表示使用的地址类型，一般都是ipv4，AF_INET
    //第二个参数表示套接字类型：tcp：面向连接的稳定数据传输SOCK_STREAM
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    //连接tcp
    socket_connect($socket, $host, $port);
    /*另一种写法连接tcp并操作
    $remote = sprintf('%s://%s:%s', 'tcp', '127.0.0.1', '3306');
    $socket = stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
    $buffer = fread($socket, 8192);
    fwrite($socket, mb_strlen($buffer,'ASCII'));
    fclose($socket);*/

    //读取握手消息
    $msg = socket_read($socket, 8192, PHP_BINARY_READ);
    $greeting_arr = decodeGreeting($msg);
    dump($greeting_arr);

    //发送登录认证
    $push_msg = formatLogin($greeting_arr);
    socket_write($socket, $push_msg, mb_strlen($push_msg, 'ASCII'));

    //读取登录结果
    $msg = socket_read($socket, 8192, PHP_BINARY_READ);
    //todo:校验是否正确登录
    //dump($msg);

    //发送命令查询等
    $push_msg = formatCommand(3, $sql);
    socket_write($socket, $push_msg, mb_strlen($push_msg, 'ASCII'));

    //读取返回的数据
    $msg = socket_read($socket, 8192, PHP_BINARY_READ);
    dump($msg);
    $msg = decodeCommand($msg);
    dump($msg);

    //关闭连接
    socket_close($socket);
}

mysqlHand();
exit();


/**
 * 解析握手信息
 * @param $stream
 * @return mixed
 */
function decodeGreeting($stream) {
    //消息体长度 3Byte
    //包的序号 1Byte (用于保证消息顺序的正确，每次客户端发起请求时，序号值都会从0开始计算)
    list($arr['package_length'], $arr['package_number'], $next_stream) = readPackageInfo($stream);

    //-- 消息体 ---
    //版本号 1Byte
    list($arr['protocol'], $next_stream) = readByLength($next_stream, 1);
    $arr['protocol'] = _little2dec($arr['protocol']);
    //服务器版本信息 nByte
    list($arr['version'], $next_stream) = readUntilNull($next_stream);
    //服务器线程ID 4Byte
    list($arr['thread_id'], $next_stream) = readByLength($next_stream, 4);
    $arr['thread_id'] = _little2dec($arr['thread_id']);
    //随机字串1 8Byte
    list($arr['salt1'], $next_stream) = readByLength($next_stream, 8);
    $arr['salt1'] = bin2hex($arr['salt1']);
    //填充值\x00 1Byte
    list($arr['fill'], $next_stream) = readByLength($next_stream, 1);
    $arr['fill'] = bin2hex($arr['fill']);
    //服务器全能标志(整形) 2B
    list($arr['server_capability'], $next_stream) = readByLength($next_stream, 2);
    $arr['server_capability'] = _little2hex($arr['server_capability']);
    $arr['server_capability'] = base_convert($arr['server_capability'], 16, 2);
    //字符编码 1B  mysql表的COLLATIONS中有全部的字符集ID
    list($arr['charset'], $next_stream) = readByLength($next_stream, 1);
    $arr['charset'] = bin2hex($arr['charset']);
    //服务器状态(整形) 2B
    list($arr['status'], $next_stream) = readByLength($next_stream, 2);
    $arr['status'] = _little2hex($arr['status']);
    $arr['status'] = base_convert($arr['status'], 16, 2);
    //服务器全能标志(高16位) 2B
    list($arr['server_capability_ext'], $next_stream) = readByLength($next_stream, 2);
    $arr['server_capability_ext'] = _little2hex($arr['server_capability_ext']);
    $arr['server_capability_ext'] = base_convert($arr['server_capability_ext'], 16, 2);
    //length of the combined auth_plugin_data, if auth_plugin_data_len is > 0  1B
    list($arr['auth_plugin_data_len'], $next_stream) = readByLength($next_stream, 1);
    $arr['auth_plugin_data_len'] = _little2dec($arr['auth_plugin_data_len']);
    //10B \x00填充值
    list($arr['fill2'], $next_stream) = readByLength($next_stream, 10);
    $arr['fill2'] = bin2hex($arr['fill2']);
    //随机字串2
    list($arr['salt2'], $next_stream) = readUntilNull($next_stream);
    $arr['salt2'] = bin2hex($arr['salt2']);
    //结尾
    list($arr['client_auth_plugin'],) = readUntilNull($next_stream);
    $arr['client_auth_plugin'] = bin2hex($arr['client_auth_plugin']);

    return $arr;
}

/**
 * 拼装登录请求信息
 * @param $greeting
 * @return string
 */
function formatLogin($greeting) {
    global $user;
    global $pass;
    global $database;
    $stream = '';
    //客户端配置项 类似bitMap 具体每个值的权限可以参考mysql的文档
    $body['capability'] = bin2hex(pack('v', 0b1010001010001101));
    $body['capability_ext'] = bin2hex(pack('v', 0b0000000000001010));
    //todo:没找到文档里的描述
    $body['message_max_length'] = '000000c0';
    $body['charset'] = $greeting['charset'];
    $body['unused'] = str_repeat('00', 23);
    //00分隔符
    $body['user_name'] = bin2hex($user) . '00';
    //\x14表示后面有20位的密码 如果不传database的话要再结尾加上00表结束
    $body['passwd'] = '14' . _generate_pass($pass, $greeting['salt1'] . $greeting['salt2']);
    $body['database'] = bin2hex($database) . '00';
    $body['client_auth_plugin'] = $greeting['client_auth_plugin'] . '00';
    //15 0c _client_name 07 mysqlnd
    $body['connection_attributes'] = '150c' . bin2hex('_client_name') . '07' . bin2hex('mysqlnd');

    foreach ($body as $v) {
        $stream .= $v;
    }
    //消息体封包
    $stream = _packageBody($stream, 1);
    return _hex2ascii($stream);
}

/**
 * 拼装查询信息
 * @param int $type 记得补全
 * @param $sql
 * @return string
 */
function formatCommand(int $type, $sql) {
    $stream = '';
    $body['command'] = dechex($type);
    $body['command'] = (strlen($body['command']) > 1) ? $body['command'] : "0{$body['command']}";
    $body['statement'] = bin2hex($sql);

    foreach ($body as $v) {
        $stream .= $v;
    }
    //消息体封包
    $stream = _packageBody($stream, 0);
    return _hex2ascii($stream);
}

/**
 * 解析查询信息
 * @param $stream
 * @return array
 */
function decodeCommand($stream) {
    $arr = [];
    //第一个包 结果集列数
    list($arr['column']['package_length'],
        $arr['column']['package_number'],
        $next_stream) = readPackageInfo($stream);
    list($arr['column']['num'], $next_stream) = readByLength($next_stream, $arr['column']['package_length']);
    $arr['column']['num'] = _little2dec($arr['column']['num']);

    //介绍字段的N个包 
    for ($i = 0; $i < $arr['column']['num']; $i++) {
        //4字节包头信息
        list($arr['fields'][$i]['package_length'],
            $arr['fields'][$i]['package_number'],
            $next_stream) = readPackageInfo($next_stream);
        //内容
        list($arr['fields'][$i]['catalog'], $next_stream) = readByFirstLength($next_stream);
        list($arr['fields'][$i]['database'], $next_stream) = readByFirstLength($next_stream);
        list($arr['fields'][$i]['table'], $next_stream) = readByFirstLength($next_stream);
        list($arr['fields'][$i]['original_table'], $next_stream) = readByFirstLength($next_stream);
        list($arr['fields'][$i]['name'], $next_stream) = readByFirstLength($next_stream);
        list($arr['fields'][$i]['original_name'], $next_stream) = readByFirstLength($next_stream);
        //填充值 1B \x0c (应该是本包后面的整体长度)
        //字符编码 2B
        //字段长度 4B
        //字段类型 1B
        //字段标志 2B
        //整型值精度 1B
        //填充值（0x00）2B
        list($arr['fields'][$i]['other_data'], $next_stream) = readByFirstLength($next_stream);
    }

    //EOF 结构 (EOF结构用于标识Field和Row Data的结束，在预处理语句中，EOF也被用来标识参数的结束。)
    $next_stream = mb_substr($next_stream, isEOFPackage($next_stream), null, 'ASCII');

    //数据N个包
    $row_num = 0;
    while (!isEOFPackage($next_stream)) {
        list(, , $next_stream) = readPackageInfo($next_stream);
        //stream举例
        //长度 值1 长度 值2 长度 值3....
        for ($i = 0; $i < $arr['column']['num']; $i++) {
            //$arr['row']['第几条数据']['字段名'] = 值
            list($arr['row'][$row_num][$arr['fields'][$i]['name']], $next_stream) = readByFirstLength($next_stream);
        }
        $row_num++;
    }
    //结尾也是EOF结构 省略...

    return $arr;
}


/*----------  格式化发送的数据  ----------*/
/**
 * 给消息体封装包头信息 (body数据长度数据(小端序3字节).第几个包(小端序1字节).消息体)
 * @param $hex_body
 * @param int $package_number
 * @return string
 */
function _packageBody($hex_body, $package_number = 0) {
    $byte_len = mb_strlen($hex_body, 'ASCII') / 2;
    //转换成24bit 小端字节序
    $package_length = substr(bin2hex(pack('V', $byte_len)), 0, 6);
    //转换成8bit 小端字节序
    $package_number = bin2hex(pack('h', $package_number));
    return $package_length . $package_number . $hex_body;
}

/**
 * 生成密码
 * https://dev.mysql.com/doc/internals/en/secure-password-authentication.html
 * SHA1( password ) XOR SHA1( "20-bytes random data from server" <concat> SHA1( SHA1( password ) ) )
 * @param $pass
 * @param $salt
 * @return int
 */
function _generate_pass($pass, $salt) {
    $part1 = sha1($pass, true);
    $part2 = sha1(_hex2ascii($salt) . sha1(sha1($pass, true), true), true);
    return bin2hex($part1 ^ $part2);
}


/*----------  读取数据  ----------*/
/**
 * 读取数据 检查包信息
 * @param $stream
 * @return array [包体长度(消息体的长度), 第几个包, 剩余的流文件]
 */
function readPackageInfo($stream) {
    $package_length = _little2dec(mb_substr($stream, 0, 3, 'ASCII'));
    $package_number = _little2dec(mb_substr($stream, 3, 1, 'ASCII'));
    return [$package_length, $package_number, mb_substr($stream, 4, null, 'ASCII')];
}

/**
 * 读取数据 固定长度
 * @param $bin
 * @param int $len
 * @return array [读出的流文件内容,剩余的流文件]
 */
function readByLength($bin, int $len) {
    return [mb_substr($bin, 0, $len, 'ASCII'), mb_substr($bin, $len, null, 'ASCII')];
}

/**
 * 读取数据 第一个字节是数据长度 第二个字节开始是数据
 * @param $bin
 * @return array [读出的流文件内容, 剩余的流文件]
 */
function readByFirstLength($bin) {
    $len = _little2dec(mb_substr($bin, 0, 1, 'ASCII'));
    return [mb_substr($bin, 1, $len, 'ASCII'), mb_substr($bin, $len + 1, null, 'ASCII')];
}

/**
 * 读取数据直到\x00(null)为止
 * @param $bin
 * @return array [读出的流文件内容, 剩余的流文件]
 */
function readUntilNull($bin) {
    $max_len = mb_strlen($bin, 'ASCII');
    for ($i = 0; $i < $max_len; $i++) {
        if (mb_substr($bin, $i, 1, 'ASCII') == "\x00") {
            break;
        }
    }
    return [mb_substr($bin, 0, $i, 'ASCII'), mb_substr($bin, $i + 1, null, 'ASCII')];
}

/**
 * 检查是否是EOF包 4字节包头 + 消息体5字节[EOF值\xfe(1字节) + 告警计数warming(2字节) + 状态标志位(2字节)]
 * @param $stream
 * @return int 返回EOF包的长度
 */
function isEOFPackage($stream) {
    list($len,) = readPackageInfo($stream);
    if (mb_substr($stream, 4, 1, 'ASCII') == "\xfe" && $len == 5) {
        return 4 + 5;
    } else {
        return 0;
    }
}


//16进制转换成ascii  反过来是bin2hex
function _hex2ascii($hex_str) {
    $send_msg = "";
    foreach (str_split($hex_str, 2) as $v) {
        $send_msg .= chr(hexdec($v));
    }
    return $send_msg;
}

//小端序 bin 转 十进制数据
function _little2dec($bin) {
    $hex = bin2hex($bin);
    $hex = implode("", array_reverse(str_split($hex, 2)));
    return hexdec($hex);
}

function _little2hex($bin) {
    $hex = bin2hex($bin);
    $hex = implode("", array_reverse(str_split($hex, 2)));
    return $hex;
}

//大端序 bin 转 十进制数据
function _big2dec($bin) {
    return hexdec(bin2hex($bin));
}


